<?php

declare(strict_types=1);

namespace Cortex\Laravel;

readonly class LogSummaryEntry
{
    public function __construct(
        public string $level,
        public string $message,
        public int $count,
        public \DateTimeImmutable $lastOccurrence,
    ) {
    }
}
