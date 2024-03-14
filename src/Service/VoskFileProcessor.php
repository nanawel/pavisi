<?php

namespace App\Service;

use Amp\Future;
use Amp\Parallel\Worker\TaskFailureThrowable;
use App\Constants;
use App\Exception\TaskMaxRetryReachedException;
use App\Exception\VoskServerStreamException;
use App\Exception\VoskServerUnavailableException;
use App\Model\VoskFileProcessor\Event;
use App\Task\VideoSpeechToText;
use App\Task\VideoSpeechToTextFactory;
use App\Worker\VoskWorkerPool;
use App\Worker\VoskWorkerPoolFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use function Amp\delay;
use function Amp\Future\awaitAll;
use function App\humanSize;

class VoskFileProcessor
{
    public const FILE_MAX_RETRY = 3;

    protected VoskWorkerPool $workerPool;

    protected \SplQueue $requeuedFiles;

    /** @var array<string, int> */
    protected array $retryPerFile = [];

    /** @var callable[] */
    protected array $listeners = [];

    public function __construct(
        protected LoggerInterface          $logger,
        protected ElasticsearchFileIndexer $elasticsearchFileIndexer,
        protected VideoSpeechToTextFactory $videoSpeechToTextTaskFactory,
        protected VoskWorkerPoolFactory    $voskWorkerPoolFactory
    ) {
        $this->workerPool = $this->voskWorkerPoolFactory->create();
        $this->requeuedFiles = new \SplQueue();
    }

    public function run(Finder $finder, array $options = []): void {
        if ($options['dry-run']) {
            $this->logger->warning('DRY-RUN ENABLED');
        }

        $this->logger->info('Initializing Elasticsearch file indexer...');
        $this->elasticsearchFileIndexer->init();
        $this->logger->info('Elasticsearch file indexer initialization complete.');

        list($shouldIndexFileFunc) = $this->setupFileCollector($finder, $options);

        $this->dispatchEvent(
            'fileprocessor::starting',
            ['finder' => $finder]
        );

        $skippedCnt = 0;
        $futures = [];
        /** @var SplFileInfo $file */
        foreach ($this->files($finder) as $file) {
            if (!$shouldIndexFileFunc($file)) {
                $this->logger->info('File already indexed, skipping: ' . $file->getRelativePathname());
                $skippedCnt++;
                $this->dispatchEvent(
                    'fileprocessor::file::processed',
                    [
                        'filepath' => $file->getRelativePathname(),
                        'is_complete' => true,
                        'result' => null,
                        'status' => 'skipped'
                    ]
                );
                continue;
            }
            $this->logger->info("🎬️ Found file: {$file->getRelativePathname()}. Adding to queue.");
            if (count($futures) % 10 === 0) {
                $this->logger->debug(
                    "Memory usage: " . memory_get_usage(true) . ' bytes ('
                    . humanSize(memory_get_usage(true)) . ')'
                );
            }
            $futures[$file->getRelativePathname()] = $this->process($file, $options)
                ->map(function ($result) use ($file) {
                    if ($result instanceof VideoSpeechToText\Result) {
                        $this->logger->info("✅ Execution COMPLETE for {$file->getRelativePathname()}");
                        $success = true;
                    } else {
                        $this->logger->warning("⚠️ Execution UNDEFINED for {$file->getRelativePathname()}");
                        if ($result) {
                            $this->logger->warning(json_encode(
                                is_scalar($result) ? $result : (array) $result
                            ));
                        }
                        $success = false;
                    }
                    $this->dispatchEvent(
                        'fileprocessor::file::processed',
                        [
                            'filepath' => $file->getRelativePathname(),
                            'is_complete' => true,
                            'result' => $result,
                            'status' => $success ? 'success' : 'undefined'
                        ]
                    );
                })
                ->catch(function (\Throwable $e) use ($file) {
                    $retryFile = false;
                    $exClass = get_class($e);
                    if ($e instanceof TaskFailureThrowable) {
                        $message = $e->getOriginalMessage();
                        // The remote Vosk server was not available so requeue file to try another one
                        if ($e->getOriginalClassName() === VoskServerUnavailableException::class) {
                            $this->logger->notice("☝️ {$file->getRelativePathname()} can be retried, requeueing.");
                            $retryFile = $this->retry($file, false);
                        }
                        // Communication error between the script and Vosk server, it might be because of
                        // the file, but it probably isn't, so wait a bit and retry
                        elseif ($e->getOriginalClassName() === VoskServerStreamException::class) {
                            delay(10);
                            $retryFile = $this->retry($file);
                        }
                        // Other type of error, retry with limit
                        else {
                            $retryFile = $this->retry($file);
                        }
                    } else {
                        $message = $e->getMessage();
                    }
                    $this->logger->error(
                        "❌ Execution FAILED for {$file->getRelativePathname()}: {$exClass} {$message}"
                    );
                    if ($retryFile) {
                        $this->logger->notice("☝️ {$file->getRelativePathname()} can be retried, requeueing.");
                    } else {
                        $this->logger->error(
                            "❌ Max attempts reached for file {$file->getRelativePathname()}, skipping."
                        );
                        $this->dispatchEvent(
                            'fileprocessor::file::processed',
                            [
                                'filepath' => $file->getRelativePathname(),
                                'is_complete' => true,
                                'result' => $e,
                                'status' => 'failure'
                            ]
                        );
                    }
                    throw $e;
                })
                ->ignore();
        }

        /** @var \Throwable[] $exceptions */
        [$exceptions, $results] = awaitAll($futures);

        $this->dispatchEvent(
            'fileprocessor::finishing',
            [
                'results' => $results,
                'exceptions' => $exceptions,
                'skipped_cnt' => $skippedCnt
            ]
        );

        $this->logger->info('Run complete.');
        $this->logger->info(sprintf('%d file(s) have been processed.', count($results)));
        $this->logger->info(sprintf('%d file(s) have been skipped.', $skippedCnt));
        $this->logger->info(sprintf('%d error(s) have been encountered.', count($exceptions)));
        if ($options['dry-run']) {
            $this->logger->warning('DRY-RUN ENABLED. No file has actually been processed.');
        }
    }

