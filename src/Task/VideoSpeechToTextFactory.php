<?php

namespace App\Task;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Process\Process;
use Amp\Sync\Channel;
use App\Constants;
use App\Task\VideoSpeechToText\Config;
use App\Task\VideoSpeechToText\Result;
use function Amp\async;

class VideoSpeechToTextFactory
{
    public function __construct(
        protected readonly \Psr\Log\LoggerInterface $taskLogger
    ) {
    }

    public function create(string $filePath, array $options = []) {
        return new VideoSpeechToText(
            $this->taskLogger,
            $filePath,
            $options['dry-run'] ?? Constants::DRY_RUN_MODE_DISABLED
        );
    }
}
