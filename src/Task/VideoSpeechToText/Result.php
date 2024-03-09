<?php

namespace App\Task\VideoSpeechToText;

use App\Trait\SerializableTrait;

class Result
{
    use SerializableTrait;

    public function __construct(
        public readonly string $workerId,
        public readonly string $filePath,
        public readonly \DateTime $datetime,
        public readonly array $voskResult
    ) {
    }
}
