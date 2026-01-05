<?php

declare(strict_types=1);

namespace Cortex\Config\Schema;

readonly class CortexConfig
{
    /**
     * @param array<string, CommandDefinition> $commands
     */
    public function __construct(
        public string $version,
        public DockerConfig $docker,
        public SetupConfig $setup,
        public N8nConfig $n8n,
        public array $commands = [],
    ) {
    }
}
