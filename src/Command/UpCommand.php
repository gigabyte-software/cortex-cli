<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Docker\Exception\ServiceNotHealthyException;
use Cortex\Orchestrator\SetupOrchestrator;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly SetupOrchestrator $setupOrchestrator,
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
        $formatter = new OutputFormatter($output);

        try {
            $formatter->welcome();

            // Load configuration
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            $formatter->info("Loaded configuration from: $configPath");

            // Run setup through orchestrator
            $totalTime = $this->setupOrchestrator->setup(
                $config,
                $input->getOption('no-wait'),
                $input->getOption('skip-init')
            );

            $formatter->completionSummary($totalTime);

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

