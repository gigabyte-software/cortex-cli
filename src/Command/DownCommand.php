<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Config\LockFile;
use Cortex\Docker\ComposeOverrideGenerator;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\NamespaceResolver;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DownCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly DockerCompose $dockerCompose,
        private readonly LockFile $lockFile,
        private readonly NamespaceResolver $namespaceResolver,
        private readonly ComposeOverrideGenerator $overrideGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('down')
            ->setDescription('Tear down the development environment')
            ->addOption('volumes', null, InputOption::VALUE_NONE, 'Remove volumes as well');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            // Load configuration to get compose file path
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            $formatter->section('Stopping environment');

            // Read lock file to get namespace
            $namespace = null;
            if ($this->lockFile->exists()) {
                $lockData = $this->lockFile->read();
                $namespace = $lockData?->namespace;
            }

            // If no lock file, derive namespace from directory
            if ($namespace === null) {
                $namespace = $this->namespaceResolver->deriveFromDirectory();
            }

            $removeVolumes = $input->getOption('volumes');

            // Stop Docker services
            $this->dockerCompose->down($config->docker->composeFile, $removeVolumes, $namespace);

            // Clean up override file
            $this->overrideGenerator->cleanup();

            // Delete lock file
            $this->lockFile->delete();

            if ($removeVolumes) {
                $formatter->info('Docker services stopped and volumes removed');
            } else {
                $formatter->info('Docker services stopped');
            }

            $output->writeln('');
            $output->writeln(sprintf('<fg=#7D55C7>Environment stopped successfully</>'));
            $output->writeln('');

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
