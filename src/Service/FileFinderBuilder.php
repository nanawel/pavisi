<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;

class FileFinderBuilder
{
    /** @var string[] */
    protected array $exclude = [];

    /** @var string[] */
    protected array $include = [];

    /** @var string[] */
    protected array $folders = [];

    public function __construct(
        protected readonly LoggerInterface $logger,
        protected array $filePatterns
    ) {
    }

    public function addExclude(mixed $exclude): self {
        if (is_array($exclude)) {
            $this->exclude = array_merge($this->exclude, $exclude);
        } else {
            $this->exclude[] = $exclude;
        }

        return $this;
    }

    public function addInclude(mixed $include): self {
        if (is_array($include)) {
            $this->include = array_merge($this->include, $include);
        } else {
            $this->include[] = $include;
        }

        return $this;
    }

    public function addFolders(array $folders): self {
        if (is_array($folders)) {
            $this->folders = array_merge($this->folders, $folders);
        } else {
            $this->folders[] = $folders;
        }

        return $this;
    }

    /**
     * @return Finder
     */
    public function build() {
        $finder = (new Finder())
            ->files()
            ->followLinks()
            ->ignoreUnreadableDirs()
            ->name($this->filePatterns)
        ;
        $finder->in($this->folders);
        if ($this->include) {
            $finder->path($this->include);
        }
        if ($this->exclude) {
            $finder->exclude($this->exclude);
        }
        $this->reset();

        $this->logger->info("Finder is set up.");

        return $finder;
    }

    public function reset(): void {
        $this->exclude = [];
        $this->include = [];
        $this->folders = [];
    }
}
