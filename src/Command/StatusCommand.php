<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\HealthChecker;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly DockerCompose $dockerCompose,
        private readonly HealthChecker $healthChecker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('status')
            ->setDescription('Check the health status of services');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            // Load configuration
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            $formatter->section('Service Status');

            // Check if services are running
            if (!$this->dockerCompose->isRunning($config->docker->composeFile)) {
                $formatter->warning('No services are currently running');
                $formatter->info('Run "cortex up" to start the environment');
                return Command::SUCCESS;
            }

            // Get service info
            $services = $this->dockerCompose->ps($config->docker->composeFile);

            if (empty($services)) {
                $formatter->warning('No services found');
                return Command::SUCCESS;
            }

            // Build table data
            $table = new Table($output);
            $table->setHeaders(['Service', 'Status', 'Health']);

            foreach ($services as $serviceName => $serviceData) {
                $status = $serviceData['State'] ?? 'unknown';
                $health = $this->healthChecker->getHealthStatus($config->docker->composeFile, $serviceName);
                
                // Color code the status
                $statusFormatted = match($status) {
                    'running' => "<fg=green>$status</>",
                    'exited' => "<fg=red>$status</>",
                    default => "<fg=yellow>$status</>",
                };

                // Color code the health
                $healthFormatted = match($health) {
                    'healthy' => "<fg=green>$health</>",
                    'unhealthy' => "<fg=red>$health</>",
                    'starting' => "<fg=yellow>$health</>",
                    'running' => "<fg=green>$health</>",
                    default => "<fg=gray>$health</>",
                };

                $table->addRow([
                    $serviceName,
                    $statusFormatted,
                    $healthFormatted,
                ]);
            }

            $output->writeln('');
            $table->render();
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

