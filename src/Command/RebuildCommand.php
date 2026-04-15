<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Config\LockFile;
use Cortex\Docker\ComposeOverrideGenerator;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\Exception\ServiceNotHealthyException;
use Cortex\Docker\HealthChecker;
use Cortex\Orchestrator\CommandOrchestrator;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RebuildCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly DockerCompose $dockerCompose,
        private readonly HealthChecker $healthChecker,
        private readonly CommandOrchestrator $commandOrchestrator,
        private readonly LockFile $lockFile,
        private readonly ComposeOverrideGenerator $overrideGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('rebuild')
            ->setDescription('Rebuild Docker images, recreate containers, and run fresh');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            $formatter->welcome('Rebuilding Development Environment');

            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);
            $startTime = microtime(true);

            // Read namespace from lock file if present
            $namespace = null;
            if ($this->lockFile->exists()) {
                $lockData = $this->lockFile->read();
                $namespace = $lockData?->namespace;
            }

            // Phase 1: Tear down existing containers
            $formatter->section('Tearing down containers');
            try {
                $this->dockerCompose->down($config->docker->composeFile, false, $namespace);
                $formatter->info('Containers stopped');
            } catch (\RuntimeException $e) {
                $formatter->warning('Could not stop containers (they may not be running): ' . $e->getMessage());
            }

            // Clean up override file from previous run
            $this->overrideGenerator->cleanup();

            // Phase 2: Rebuild images and start containers
            $formatter->section('Rebuilding images and starting containers');
            $this->dockerCompose->upWithBuild($config->docker->composeFile, $namespace);
            $formatter->info('Containers rebuilt and started');

            // Phase 3: Wait for services to be healthy
            if (!empty($config->docker->waitFor)) {
                $formatter->section('Waiting for services');
                foreach ($config->docker->waitFor as $waitConfig) {
                    $waitStart = microtime(true);
                    $this->healthChecker->waitForHealth(
                        $config->docker->composeFile,
                        $waitConfig->service,
                        $waitConfig->timeout,
                        $namespace
                    );
                    $elapsed = microtime(true) - $waitStart;
                    $formatter->info(sprintf('%s (healthy after %.1fs)', $waitConfig->service, $elapsed));
                }
            }

            // Phase 4: Run `fresh` if defined
            if (isset($config->commands['fresh']) && trim($config->commands['fresh']->command) !== '') {
                $formatter->section('Running fresh');
                $this->commandOrchestrator->run('fresh', $config);
            } else {
                $formatter->warning('Command \'fresh\' is not defined in cortex.yml — skipping database reset');
                $formatter->info('Define a \'fresh\' command to have rebuild automatically reset your database');
            }

            $totalTime = microtime(true) - $startTime;
            $output->writeln('');
            $output->writeln(sprintf('<fg=#7D55C7>Environment rebuilt successfully (%.1fs)</>', $totalTime));
            $output->writeln('');

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
}
