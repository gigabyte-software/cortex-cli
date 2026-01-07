<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Config\LockFile;
use Cortex\Docker\PortOffsetManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowUrlCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly LockFile $lockFile,
        private readonly PortOffsetManager $portOffsetManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('show-url')
            ->setDescription('Display the URL for the development environment');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Load configuration
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            // Get base URL from config
            $appUrl = $config->docker->appUrl;

            // Get port offset from lock file (0 if not running with offset)
            $portOffset = 0;
            if ($this->lockFile->exists()) {
                $lockData = $this->lockFile->read();
                $portOffset = $lockData?->portOffset ?? 0;
            }

            // Get the primary service's base port
            $basePort = $this->portOffsetManager->getPrimaryServicePort(
                $config->docker->composeFile,
                $config->docker->primaryService
            );

            // Build the URL
            if ($basePort !== null) {
                $finalPort = $basePort + $portOffset;
                // Parse URL and replace/add port
                $url = $this->buildUrlWithPort($appUrl, $finalPort);
            } else {
                // No port exposed, use app_url as-is
                $url = $appUrl;
            }

            // Output plain URL for easy piping
            $output->writeln($url);

            return Command::SUCCESS;
        } catch (ConfigException $e) {
            $output->writeln("<error>Configuration error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    /**
     * Build URL with the specified port
     */
    private function buildUrlWithPort(string $baseUrl, int $port): string
    {
        $parsed = parse_url($baseUrl);

        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? 'localhost';
        $path = $parsed['path'] ?? '';

        return "{$scheme}://{$host}:{$port}{$path}";
    }
}

