<?php

namespace App\Worker;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use Amp\Parallel\Context\StatusError;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerException;
use Amp\Parallel\Worker\WorkerPool;
use App\Exception\NoAvailableWorkerException;
use App\Exception\VoskServerUnavailableException;
use App\Model\Vosk\Worker\Config;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use function Amp\async;

/**
 * @see \Amp\Parallel\Worker\ContextWorkerPool
 */
class VoskWorkerPool implements WorkerPool
{
    use ForbidCloning;
    use ForbidSerialization;

    protected LoggerInterface $logger;

    /** @var \SplObjectStorage<VoskWorker, int> A collection of all workers in the pool. */
    private readonly \SplObjectStorage $workers;

    /** @var \SplQueue<VoskWorker> A collection of idle workers. */
    private readonly \SplQueue $idleWorkers;

    /** @var \SplQueue<DeferredFuture<VoskWorker|null>> Task submissions awaiting an available worker. */
    private readonly \SplQueue $waiting;

    /** @var \Closure(VoskWorker):void */
    private readonly \Closure $push;

    private ?Future $exitStatus = null;

    protected readonly DeferredCancellation $deferredCancellation;

    /**
     * @param LoggerInterface $logger
     * @param Config[] $workerConfigStack
     */
    public function __construct(
        LoggerInterface $workerPoolLogger,
        protected array $workerConfigStack
    ) {
        $this->logger = $workerPoolLogger;
        $this->workers = new \SplObjectStorage();
        $this->idleWorkers = $idleWorkers = new \SplQueue();
        $this->waiting = $waiting = new \SplQueue();
        $this->deferredCancellation = new DeferredCancellation();

        foreach ($this->workerConfigStack as $workerId => $workerConfig) {
            $worker = new VoskWorker($this->logger, $workerConfig);
            $this->workers->attach($worker, $workerId);
            $this->idleWorkers->enqueue($worker);
            $this->logger->info("New worker registered successfully: {$worker->config->id}");
        }

        $this->push = function (VoskWorker $worker) use ($waiting, $idleWorkers): void {
            if (!$worker->isRunning()) {
                $this->logger->debug(
                    "Ignoring push of worker {$worker->config->id} back into the pool (not running)"
                );
                return;
            }
            if ($waiting->isEmpty()) {
                $idleWorkers->push($worker);
            } else {
                $waiting->dequeue()->complete($worker);
            }
        };
    }

    public function __destruct()
    {
        if ($this->isRunning()) {
            $this->deferredCancellation->cancel();
        }
        self::killWorkers($this->workers, $this->waiting);
        $this->logger->debug("Worker pool has shut down");
    }

    /**
     * Gets the maximum number of workers the pool may spawn to handle concurrent tasks.
     *
     * @return int The maximum number of workers.
     */
    public function getLimit(): int
    {
        return count($this->workers);
    }

    /**
     * Checks if the pool is running.
     *
     * @return bool True if the pool is running, otherwise false.
     */
    public function isRunning(): bool {
        return !$this->deferredCancellation->isCancelled();
    }

    /**
     * Checks if the pool has any idle workers.
     *
     * @return bool True if the pool has at least one idle worker, otherwise false.
     */
    public function isIdle(): bool
    {
        return $this->idleWorkers->count() > 0 || $this->workers->count() < $this->getLimit();
    }

    public function getWorkerCount(): int
    {
        return count($this->workers);
    }

    public function getIdleWorkerCount(): int
    {
        return $this->idleWorkers->count();
    }

    public function getRunningWorkersCount(): int
    {
        return $this->workers->count() - $this->idleWorkers->count();
    }

    /**
     * Submits a {@see Task} to be executed by the worker pool.
     */
    public function submit(Task $task, ?Cancellation $cancellation = null): Execution
    {
        $worker = $this->pull();
        $push = $this->push;

        try {
            $execution = $worker->submit($task, $cancellation);
        } catch (\Throwable $exception) {
            $push($worker);
            throw $exception;
        }

        $execution->getFuture()
            ->catch(function (\Throwable $e) use ($worker) {
                if ($e instanceof TaskFailureThrowable) {
                    $this->logger->error("Worker {$worker->config->id}: {$e->getOriginalClassName()}");
                    $this->logger->error($e->getOriginalMessage());

                    if ($e->getOriginalClassName() === VoskServerUnavailableException::class) {
                        $this->fireWorker($worker);
                    }
                }
                throw $e;
            })
            ->finally(static fn () => $push($worker))
            ->ignore();

        return $execution;
    }

