<?php

namespace App\Model\Vosk\Worker;

use App\Trait\SerializableTrait;

class Config
{
    use SerializableTrait;

    public function __construct(
        public readonly string $id,
        public readonly string $websocketUrl,
        public readonly ?float $websocketTcpTimeout,
        public readonly ?float $websocketTlsTimeout,
        public readonly ?int $websocketRetry,
        public readonly ?int $ffmpegNice,
    ) {
    }
}
