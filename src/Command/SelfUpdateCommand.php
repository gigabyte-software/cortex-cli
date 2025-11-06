<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SelfUpdateCommand extends Command
{
    private const GITHUB_REPO = 'gigabyte-software/cortex-cli';
    private const GITHUB_API_URL = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
    private const GITHUB_DOWNLOAD_URL = 'https://github.com/' . self::GITHUB_REPO . '/releases/latest/download/cortex.phar';

    protected function configure(): void
    {
        $this
            ->setName('self-update')
            ->setDescription('Update Cortex CLI to the latest version')
            ->setAliases(['selfupdate', 'update'])
            ->addOption('check', null, InputOption::VALUE_NONE, 'Check for updates without installing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force update even if already on latest version');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        // Check if running as PHAR
        $pharPath = \Phar::running(false);
        if (empty($pharPath)) {
            $formatter->error('Self-update is only available when running as PHAR.');
            $formatter->info('You are running from source. Use git to update:');
            $formatter->info('  git pull origin main');
            $formatter->info('  composer install');
            return Command::FAILURE;
        }

        // Check if we have write permissions
        if (!is_writable($pharPath)) {
            $formatter->error("Cannot update: No write permission to $pharPath");
            $formatter->info('Try running with sudo:');
            $formatter->info('  sudo cortex self-update');
            return Command::FAILURE;
        }

        $checkOnly = $input->getOption('check');
        $force = $input->getOption('force');

        try {
            $formatter->section('Checking for updates');

            // Get current version
            $application = $this->getApplication();
            if ($application === null) {
                throw new \RuntimeException('Application not set');
            }
            $currentVersion = $application->getVersion();
            $formatter->info("Current version: $currentVersion");

            // Fetch latest release info from GitHub
            $latestVersion = $this->getLatestVersion();
            $formatter->info("Latest version: $latestVersion");

            // Compare versions
            if (!$force && version_compare($currentVersion, $latestVersion, '>=')) {
                $formatter->success('✓ Already running the latest version');
                return Command::SUCCESS;
            }

            if ($checkOnly) {
                if (version_compare($currentVersion, $latestVersion, '<')) {
                    $formatter->warning("Update available: $currentVersion → $latestVersion");
                    $formatter->info('Run without --check to install the update');
                }
                return Command::SUCCESS;
            }

            // Download and install update
            $formatter->section('Downloading update');
            $tempFile = $this->downloadLatestVersion();

            $formatter->section('Installing update');
            $this->installUpdate($tempFile, $pharPath);

            // Clean up
            @unlink($tempFile);

            $formatter->success("✓ Successfully updated to version $latestVersion");
            $formatter->info('');
            $formatter->info('Run "cortex --version" to verify');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $formatter->error("Update failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function getLatestVersion(): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: Cortex-CLI',
                    'Accept: application/json',
                ],
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents(self::GITHUB_API_URL, false, $context);

        if ($response === false) {
            throw new \RuntimeException('Failed to fetch release information from GitHub');
        }

        $data = json_decode($response, true);

        if (!isset($data['tag_name'])) {
            throw new \RuntimeException('Invalid response from GitHub API');
        }

        // Remove 'v' prefix if present
        return ltrim($data['tag_name'], 'v');
    }

    private function downloadLatestVersion(): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'cortex_update_');

        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'User-Agent: Cortex-CLI',
                'timeout' => 60,
                'follow_location' => 1,
            ],
        ]);

        $content = @file_get_contents(self::GITHUB_DOWNLOAD_URL, false, $context);

        if ($content === false) {
            @unlink($tempFile);
            throw new \RuntimeException('Failed to download latest version from GitHub');
        }

        if (file_put_contents($tempFile, $content) === false) {
            @unlink($tempFile);
            throw new \RuntimeException('Failed to save downloaded file');
        }

        // Verify it's a valid PHAR
        try {
            new \Phar($tempFile);
        } catch (\Exception $e) {
            @unlink($tempFile);
            throw new \RuntimeException('Downloaded file is not a valid PHAR');
        }

        return $tempFile;
    }

    private function installUpdate(string $tempFile, string $pharPath): void
    {
        // Create backup
        $backupPath = $pharPath . '.backup';
        if (!@copy($pharPath, $backupPath)) {
            throw new \RuntimeException('Failed to create backup');
        }

        // Replace with new version
        if (!@rename($tempFile, $pharPath)) {
            // Restore backup on failure
            @rename($backupPath, $pharPath);
            throw new \RuntimeException('Failed to install update');
        }

        // Make executable
        @chmod($pharPath, 0755);

        // Remove backup on success
        @unlink($backupPath);
    }
}