    /**
     * Shuts down the pool and all workers in it.
     *
     * @throws StatusError If the pool has not been started.
     */
    public function shutdown(): void
    {
        if ($this->exitStatus) {
            $this->exitStatus->await();
            return;
        }

        $this->deferredCancellation->cancel();

        while (!$this->waiting->isEmpty()) {
            $this->waiting->dequeue()->error(
                $exception ??= new WorkerException('The pool shut down before the task could be executed'),
            );
        }

        $futures = \array_map(
            static fn (Worker $worker) => async($worker->shutdown(...)),
            \iterator_to_array($this->workers),
        );

        ($this->exitStatus = async(Future\awaitAll(...), $futures)->map(static fn () => null))->await();
    }

    /**
     * Kills all workers in the pool and halts the worker pool.
     */
    public function kill(): void
    {
        $this->deferredCancellation->cancel();
        self::killWorkers($this->workers, $this->waiting);
    }

    /**
     * @param \SplObjectStorage<Worker, int> $workers
     * @param \SplQueue<DeferredFuture<Worker|null>> $waiting
     */
    protected static function killWorkers(
        \SplObjectStorage $workers,
        \SplQueue $waiting,
        ?\Throwable $exception = null,
    ): void {
        foreach ($workers as $worker) {
            \assert($worker instanceof Worker);
            if ($worker->isRunning()) {
                $worker->kill();
            }
        }

        while (!$waiting->isEmpty()) {
            $waiting->dequeue()->error(
                $exception ??= new WorkerException('The pool was killed before the task could be executed'),
            );
        }
    }

    public function getWorker(): Worker {
        throw new \BadMethodCallException(__METHOD__ . ': Not supported');
    }

    public function fireWorker(VoskWorker $worker): void {
        $this->logger->notice("Firing worker {$worker->config->id}");
        try {
            if ($worker->isRunning()) {
                $worker->kill();
            }
        } catch (\Throwable $e) {
            $this->logger->error("Error while firing worker {$worker->config->id}");
            $this->logger->error($e->getMessage());
        }
        $this->workers->detach($worker);
    }

    /**
     * Pulls a worker from the pool.
     *
     * @throws StatusError
     * @throws WorkerException
     * @throws NoAvailableWorkerException
     */
    protected function pull(): VoskWorker
    {
        if (!$this->isRunning()) {
            throw new StatusError("The pool was shut down");
        }

        do {
            if (!$this->workers->count()) {
                $this->logger->debug('Cannot pull worker from an empty pool!');
                throw new NoAvailableWorkerException('No (more) available workers in the pool.');
            }

            if ($this->idleWorkers->isEmpty()) {
                /** @var DeferredFuture<VoskWorker|null> $deferredFuture */
                $deferredFuture = new DeferredFuture;
                $this->waiting->enqueue($deferredFuture);

                $this->logger->debug('Waiting for an available worker...');
                try {
                    $worker = $deferredFuture->getFuture()->await();
                } catch (\Throwable $e) {
                    $this->logger->error('Could not obtain a valid worker: ' . $e->getMessage());
                }
                if (isset($worker)) {
                    $this->logger->debug("Newly available worker found: {$worker->config->id}");
                } else {
                    $this->logger->debug("No available worker found.");
                }
            } else {
                // Shift a worker off the idle queue.
                $worker = $this->idleWorkers->shift();
                $this->logger->debug("Idle worker found: {$worker->config->id}");
            }

            if (!isset($worker)) {
                // Worker crashed when executing a Task, which should have failed.
                continue;
            }

            \assert($worker instanceof VoskWorker);

            if ($worker->isRunning()) {
                return $worker;
            }

            if ($this->workers->contains($worker)) { // Prevents shutting down an already fired worker
                $logger = $this->logger;
                // Worker crashed while idle; trigger error and remove it from the pool.
                EventLoop::queue(static function () use ($worker, $logger): void {
                    try {
                        $worker->shutdown();
                        \trigger_error('Worker in pool exited unexpectedly', \E_USER_WARNING);
                    } catch (\Throwable $exception) {
                        $logger->error("Worker in pool crashed with exception on shutdown: {$exception->getMessage()}");
                        \trigger_error(
                            'Worker in pool crashed with exception on shutdown: ' . $exception->getMessage(),
                            \E_USER_WARNING,
                        );
                    }
                });
                $this->logger->debug("Detaching worker from pool: {$worker->config->id}");
                $this->workers->detach($worker);
            }

        } while (true);
    }
}
