<?php

namespace App\Task\VideoSpeechToText;

use App\Trait\SerializableTrait;

class Config
{
    use SerializableTrait;

    public function __construct(
        public readonly \Psr\Log\LoggerInterface $logger,
        public readonly string                   $workerId,
        public readonly string                   $websocketUrl,
        public readonly ?float                   $websocketTcpTimeout,
        public readonly ?float                   $websocketTlsTimeout,
        public readonly ?int                     $websocketRetry,
        public readonly ?int                     $ffmpegNice,
    ) {
    }
}
