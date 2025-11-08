<?php

declare(strict_types=1);

namespace Cortex\Config\Schema;

readonly class ServiceWaitConfig
{
    public function __construct(
        public string $service,
        public int $timeout,
    ) {
    }
}
