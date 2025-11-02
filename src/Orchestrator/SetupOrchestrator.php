<?php

declare(strict_types=1);

namespace Cortex\Orchestrator;

use Cortex\Config\Schema\CommandDefinition;
use Cortex\Config\Schema\CortexConfig;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\Exception\ServiceNotHealthyException;
use Cortex\Docker\HealthChecker;
use Cortex\Executor\ContainerCommandExecutor;
use Cortex\Executor\HostCommandExecutor;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Process\Process;

class SetupOrchestrator
{
    public function __construct(
        private readonly DockerCompose $dockerCompose,
        private readonly HostCommandExecutor $hostExecutor,
        private readonly HealthChecker $healthChecker,
        private readonly OutputFormatter $formatter,
    ) {
    }

    /**
     * Orchestrate the full setup flow
     * 
     * @param bool $skipWait Skip health checks
     * @param bool $skipInit Skip initialize commands
     * @return float Total execution time
     * @throws \RuntimeException
     * @throws ServiceNotHealthyException
     */
    public function setup(CortexConfig $config, bool $skipWait = false, bool $skipInit = false): float
    {
        $startTime = microtime(true);

        // Phase 1: Pre-start commands
        if (!empty($config->setup->preStart)) {
            $this->runPreStartCommands($config->setup->preStart);
        }

        // Phase 2: Start Docker services
        $this->startDockerServices($config->docker->composeFile);

        // Phase 3: Wait for services
        if (!$skipWait && !empty($config->docker->waitFor)) {
            $this->waitForServices($config->docker->composeFile, $config->docker->waitFor);
        }

        // Phase 4: Initialize commands
        if (!$skipInit && !empty($config->setup->initialize)) {
            $this->runInitializeCommands(
                $config->setup->initialize,
                $config->docker->composeFile,
                $config->docker->primaryService
            );
        }

        return microtime(true) - $startTime;
    }

    /**
     * Execute pre-start commands on host
     * 
     * @param CommandDefinition[] $commands
     */
    private function runPreStartCommands(array $commands): void
    {
        $this->formatter->section('Pre-start commands');

        foreach ($commands as $cmd) {
            $this->executeHostCommand($cmd);
        }
    }

    /**
     * Start Docker Compose services
     */
    private function startDockerServices(string $composeFile): void
    {
        $this->formatter->section('Starting Docker services');
        $this->dockerCompose->up($composeFile);
        $this->formatter->info('Docker services started');
    }

    /**
     * Wait for services to become healthy
     * 
     * @param \Cortex\Config\Schema\ServiceWaitConfig[] $waitFor
     */
    private function waitForServices(string $composeFile, array $waitFor): void
    {
        $this->formatter->section('Waiting for services');

        foreach ($waitFor as $waitConfig) {
            $startTime = microtime(true);

            try {
                $this->healthChecker->waitForHealth(
                    $composeFile,
                    $waitConfig->service,
                    $waitConfig->timeout
                );

                $elapsed = microtime(true) - $startTime;
                $this->formatter->info(sprintf('%s (healthy after %.1fs)', $waitConfig->service, $elapsed));
            } catch (ServiceNotHealthyException $e) {
                throw $e;
            }
        }
    }

    /**
     * Execute initialize commands in container
     * 
     * @param CommandDefinition[] $commands
     */
    private function runInitializeCommands(array $commands, string $composeFile, string $primaryService): void
    {
        $this->formatter->section('Initialize commands');

        $containerExecutor = new ContainerCommandExecutor(
            new \Cortex\Docker\ContainerExecutor(),
            $composeFile,
            $primaryService
        );

        foreach ($commands as $cmd) {
            $this->executeContainerCommand($cmd, $containerExecutor);
        }
    }

    /**
     * Execute a host command with real-time output
     */
    private function executeHostCommand(CommandDefinition $cmd): void
    {
        $this->formatter->command($cmd);

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

        $result = $this->hostExecutor->execute($cmd, $outputCallback);

        if (!$result->isSuccessful() && !$cmd->ignoreFailure) {
            $this->formatter->error("Command failed: {$cmd->command}");
            throw new \RuntimeException("Host command failed: {$cmd->command}");
        }
    }

    /**
     * Execute a container command with real-time output
     */
    private function executeContainerCommand(CommandDefinition $cmd, ContainerCommandExecutor $executor): void
    {
        $this->formatter->command($cmd);

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

        $result = $executor->execute($cmd, $outputCallback);

        if (!$result->isSuccessful() && !$cmd->ignoreFailure) {
            $this->formatter->error("Command failed: {$cmd->command}");
            throw new \RuntimeException("Container command failed: {$cmd->command}");
        }
    }
}

