<?php

declare(strict_types=1);

namespace Cortex\Orchestrator;

use Cortex\Config\Schema\CommandDefinition;
use Cortex\Config\Schema\CortexConfig;
use Cortex\Config\Validator\SecretsValidator;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\Exception\ServiceNotHealthyException;
use Cortex\Docker\HealthChecker;
use Cortex\Docker\ServiceReadinessWaiter;
use Cortex\Executor\ContainerCommandExecutor;
use Cortex\Executor\HostCommandExecutor;
use Cortex\Output\LiveLogPanel;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Process\Process;

class SetupOrchestrator
{
    private readonly ServiceReadinessWaiter $readinessWaiter;

    public function __construct(
        private readonly DockerCompose $dockerCompose,
        private readonly HostCommandExecutor $hostExecutor,
        private readonly HealthChecker $healthChecker,
        private readonly OutputFormatter $formatter,
        private readonly SecretsValidator $secretsValidator = new SecretsValidator(),
        ?ServiceReadinessWaiter $readinessWaiter = null,
    ) {
        $this->readinessWaiter = $readinessWaiter ?? new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
        );
    }

    /**
     * Orchestrate the full setup flow
     *
     * @param CortexConfig $config Configuration
     * @param bool $skipWait Skip health checks
     * @param bool $skipInit Skip initialize commands
     * @param string|null $namespace Container namespace
     * @param int|null $portOffset Port offset to apply
     * @return array{time: float, namespace: string, port_offset: int} Setup results
     * @throws \RuntimeException
     * @throws ServiceNotHealthyException
     */
    public function setup(
        CortexConfig $config,
        bool $skipWait = false,
        bool $skipInit = false,
        ?string $namespace = null,
        ?int $portOffset = null,
        bool $rebuild = false,
        ?int $timeout = null
    ): array {
        $startTime = microtime(true);

        // Validate required secrets are available
        if (!empty($config->secrets->required)) {
            $this->validateSecrets($config);
        }

        // Detect first run (no existing images)
        $firstRun = !$this->dockerCompose->hasExistingImages($config->docker->composeFile, $namespace);
        if ($firstRun) {
            $this->formatter->section('First run detected');
            $this->formatter->info('Building containers may take a few minutes');
        }

        // Phase 1: Pre-start commands
        if (!empty($config->setup->preStart)) {
            $this->runPreStartCommands($config->setup->preStart);
        }

        // Phase 2: Start Docker services
        $this->startDockerServices($config->docker->composeFile, $namespace, $rebuild, $timeout, $firstRun);

        // Phase 3: Wait for services with live status display. Even when the
        // user has no explicit wait_for entries, we still scan every compose
        // service for crash-loops so a broken container never masquerades as a
        // successful startup.
        if (!$skipWait) {
            $this->waitForServices($config->docker->composeFile, $config->docker->waitFor, $namespace, $firstRun);
        }

        // Phase 4: Initialize commands
        if (!$skipInit && !empty($config->setup->initialize)) {
            $this->runInitializeCommands(
                $config->setup->initialize,
                $config->docker->composeFile,
                $config->docker->primaryService,
                $namespace
            );
        }

        return [
            'time' => microtime(true) - $startTime,
            'namespace' => $namespace ?? '',
            'port_offset' => $portOffset ?? 0,
        ];
    }

    /**
     * Validate that all required secrets are available before setup proceeds
     */
    private function validateSecrets(CortexConfig $config): void
    {
        $this->formatter->section('Validating secrets');

        $missing = $this->secretsValidator->validate($config->secrets);

        if (!empty($missing)) {
            $names = implode(', ', $missing);
            $this->formatter->error("Missing required secrets: $names");
            throw new \RuntimeException(
                "Missing required secrets: $names. These must be set as environment variables."
            );
        }

        $count = count($config->secrets->required);
        $this->formatter->info("All $count required secret(s) available");
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
     * Start Docker Compose services with a live, rolling 3-line log panel
     * showing the latest docker-compose output (build progress, container
     * creation, etc.). The panel is cleared when the command completes so
     * it leaves no trace on the console.
     */
    private function startDockerServices(
        string $composeFile,
        ?string $namespace = null,
        bool $rebuild = false,
        ?int $timeout = null,
        bool $firstRun = false
    ): void {
        $this->formatter->section('Starting Docker services');

        if ($namespace !== null) {
            $this->formatter->info("Using namespace: {$namespace}");
        }

        if ($rebuild) {
            $this->formatter->info('Rebuilding Docker images...');
        }

        // On first run the images have to be built (or pulled), which can take
        // well over the default 5-minute non-rebuild timeout. Extend it to 30
        // minutes unless the caller passed an explicit --timeout.
        $effectiveTimeout = $timeout;
        if ($effectiveTimeout === null && $firstRun && !$rebuild) {
            $effectiveTimeout = 1800;
        }

        $panel = new LiveLogPanel($this->formatter->createSection(), 3);
        try {
            $this->dockerCompose->up(
                $composeFile,
                $namespace,
                $rebuild,
                $effectiveTimeout,
                static function (string $type, string $buffer) use ($panel): void {
                    $panel->appendBuffer($buffer);
                }
            );
        } finally {
            $panel->clear();
        }

        $this->formatter->info('Docker services started');
    }

    /**
     * Wait for services to become healthy with live-updating status display,
     * delegating to the shared {@see ServiceReadinessWaiter}.
     *
     * Every compose service that is NOT in the explicit wait list is passed
     * through as a monitored service: while we wait on the explicit ones,
     * crashes in any other container (for example an nginx whose upstream
     * `app` is dead) will abort immediately rather than being silently
     * ignored.
     *
     * @param \Cortex\Config\Schema\ServiceWaitConfig[] $waitFor
     */
    private function waitForServices(string $composeFile, array $waitFor, ?string $namespace = null, bool $firstRun = false): void
    {
        if (empty($waitFor)) {
            // No explicit wait list: the section header would be misleading. We
            // still want to detect crash-looping services, so run the one-shot
            // verification silently.
            $allServices = $this->dockerCompose->listServices($composeFile, $namespace);
            $this->readinessWaiter->verifyNoServicesFailed($composeFile, $allServices, $namespace);
            return;
        }

        $this->formatter->section('Waiting for services');

        $monitorServices = $this->computeMonitorServices($composeFile, $waitFor, $namespace);

        $this->readinessWaiter->waitForAll(
            $composeFile,
            $waitFor,
            $namespace,
            $firstRun ? 10 : 1,
            $monitorServices,
        );
    }

    /**
     * Return the list of compose services that are not already covered by
     * $waitFor. These are the services we want to watch for crash-loops while
     * the explicit wait is in progress.
     *
     * @param \Cortex\Config\Schema\ServiceWaitConfig[] $waitFor
     *
     * @return list<string>
     */
    private function computeMonitorServices(string $composeFile, array $waitFor, ?string $namespace): array
    {
        $allServices = $this->dockerCompose->listServices($composeFile, $namespace);
        if ($allServices === []) {
            return [];
        }

        $waitForNames = [];
        foreach ($waitFor as $waitConfig) {
            $waitForNames[$waitConfig->service] = true;
        }

        return array_values(array_filter(
            $allServices,
            static fn (string $service): bool => !isset($waitForNames[$service])
        ));
    }

    /**
     * Execute initialize commands in container
     *
     * @param CommandDefinition[] $commands
     */
    private function runInitializeCommands(
        array $commands,
        string $composeFile,
        string $primaryService,
        ?string $namespace = null
    ): void {
        $this->formatter->section('Initialize commands');

        $containerExecutor = new ContainerCommandExecutor(
            new \Cortex\Docker\ContainerExecutor(),
            $composeFile,
            $primaryService,
            $namespace
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
