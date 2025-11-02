<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Docker\DockerCompose;
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

            // Phase 1: Pre-start commands (skeleton - not fully implemented yet)
            if (!empty($config->setup->preStart)) {
                $formatter->section('Pre-start commands');
                $formatter->info('Pre-start commands will be executed here (Phase 2)');
            }

            // Phase 2: Start Docker services
            $formatter->section('Starting Docker services');
            $this->dockerCompose->up($config->docker->composeFile);
            $formatter->info('Docker services started');

            // Phase 3: Wait for services (skeleton)
            if (!$input->getOption('no-wait') && !empty($config->docker->waitFor)) {
                $formatter->section('Waiting for services');
                $formatter->info('Service health checks will be implemented in Phase 2');
            }

            // Phase 4: Initialize commands (skeleton)
            if (!$input->getOption('skip-init') && !empty($config->setup->initialize)) {
                $formatter->section('Initialize commands');
                $formatter->info('Initialize commands will be executed here (Phase 2)');
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
        }
    }
}

