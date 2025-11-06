<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Docker\ContainerExecutor;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShellCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly ContainerExecutor $containerExecutor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('shell')
            ->setDescription('Open an interactive bash shell in the primary service container');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            // Load configuration
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            $primaryService = $config->docker->primaryService;
            $composeFile = $config->docker->composeFile;

            // Execute interactive bash shell - no output, just pass through
            $exitCode = $this->containerExecutor->execInteractive($composeFile, $primaryService, '/bin/bash');

            return $exitCode;
        } catch (ConfigException $e) {
            $formatter->error("Configuration error: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (\Exception $e) {
            $formatter->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
