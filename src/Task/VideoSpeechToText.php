<?php

namespace App\Task;

use Amp\ByteStream;
use Amp\Cancellation;
use Amp\Http\Client\Connection\DefaultConnectionFactory;
use Amp\Http\Client\Connection\UnlimitedConnectionPool;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Parallel\Worker\Task;
use Amp\Process\Process;
use Amp\Socket\ConnectContext;
use Amp\Sync\Channel;
use Amp\Websocket\Client\Rfc6455ConnectionFactory;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketHandshake;
use App\Constants;
use App\Exception\VoskServerStreamException;
use App\Exception\VoskServerUnavailableException;
use App\Task\VideoSpeechToText\Config;
use App\Task\VideoSpeechToText\Result;
use function Amp\async;
use function App\humanSize;
use function App\humanTime;

class VideoSpeechToText implements Task
{
    public const FFMPEG_NICE_DEFAULT = 3;

    protected \Psr\Log\LoggerInterface $logger;

    protected ?Config $config = null;

    public function __construct(
        \Psr\Log\LoggerInterface $taskLogger,
        protected readonly string $filePath,
        protected int $dryRunMode = Constants::DRY_RUN_MODE_DISABLED
    ) {
        $this->logger = $taskLogger;
    }

    public function __destruct()
    {
        if ($this->config) {
            $this->logger->debug("End of task on worker {$this->config->workerId}");
        }
    }

    public function setup(Config $config): void {
        $this->config = $config;
    }

    public function run(Channel $channel, Cancellation $cancellation): mixed {
        if (!$this->config) {
            throw new \Exception('Task has not been set up.');
        }

        $workerId = $this->config->workerId;
        $ffmpegNice = $this->config->ffmpegNice
            && $this->config->ffmpegNice >= -20
            && $this->config->ffmpegNice <= 19
            ? $this->config->ffmpegNice
            : self::FFMPEG_NICE_DEFAULT;

        $this->logger->info("[$workerId] Running VSTT on file: {$this->filePath}");
        $this->logger->debug(
            "[$workerId] Memory usage: " . memory_get_usage(true) . ' bytes ('
            . humanSize(memory_get_usage(true)) . ')'
        );
        try {
            if ($this->dryRunMode === Constants::DRY_RUN_MODE_SUCCESS) {
                $this->logger->info("[$workerId] DRY-RUN enabled, returning fake SUCCESS result.");
                return new Result(
                    $workerId,
                    $this->filePath,
                    new \DateTime(),
                    ['result' => [], '_note' => 'DRY-RUN - FAKE RESULT']
                );
            }
            if ($this->dryRunMode === Constants::DRY_RUN_MODE_IMMEDIATE_FAILURE) {
                $this->logger->info("[$workerId] DRY-RUN enabled, returning fake FAILURE result.");
                throw new VoskServerUnavailableException("Dry-run fake failure");
            }

            $websocket = $this->websocketConnect();
            $startTime = microtime(true);

            // "-hide_banner -loglevel quiet" are *critical* otherwise the STDERR pipe
            // gets filled up and then the process gets stuck silently
            $command = sprintf(
                '%sffmpeg -hide_banner -loglevel quiet -i %s -f wav -ac 1 -c:a pcm_s16le -ar 16000 pipe:1',
                $ffmpegNice ? "nice -n$ffmpegNice " : '',
                escapeshellarg($this->filePath)
            );
            $this->logger->debug("[$workerId] $command");
            $process = Process::start($command);

            // Make sure to flush STDERR regularly anyway, just in case
            async(
                ByteStream\pipe(...),
                $process->getStderr(),
                new ByteStream\WritableResourceStream(\fopen('/dev/null', 'wb'))
            )->ignore();

            try {
                $voskResult = [];
                while (($chunk = $process->getStdout()->read($cancellation)) !== null) {
                    //$this->logger->debug(sprintf(
                    //    "[$workerId] New audio chunk read from ffmpeg: %d bytes",
                    //    strlen($chunk)
                    //));

                    // Send audio
                    $websocket->sendBinary($chunk);
                    //$this->logger->debug("[$workerId] Audio chunk sent to remote vosk server.");
                    unset($chunk);

                    // Receive text
                    $wsMessage = $websocket->receive($cancellation);
                    if ($wsMessage) {
                        $json = json_decode($frame = $wsMessage->read(), JSON_OBJECT_AS_ARRAY);
                        //$this->logger->debug(sprintf("[$workerId] 🗣️ %s", str_replace("\n", ' ', $frame)));
                        if (!empty($json['text'])) {
                            $this->logger->debug("[$workerId] 🗣️ {$json['text']}");
                            $voskResult[] = $json;
                        }
                    }
                    //$this->logger->debug("[$workerId] End of loop for current audio chunk. Waiting for the next one.");
                }
            } catch (ByteStream\StreamException $e) {
                $this->logger->error(
                    "[$workerId] Communication error with {$this->config->websocketUrl}: {$e->getMessage()}"
                );
                throw new VoskServerStreamException(
                    "Communication error with {$this->config->websocketUrl}",
                    previous: $e
                );
            } catch (\Throwable $e) {
                $this->logger->error("[$workerId] VSTT failed for {$this->filePath}! ({$e->getMessage()})");
                $this->logger->error($e);
                throw $e;
            }

            $this->logger->debug("[$workerId] VSTT completed for {$this->filePath}.");
            $this->logger->debug("[$workerId] Time spent: " . humanTime(microtime(true) - $startTime));

            $websocket->sendText('{"eof": 1}');
            $exitCode = $process->join();
            if ($exitCode === 0) {
                $this->logger->debug("[$workerId] ffmpeg exited successfully.");
            } else {
                throw new \RuntimeException("[$workerId] ffmpeg returned error code $exitCode");
            }

            $this->logger->info("[$workerId] Returning result for {$this->filePath}");
            $this->logger->debug(
                "[$workerId] Memory usage: " . memory_get_usage(true) . ' bytes ('
                . humanSize(memory_get_usage(true)) . ')'
            );

            return new Result(
                $workerId,
                $this->filePath,
                new \DateTime(),
                $voskResult
            );
        }
        catch (\Throwable $e) {
            $this->logger->error(static::class . " task has crashed! {$e->getMessage()}");
            $this->logger->error($e);
            throw $e;
        } finally {
            if (isset($websocket)) {
                $websocket->close();
            }
        }
    }

