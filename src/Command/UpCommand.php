<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Config\LockFile;
use Cortex\Config\LockFileData;
use Cortex\Docker\ComposeOverrideGenerator;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\Exception\ServiceNotHealthyException;
use Cortex\Docker\NamespaceResolver;
use Cortex\Docker\PortOffsetManager;
use Cortex\Orchestrator\SetupOrchestrator;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class UpCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly SetupOrchestrator $setupOrchestrator,
        private readonly LockFile $lockFile,
        private readonly NamespaceResolver $namespaceResolver,
        private readonly PortOffsetManager $portOffsetManager,
        private readonly ComposeOverrideGenerator $overrideGenerator,
        private readonly DockerCompose $dockerCompose,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('up')
            ->setDescription('Set up the development environment')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Custom container namespace prefix')
            ->addOption('port-offset', null, InputOption::VALUE_REQUIRED, 'Port offset to add to all exposed ports')
            ->addOption('avoid-conflicts', null, InputOption::VALUE_NONE, 'Automatically avoid container and port conflicts')
            ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Skip health checks')
            ->addOption('skip-init', null, InputOption::VALUE_NONE, 'Skip initialize commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            $formatter->welcome();

            // Check if already running
            if ($this->lockFile->exists()) {
                $formatter->error('Environment already running in this directory.');
                $formatter->info('Use "cortex down" to stop it first.');
                return Command::FAILURE;
            }

            // Load configuration
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            $formatter->info("Loaded configuration from: $configPath");

            // Determine namespace early (needed for stale container detection)
            $namespace = $this->resolveNamespace($input, $formatter);

            // Check for stale containers (containers exist but no lock file)
            // This can happen if a previous run failed before writing the lock file
            if ($namespace !== null) {
                $this->cleanupStaleContainers($config, $formatter, $namespace);
            }

            // Determine port offset
            $portOffset = $this->resolvePortOffset($input, $config->docker->composeFile, $formatter);

            // Generate override file if port offset is needed or if using namespace
            // (need to prefix explicit container_name fields to avoid conflicts)
            $needsOverride = $portOffset > 0 || $namespace !== null;
            if ($needsOverride) {
                $this->overrideGenerator->generate($config->docker->composeFile, $portOffset, $namespace);
            }

            // Run setup through orchestrator
            $result = $this->setupOrchestrator->setup(
                $config,
                $input->getOption('no-wait'),
                $input->getOption('skip-init'),
                $namespace,
                $portOffset
            );

            // Write lock file if we generated an override file
            if ($needsOverride) {
                $lockData = new LockFileData(
                    namespace: $namespace,
                    portOffset: $portOffset > 0 ? $portOffset : null,
                    startedAt: date('c')
                );
                $this->lockFile->write($lockData);
                $output->writeln('');
                $formatter->info('Instance details saved to .cortex.lock');
            }

            // Display completion summary with port information
            $this->displayCompletionSummary($formatter, $result['time'], $config, $portOffset);

            return Command::SUCCESS;
        } catch (ConfigException $e) {
            $formatter->error("Configuration error: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (ServiceNotHealthyException $e) {
            $formatter->error($e->getMessage());
            return Command::FAILURE;
        } catch (\Exception $e) {
            $formatter->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Resolve the namespace from input options
     * Returns null for default mode (no namespace isolation)
     */
    private function resolveNamespace(InputInterface $input, OutputFormatter $formatter): ?string
    {
        $avoidConflicts = $input->getOption('avoid-conflicts');
        $namespaceOption = $input->getOption('namespace');

        if ($namespaceOption !== null) {
            // Use explicit namespace
            $this->namespaceResolver->validate($namespaceOption);
            return $namespaceOption;
        }

        if ($avoidConflicts) {
            // Auto-generate namespace from directory
            $namespace = $this->namespaceResolver->deriveFromDirectory();
            $formatter->info("Auto-generated namespace: {$namespace}");
            return $namespace;
        }

        // Default mode: no namespace isolation
        return null;
    }

    /**
     * Resolve the port offset from input options
     */
    private function resolvePortOffset(InputInterface $input, string $composeFile, OutputFormatter $formatter): int
    {
        $avoidConflicts = $input->getOption('avoid-conflicts');
        $portOffsetOption = $input->getOption('port-offset');

        if ($portOffsetOption !== null) {
            // Use explicit port offset
            $offset = (int) $portOffsetOption;
            if ($offset < 0) {
                throw new \InvalidArgumentException('Port offset must be a positive integer');
            }
            return $offset;
        }

        if ($avoidConflicts) {
            // Auto-allocate port offset
            $basePorts = $this->portOffsetManager->extractBasePorts($composeFile);
            if (empty($basePorts)) {
                return 0; // No ports to offset
            }

            $formatter->info('Scanning for available ports...');
            $offset = $this->portOffsetManager->findAvailableOffset($basePorts);

            if ($offset > 0) {
                $formatter->info("Port offset allocated: +{$offset}");
            }

            return $offset;
        }

        // No port offset by default
        return 0;
    }

    /**
     * Display completion summary with port information
     */
    private function displayCompletionSummary(
        OutputFormatter $formatter,
        float $totalTime,
        \Cortex\Config\Schema\CortexConfig $config,
        int $portOffset
    ): void {
        $output = $formatter->getOutput();
        $output->writeln('');
        $output->writeln(sprintf('<fg=#7D55C7>✨ Environment ready in %.1fs!</>', $totalTime));
        $output->writeln('');

        // Display URL with port offset if applicable
        if (isset($config->docker->appUrl) && !empty($config->docker->appUrl)) {
            $url = $config->docker->appUrl;

            // Apply port offset to URL if present
            if ($portOffset > 0 && preg_match('/^(https?:\/\/[^:]+):(\d+)(.*)$/', $url, $matches)) {
                $basePort = (int) $matches[2];
                $newPort = $basePort + $portOffset;
                $url = $matches[1] . ':' . $newPort . $matches[3];
            }

            $output->writeln(sprintf('<fg=green>→</> Access at: <fg=cyan>%s</>', $url));
            $output->writeln('');
        }
    }

    /**
     * Clean up stale containers from previous failed runs
     */
    private function cleanupStaleContainers(
        \Cortex\Config\Schema\CortexConfig $config,
        OutputFormatter $formatter,
        string $namespace
    ): void {
        // Check if containers exist with this namespace
        $command = ['docker', 'ps', '-a', '--filter', "name={$namespace}", '--format', '{{.Names}}'];
        $process = new Process($command);
        $process->run();
        
        if ($process->isSuccessful() && !empty(trim($process->getOutput()))) {
            $formatter->warning('Found containers from previous failed run. Cleaning up...');
            
            // Use docker-compose down to clean up properly
            try {
                $overrideFile = dirname($config->docker->composeFile) . '/docker-compose.override.yml';
                if (file_exists($overrideFile)) {
                    // Use the existing override file if present
                    $this->dockerCompose->down($config->docker->composeFile, false, $namespace);
                    $this->overrideGenerator->cleanup();
                } else {
                    // Just remove containers without override file
                    $this->dockerCompose->down($config->docker->composeFile, false, $namespace);
                }
                $formatter->info('Cleanup complete');
            } catch (\Exception $e) {
                // If cleanup fails, just warn but continue
                $formatter->warning('Could not fully clean up containers: ' . $e->getMessage());
            }
        }
    }
}
