<?php

namespace App\Service;

use Amp\Future;
use App\Exception\ElasticsearchFileIndexerException;
use App\Task\VideoSpeechToText\Result;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\SplFileInfo;
use Webgriffe\AmpElasticsearch\Client;
use Webgriffe\AmpElasticsearch\Error;

class ElasticsearchFileIndexer
{
    protected \Psr\Log\LoggerInterface $logger;

    protected bool $initialized = false;

    public function __construct(
        LoggerInterface $elasticsearchLogger,
        protected readonly Client $esClient,
        protected string $esIndex,
        protected bool $esSkipMappingUpdate,
    ) {
        $this->logger = $elasticsearchLogger;
    }

    public function init(): void {
        $this->initIndex();
        if (!$this->esSkipMappingUpdate) {
            $this->initMapping();
        }
        $this->initialized = true;
    }

    public function assertInit(): void {
        if (!$this->initialized) {
            throw new ElasticsearchFileIndexerException('Service must be initialized first.');
        }
    }

    public function initIndex($dropIfExists = false): void {
        try {
            $create = true;
            if ($this->esClient->existsIndex($this->esIndex)->await()) {
                if ($dropIfExists) {
                    $this->esClient->deleteIndex($this->esIndex);
                } else {
                    $create = false;
                    $this->logger->info("Connection successful. Found index {$this->esIndex} in Elasticsearch.");
                }
            }
            if ($create) {
                $this->logger->info("Connection successful. Creating index {$this->esIndex} in Elasticsearch.");
                $this->esClient->createIndex($this->esIndex)->await();
            }
        } catch (\Throwable $e) {
            $this->logger->critical('Could not connect to Elasticsearch!', ['exception' => $e]);
            if ($e instanceof Error) {
                $this->logger->critical(
                    "[{$e->getCode()}] {$e->getMessage()} Response body: " . json_encode($e->getData())
                );
            }
            throw new ElasticsearchFileIndexerException(
                'Could not connect to Elasticsearch. Is it running?',
                previous: $e
            );
        }
    }

    public function initMapping(): void {
        try {
            $mapping = [
                'properties' => [
                    'filepath' => ['type' => 'text'],
                    'filesize' => ['type' => 'long'],
                    'filemtime' => [
                        'type' => 'date',
                        'format' => 'strict_date_optional_time||epoch_second'
                    ],
                    'vosk_result' => [
                        'type' => 'nested',
                        'properties' => [
                            'result' => [
                                'type' => 'nested',
                                'properties' => [
                                    'conf' => ['type' => 'float'],
                                    'start' => ['type' => 'float'],
                                    'end' => ['type' => 'float'],
                                    'word' => ['type' => 'text'],
                                ]
                            ],
                            'text' => ['type' => 'text']
                        ]
                    ],
                    'text' => ['type' => 'text'],
                    'vstt_worker_id' => ['type' => 'keyword'],
                    'vstt_date' => ['type' => 'date'],
                ]
            ];
            $this->esClient->updateMapping($mapping, $this->esIndex,)->await();
        } catch (\Throwable $e) {
            $this->logger->critical('Could not set/update mapping in Elasticsearch!', ['exception' => $e]);
            if ($e instanceof Error) {
                $this->logger->critical(
                    "[{$e->getCode()}] {$e->getMessage()} Response body: " . json_encode($e->getData())
                );
            }
            throw $e;
        }
    }
    public function clearIndex(): void {
        $this->esClient->deleteIndex($this->esIndex)->await();
    }

    public function shouldIndexFile(SplFileInfo $file): bool {
        $this->assertInit();
        return !$this->esClient->existsDocument($this->esIndex, $this->getDocumentIdForFile($file))->await();
    }

    public function indexFile(SplFileInfo $file, Result $vsttResult): Future {
        $this->assertInit();
        $this->logger->info(sprintf(
            'Indexing file %s to ES %s. Result weighs %.2f kb.',
            $file->getRelativePathname(),
            $this->esIndex,
            strlen(json_encode($vsttResult->voskResult)) / 1024
        ));

        $document = $this->prepareDocument($file, $vsttResult);

        return $this->esClient
            ->indexDocument(
                $this->esIndex,
                $this->getDocumentIdForFile($file),
                $document
            )
            ->map(function ($r) use ($file) {
                $this->logger->debug(sprintf('File %s indexed successfully.', $file->getRelativePathname()));

                return $r;
            })
            ->catch(function ($e) use ($file, $document) {
                $this->logger->error(sprintf('Failed indexing file %s:', $file->getRelativePathname()));
                if ($e instanceof Error) {
                    $this->logger->error(
                        "[{$e->getCode()}] {$e->getMessage()} Response body: " . json_encode($e->getData())
                    );
                } else {
                    $this->logger->error($e);
                }
                $this->logger->error('The document was: ' . json_encode($document));

                throw $e;
            })
        ;
    }

    public function prepareDocument(SplFileInfo $file, Result $vsttResult): array {
        $doc = [
            'filepath' => $file->getRelativePathname(),
            'filesize' => $file->getSize(),
            'filemtime' => $file->getMTime(),
            'vosk_result' => $vsttResult->voskResult,
            'text' => implode("\n", array_reduce($vsttResult->voskResult, function ($carry, $item) {
                return isset($item['text'])
                    ? array_merge($carry, [$item['text']])
                    : $carry;
            }, [])),
            'vstt_worker_id' => $vsttResult->workerId,
            'vstt_date' => $vsttResult->datetime->format(\DateTime::RFC3339),
        ];

        return $doc;
    }

    public function getDocumentIdForFile(SplFileInfo $file): string {
        return uniqid('FILE_', true);
    }
}
