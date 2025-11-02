<?php

declare(strict_types=1);

namespace Cortex\Config\Schema;

readonly class CommandDefinition
{
    public function __construct(
        public string $command,
        public string $description,
        public int $timeout = 60,
        public int $retry = 0,
        public bool $ignoreFailure = false,
    ) {
    }
}