    protected function setupFileCollector(Finder $finder, array $options): array {
        $this->dispatchEvent(
            'filecollector::init',
            ['finder' => $finder]
        );

        $shouldIndexFile = fn(SplFileInfo $file) => $this->elasticsearchFileIndexer->shouldIndexFile($file);

        if ($options['progress'] ?? Constants::PROGRESS_MODE_DISABLED) {
            $this->dispatchEvent(
                'filecollector::calculating::start',
                ['finder' => $finder, 'progress' => $options['progress']]
            );
            if ($options['progress'] == Constants::PROGRESS_MODE_TWO_PASS) {
                $filesCount = 0;
                $alreadyIndexFiles = [];
                foreach ($finder as $file) {
                    $filesCount++;
                    if (!$this->elasticsearchFileIndexer->shouldIndexFile($file)) {
                        $alreadyIndexFiles[] = $file->getRelativePathname();
                    }
                    $this->dispatchEvent(
                        'filecollector::calculating::found_file',
                        [
                            'finder' => $finder,
                            'file' => $file,
                            'files_total' => $filesCount,
                            'files_count' => $filesCount - count($alreadyIndexFiles)
                        ]
                    );
                }
                $shouldIndexFile = static fn(SplFileInfo $file)
                => !in_array($file->getRelativePathname(), $alreadyIndexFiles);
            } else {
                $filesCount = $finder->count();
            }

            $this->dispatchEvent(
                'filecollector::calculating::done',
                [
                    'finder' => $finder,
                    'files_total' => $filesCount,
                    'files_count' => isset($alreadyIndexFiles)
                        ? $filesCount - count($alreadyIndexFiles)
                        : $filesCount
                ]
            );
        }

        $this->dispatchEvent(
            'filecollector::ready',
            ['finder' => $finder]
        );

        return [$shouldIndexFile];
    }

    public function files(Finder $finder): \Generator {
        $finderIterator = $finder->getIterator();
        $finderIterator->rewind();
        $endOfFiles = false;
        while (true) {
            if ($this->requeuedFiles->count()) {
                yield $this->requeuedFiles->dequeue();
            } elseif ($finderIterator->valid()) {
                yield $finderIterator->current();
                $finderIterator->next();
            } elseif ($this->workerPool->getRunningWorkersCount() > 0) {
                if (!$endOfFiles) {
                    $this->logger->info('No more new files to process. Waiting fo the current pool to complete.');
                    $endOfFiles = true;
                }
                // Pool has not completed its queue so we cannot be sure there won't be any
                // new files added to requeue yet. Wait a bit and check again.
                delay(3);
            } else {
                break;
            }
        }
    }

    public function canRetry(SplFileInfo $file): bool {
        if (!isset($this->retryPerFile[$file->getRealPath()])) {
            $this->retryPerFile[$file->getRealPath()] = 0;
            return true;
        }
        return $this->retryPerFile[$file->getRealPath()] < self::FILE_MAX_RETRY;
    }

    public function retry(SplFileInfo $file, bool $incrementRetry = true): bool {
        if ($this->canRetry($file)) {
            return false;
        }
        $this->requeuedFiles->enqueue($file);
        if ($incrementRetry) {
            $this->retryPerFile[$file->getRealPath()]++;
        }
        return true;
    }

    public function process(SplFileInfo $file, array $options): Future {
        $task = $this->videoSpeechToTextTaskFactory->create($file->getRealPath(), $options);
        $future = $this->workerPool->submit($task)->getFuture()
            ->map(function ($vsttResult) use ($file) {
                $this->elasticsearchFileIndexer->indexFile($file, $vsttResult)->await();
                return $vsttResult;
            })
            ->ignore()
        ;

        return $future;
    }

    public function addListener(callable $listener): void {
        $this->listeners[] = $listener;
    }

    public function dispatchEvent(string $name, mixed $payload): void {
        $ev = new Event($name, $payload, $this);
        foreach ($this->listeners as $l) {
            try {
                $l($ev);
            } catch (\Throwable $e) {
                $this->logger->error("An error occurred while dispatching event $name: {$e->getMessage()}. Ignoring.");
            }
        }
    }
}
