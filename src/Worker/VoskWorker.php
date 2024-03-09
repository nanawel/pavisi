<?php

namespace App\Worker;

use Amp\Cancellation;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\Worker;
use App\Exception\VoskServerUnavailableException;
use App\Model\Vosk\Worker\Config;
use App\Task\VideoSpeechToText;
use Psr\Log\LoggerInterface;

class VoskWorker implements Worker
{
    protected \Amp\Parallel\Worker\Worker $worker;

    public function __construct(
        protected readonly LoggerInterface $logger,
        public readonly Config $config,
        ?Worker $worker = null
    ) {
        $this->worker = $worker ?? \Amp\Parallel\Worker\createWorker();
    }

    public function __destruct()
    {
        $this->logger->debug("Worker {$this->config->id} has ended");
    }

    public function isRunning(): bool {
        return $this->worker->isRunning();
    }

    public function isIdle(): bool {
        return $this->worker->isIdle();
    }

    public function submit(Task $task, ?Cancellation $cancellation = null): Execution {
        if ($task instanceof VideoSpeechToText) {
            $task->setup(new VideoSpeechToText\Config(
                $this->logger,
                $this->config->id,
                $this->config->websocketUrl,
                $this->config->websocketTcpTimeout,
                $this->config->websocketTlsTimeout,
                $this->config->websocketRetry,
                $this->config->ffmpegNice,
            ));
        }
        return $this->worker->submit($task, $cancellation);
    }

    public function shutdown(): void {
        $this->worker->shutdown();
    }

    public function kill(): void {
        $this->worker->kill();
    }
}
