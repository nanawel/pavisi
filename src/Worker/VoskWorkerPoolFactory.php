<?php

namespace App\Worker;

use Amp\Cancellation;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerPool;
use App\Exception\VoskServerUnavailableException;
use App\Model\Vosk\Worker\Config;
use App\Task\VideoSpeechToText;
use Psr\Log\LoggerInterface;

class VoskWorkerPoolFactory
{
    protected array $workerConfigStack = [];

    public function __construct(
        protected LoggerInterface $workerPoolLogger,
        protected array $voskInstancesConfig
    ) {
        foreach ($this->voskInstancesConfig as $voskServerName => $workerConfig) {
            for ($i = 1; $i <= ($workerConfig['workers'] ?? 1); $i++) {
                $workerId = "{$voskServerName}-$i";
                $this->workerConfigStack[$workerId] = new Config(
                    $workerId,
                    $workerConfig['websocket_url'],
                    $workerConfig['websocket_tcp_timeout'] ?? null,
                    $workerConfig['websocket_tls_timeout'] ?? null,
                    $workerConfig['websocket_retry'] ?? null,
                    $workerConfig['ffmpeg_nice'] ?? null,
                );
            }
        }
    }

    public function create(): VoskWorkerPool {
        return new VoskWorkerPool($this->workerPoolLogger, $this->workerConfigStack);
    }
}
