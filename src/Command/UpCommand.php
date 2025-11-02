<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Config\Schema\CommandDefinition;
use Cortex\Docker\ContainerExecutor;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\Exception\ServiceNotHealthyException;
use Cortex\Docker\HealthChecker;
use Cortex\Executor\ContainerCommandExecutor;
use Cortex\Executor\HostCommandExecutor;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly DockerCompose $dockerCompose,
        private readonly HostCommandExecutor $hostExecutor,
        private readonly ContainerExecutor $containerExecutor,
        private readonly HealthChecker $healthChecker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('up')
            ->setDescription('Set up the development environment')
            ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Skip health checks')
            ->addOption('skip-init', null, InputOption::VALUE_NONE, 'Skip initialize commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startTime = microtime(true);
        $formatter = new OutputFormatter($output);

        try {
            $formatter->welcome();

            // Load configuration
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            $formatter->info("Loaded configuration from: $configPath");

            // Phase 1: Pre-start commands (run on host)
            if (!empty($config->setup->preStart)) {
                $formatter->section('Pre-start commands');
                foreach ($config->setup->preStart as $cmd) {
                    $this->executeHostCommand($cmd, $formatter);
                }
            }

            // Phase 2: Start Docker services
            $formatter->section('Starting Docker services');
            $this->dockerCompose->up($config->docker->composeFile);
            $formatter->info('Docker services started');

            // Phase 3: Wait for services
            if (!$input->getOption('no-wait') && !empty($config->docker->waitFor)) {
                $formatter->section('Waiting for services');
                foreach ($config->docker->waitFor as $waitConfig) {
                    $this->waitForService($config->docker->composeFile, $waitConfig->service, $waitConfig->timeout, $formatter);
                }
            }

            // Phase 4: Initialize commands (run in container)
            if (!$input->getOption('skip-init') && !empty($config->setup->initialize)) {
                $formatter->section('Initialize commands');
                $containerExecutor = new ContainerCommandExecutor(
                    $this->containerExecutor,
                    $config->docker->composeFile,
                    $config->docker->primaryService
                );
                
                foreach ($config->setup->initialize as $cmd) {
                    $this->executeContainerCommand($cmd, $containerExecutor, $formatter);
                }
            }

            $totalTime = microtime(true) - $startTime;
            $formatter->completionSummary($totalTime);

            return Command::SUCCESS;
        } catch (ConfigException $e) {
            $formatter->error("Configuration error: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (\Exception $e) {
            $formatter->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (ServiceNotHealthyException $e) {
            $formatter->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function executeHostCommand(CommandDefinition $cmd, OutputFormatter $formatter): void
    {
        $formatter->command($cmd);
        
        $result = $this->hostExecutor->execute($cmd);
        
        if (!$result->isSuccessful() && !$cmd->ignoreFailure) {
            $formatter->error("Command failed: {$cmd->command}");
            if (!empty($result->errorOutput)) {
                $formatter->commandOutput($result->errorOutput);
            }
            throw new \RuntimeException("Host command failed: {$cmd->command}");
        }
        
        if (!empty($result->output)) {
            $formatter->commandOutput($result->output);
        }
    }

    private function executeContainerCommand(
        CommandDefinition $cmd,
        ContainerCommandExecutor $executor,
        OutputFormatter $formatter
    ): void {
        $formatter->command($cmd);
        
        $result = $executor->execute($cmd);
        
        if (!$result->isSuccessful() && !$cmd->ignoreFailure) {
            $formatter->error("Command failed: {$cmd->command}");
            if (!empty($result->errorOutput)) {
                $formatter->commandOutput($result->errorOutput);
            }
            throw new \RuntimeException("Container command failed: {$cmd->command}");
        }
        
        if (!empty($result->output)) {
            $formatter->commandOutput($result->output);
        }
    }

    private function waitForService(string $composeFile, string $service, int $timeout, OutputFormatter $formatter): void
    {
        $startTime = microtime(true);
        
        try {
            $this->healthChecker->waitForHealth($composeFile, $service, $timeout);
            $elapsed = microtime(true) - $startTime;
            $formatter->info(sprintf('%s (healthy after %.1fs)', $service, $elapsed));
        } catch (ServiceNotHealthyException $e) {
            throw $e;
        }
    }
}

