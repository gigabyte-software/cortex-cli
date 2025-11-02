<?php

declare(strict_types=1);

namespace Cortex\Orchestrator;

use Cortex\Config\Schema\CommandDefinition;
use Cortex\Config\Schema\CortexConfig;
use Cortex\Docker\ContainerExecutor;
use Cortex\Executor\ContainerCommandExecutor;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Process\Process;

class CommandOrchestrator
{
    public function __construct(
        private readonly OutputFormatter $formatter,
    ) {
    }

    /**
     * Run a custom command from cortex.yml
     * 
     * @throws \RuntimeException
     */
    public function run(string $commandName, CortexConfig $config): float
    {
        // Check if command exists
        if (!isset($config->commands[$commandName])) {
            throw new \RuntimeException("Command '$commandName' not found in cortex.yml");
        }

        $cmd = $config->commands[$commandName];
        $startTime = microtime(true);

        // Show command header
        $this->formatter->section("Running: $commandName");
        $this->formatter->command($cmd);

        // Execute in container
        $containerExecutor = new ContainerCommandExecutor(
            new ContainerExecutor(),
            $config->docker->composeFile,
            $config->docker->primaryService
        );

        // Create output callback for real-time streaming
        $outputCallback = function ($type, $buffer) {
            if ($type === Process::OUT || $type === Process::ERR) {
                $lines = explode("\n", rtrim($buffer));
                foreach ($lines as $line) {
                    if (!empty(trim($line))) {
                        $this->formatter->commandOutput($line);
                    }
                }
            }
        };

        $result = $containerExecutor->execute($cmd, $outputCallback);

        if (!$result->isSuccessful()) {
            // Check if the error is because services aren't running
            if (str_contains($result->errorOutput, 'is not running') || str_contains($result->output, 'is not running')) {
                $this->formatter->error("Services are not running. Start them with 'cortex up' first.");
            } else {
                $this->formatter->error("Command failed with exit code {$result->exitCode}");
            }
            throw new \RuntimeException("Command '$commandName' failed");
        }

        $executionTime = microtime(true) - $startTime;
        return $executionTime;
    }

    /**
     * List all available custom commands
     * 
     * @return array<string, string> Command name => description
     */
    public function listAvailableCommands(CortexConfig $config): array
    {
        $commands = [];
        foreach ($config->commands as $name => $cmd) {
            $commands[$name] = $cmd->description;
        }
        return $commands;
    }
}

