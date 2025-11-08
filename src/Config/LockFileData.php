<?php

declare(strict_types=1);

namespace Cortex\Config;

/**
 * Value object representing lock file data
 */
readonly class LockFileData
{
    public function __construct(
        public ?string $namespace,
        public ?int $portOffset,
        public string $startedAt,
    ) {
    }
}
