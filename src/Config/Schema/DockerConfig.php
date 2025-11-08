<?php

declare(strict_types=1);

namespace Cortex\Config\Schema;

readonly class DockerConfig
{
    /**
     * @param ServiceWaitConfig[] $waitFor
     */
    public function __construct(
        public string $composeFile,
        public string $primaryService,
        public string $appUrl,
        public array $waitFor = [],
    ) {
    }
}
