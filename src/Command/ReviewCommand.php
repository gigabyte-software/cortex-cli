<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Config\LockFile;
use Cortex\Docker\DockerCompose;
use Cortex\Git\GitRepositoryService;
use Cortex\Laravel\LaravelService;
use Cortex\Orchestrator\CommandOrchestrator;
use Cortex\Output\OutputFormatter;
use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReviewCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly DockerCompose $dockerCompose,
        private readonly LockFile $lockFile,
        private readonly GitRepositoryService $gitRepositoryService,
        private readonly LaravelService $laravelService,
        private readonly CommandOrchestrator $commandOrchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('review')
            ->setDescription('Prepare the development environment for reviewing a ticket by checking out its branch and resetting the database')
            ->addArgument('ticket', InputArgument::REQUIRED, 'The ticket number to prepare for review')
            ->addOption('quick', null, InputOption::VALUE_NONE, 'Use the "clear" command instead of "fresh" (runs migrations without dropping tables)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);
        $ticketNumber = $input->getArgument('ticket');

        try {
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            $primaryService = $config->docker->primaryService;
            $composeFile = $config->docker->composeFile;

            $namespace = null;
            if ($this->lockFile->exists()) {
                $lockData = $this->lockFile->read();
                $namespace = $lockData?->namespace;
            }

            if (!$this->dockerCompose->isRunning($composeFile, $namespace)) {
                $formatter->error('Services are not running. Please run "cortex up" first.');
                return Command::FAILURE;
            }

            $formatter->section("Preparing for Ticket Review: $ticketNumber");

            $repositoryPath = dirname($configPath);

            $formatter->info('Fetching latest changes from origin...');
            if (!$this->gitRepositoryService->fetchFromOrigin($repositoryPath)) {
                $formatter->error('Failed to fetch from origin. Make sure you have git configured on your host machine and have access to the repository.');
                return Command::FAILURE;
            }

            $formatter->info('Searching for branches containing ticket number...');
            $branchNames = $this->gitRepositoryService->findBranchesContaining($repositoryPath, $ticketNumber);
            if (empty($branchNames)) {
                $formatter->error("No branches found containing ticket number: $ticketNumber");
                return Command::FAILURE;
            }

            $selectedBranch = $this->gitRepositoryService->selectBranch(
                $repositoryPath,
                $branchNames,
                $input,
                $output,
                fn(string $message) => $formatter->info($message),
                fn(string $message) => $formatter->warning($message),
                fn(string $branch) => str_starts_with($branch, $ticketNumber)
            );

            $formatter->info("Checking out branch: $selectedBranch");
            if (!$this->gitRepositoryService->checkoutBranch($repositoryPath, $selectedBranch)) {
                $formatter->error('Failed to checkout branch');
                return Command::FAILURE;
            }

            $resetCommand = $input->getOption('quick') ? 'clear' : 'fresh';

            if (isset($config->commands[$resetCommand]) && trim($config->commands[$resetCommand]->command) !== '') {
                $formatter->section("Running $resetCommand");
                $this->commandOrchestrator->run($resetCommand, $config);
            } else {
                $formatter->warning("Command '$resetCommand' is not defined in cortex.yml — falling back to default Laravel reset");

                if (!$this->laravelService->hasArtisan($composeFile, $primaryService, $namespace)) {
                    $formatter->warning('Laravel artisan not found, skipping environment reset');
                } else {
                    $formatter->info('Clearing application caches...');
                    if (!$this->laravelService->clearCaches($composeFile, $primaryService, $namespace)) {
                        $formatter->error('Failed to clear caches');
                        return Command::FAILURE;
                    }

                    $formatter->info('Resetting development database...');
                    if (!$this->laravelService->resetDatabase($composeFile, $primaryService, $namespace)) {
                        $formatter->error('Failed to reset database');
                        return Command::FAILURE;
                    }
                }
            }

            $formatter->success("✓ Successfully prepared for ticket $ticketNumber review on branch $selectedBranch");

            $this->displayCompletionUrls($repositoryPath, $ticketNumber, $formatter);

            $output->writeln('');

            return Command::SUCCESS;
        } catch (ConfigException $e) {
            $formatter->error("Configuration error: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (Exception $e) {
            $formatter->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Look for a @COMPLETION.md (case-insensitive) in .cortex/tickets/<ticket>/ and display any URLs found.
     */
    private function displayCompletionUrls(string $repositoryPath, string $ticketNumber, OutputFormatter $formatter): void
    {
        $ticketDir = $this->findTicketDirectory($repositoryPath, $ticketNumber);

        if ($ticketDir === null) {
            return;
        }

        $completionFile = $this->findCompletionFile($ticketDir);

        if ($completionFile === null) {
            return;
        }

        $contents = file_get_contents($completionFile);
        if ($contents === false) {
            return;
        }

        $urls = $this->parseCompletionUrls($contents);

        if ($urls === []) {
            return;
        }

        $formatter->getOutput()->writeln('');
        foreach ($urls as $label => $url) {
            $formatter->url($label, $url);
        }
    }

    /**
     * Case-insensitive search for the ticket folder inside .cortex/tickets/.
     */
    private function findTicketDirectory(string $repositoryPath, string $ticketNumber): ?string
    {
        $ticketsDir = $repositoryPath . '/.cortex/tickets';

        if (!is_dir($ticketsDir)) {
            return null;
        }

        $entries = scandir($ticketsDir);
        if ($entries === false) {
            return null;
        }

        foreach ($entries as $entry) {
            if (strcasecmp($entry, $ticketNumber) === 0) {
                $path = $ticketsDir . '/' . $entry;
                if (is_dir($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Case-insensitive search for @COMPLETION.md or @completion.md (and any mix).
     */
    private function findCompletionFile(string $ticketDir): ?string
    {
        $files = scandir($ticketDir);
        if ($files === false) {
            return null;
        }

        foreach ($files as $file) {
            if (strcasecmp($file, '@COMPLETION.md') === 0) {
                return $ticketDir . '/' . $file;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> label => URL
     */
    private function parseCompletionUrls(string $contents): array
    {
        $urls = [];

        if (preg_match_all('/^-\s*(.+?):\s*(https?:\/\/\S+)/m', $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $urls[trim($match[1])] = trim($match[2]);
            }
        }

        return $urls;
    }
}