    protected function websocketConnect(): WebsocketConnection {
        $workerId = $this->config->workerId;
        $wsUrl = $this->config->websocketUrl;
        $wsHeaders = $this->config->websocketHeaders ?? [];
        $wsTcpTimeout = $this->config->websocketTcpTimeout ?? Constants::WEBSOCKET_TCP_CONNECT_TIMEOUT;
        $wsTlsTimeout = $this->config->websocketTlsTimeout ?? Constants::WEBSOCKET_TLS_CONNECT_TIMEOUT;
        $wsRetry = $this->config->websocketRetry ?? Constants::WEBSOCKET_CONNECT_RETRY;

        $this->logger->info("[$workerId] Initiating connection to $wsUrl");
        try {
            $handshake = (new WebsocketHandshake($wsUrl, $wsHeaders))
                ->withTcpConnectTimeout($wsTcpTimeout)
                ->withTlsHandshakeTimeout($wsTlsTimeout);

            $connector = new Rfc6455Connector(
                new Rfc6455ConnectionFactory(),
                $this->getHttpClientBuilder()
                    ->retry($wsRetry) // Fail early when host is down
                    ->build(),
            );

            $websocket = \Amp\Websocket\Client\websocketConnector($connector)
                ->connect($handshake);
        } catch (\Throwable $e) {
            $this->logger->error("[$workerId] Failed connecting to $wsUrl: {$e->getMessage()}");
            throw new VoskServerUnavailableException("Failed connecting to $wsUrl.", previous: $e);
        }
        $this->logger->info("[$workerId] Connection to $wsUrl established successfully.");

        return $websocket;
    }

    protected function getHttpClientBuilder(): HttpClientBuilder {
        /** @see \Amp\Websocket\Client\Rfc6455Connector::__construct() */
        return (new HttpClientBuilder)->usingPool(
            new UnlimitedConnectionPool(
                new DefaultConnectionFactory(connectContext: (new ConnectContext)->withTcpNoDelay())
            )
        );
    }
}
