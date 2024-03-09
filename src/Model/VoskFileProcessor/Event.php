<?php

namespace App\Model\VoskFileProcessor;

use App\Service\VoskFileProcessor;

class Event
{
    public readonly \DateTime $dateTime;

    public function __construct(
        public readonly string            $name,
        public readonly mixed             $payload,
        public readonly VoskFileProcessor $source,
    ) {
        $this->dateTime = new \DateTime();
    }
}
