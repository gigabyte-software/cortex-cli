<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Config\LockFile;
use Cortex\Docker\DockerCompose;
use Cortex\Git\GitRepositoryService;
use Cortex\Laravel\LaravelService;
use Cortex\Output\OutputFormatter;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

class ReviewCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly DockerCompose $dockerCompose,
        private readonly LockFile $lockFile,
        private readonly GitRepositoryService $gitRepositoryService,
        private readonly LaravelService $laravelService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('review')
            ->setDescription('Prepare the development environment for reviewing a ticket by checking out its branch and resetting the database')
            ->addArgument('ticket', InputArgument::REQUIRED, 'The ticket number to prepare for review');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);
        $ticketNumber = $input->getArgument('ticket');

        try {
            // Load configuration
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            $primaryService = $config->docker->primaryService;
            $composeFile = $config->docker->composeFile;

            // Read lock file to get namespace
            $namespace = null;
            if ($this->lockFile->exists()) {
                $lockData = $this->lockFile->read();
                $namespace = $lockData?->namespace;
            }

            // Check if services are running
            if (!$this->dockerCompose->isRunning($composeFile, $namespace)) {
                $formatter->error('Services are not running. Please run "cortex up" first.');
                return Command::FAILURE;
            }

            $formatter->section("Preparing for Ticket Review: $ticketNumber");

            $repositoryPath = dirname($configPath);

            // Step 1: Fetch from origin
            $formatter->info('Fetching latest changes from origin...');
            if (!$this->gitRepositoryService->fetchFromOrigin($repositoryPath)) {
                $formatter->error('Failed to fetch from origin. Make sure you have git configured on your host machine and have access to the repository.');
                return Command::FAILURE;
            }

            // Step 2: Find branches containing ticket number
            $formatter->info('Searching for branches containing ticket number...');
            $branchNames = $this->gitRepositoryService->findBranchesContaining($repositoryPath, $ticketNumber);
            if (empty($branchNames)) {
                $formatter->error("No branches found containing ticket number: $ticketNumber");
                return Command::FAILURE;
            }

            // Step 3: Select branch (if multiple)
            $selectedBranch = $this->selectBranch(
                $input,
                $output,
                $formatter,
                $branchNames,
                $ticketNumber,
                $repositoryPath
            );

            // Step 4: Checkout branch
            $formatter->info("Checking out branch: $selectedBranch");
            if (!$this->gitRepositoryService->checkoutBranch($repositoryPath, $selectedBranch)) {
                $formatter->error('Failed to checkout branch');
                return Command::FAILURE;
            }

            // Step 5: Clear caches (if Laravel is present)
            if (!$this->laravelService->hasArtisan($composeFile, $primaryService, $namespace)) {
                $formatter->warning('Laravel artisan not found, skipping cache clear');
            } else {
                $formatter->info('Clearing application caches...');
                if (!$this->laravelService->clearCaches($composeFile, $primaryService, $namespace)) {
                    $formatter->error('Failed to clear caches');
                    return Command::FAILURE;
                }
            }

            // Step 6: Reset database (if Laravel is present)
            if (!$this->laravelService->hasArtisan($composeFile, $primaryService, $namespace)) {
                $formatter->warning('Laravel artisan not found, skipping database reset');
            } else {
                $formatter->info('Resetting development database...');
                if (!$this->laravelService->resetDatabase($composeFile, $primaryService, $namespace)) {
                    $formatter->error('Failed to reset database');
                    return Command::FAILURE;
                }
            }

            $formatter->success("✓ Successfully prepared for ticket $ticketNumber review on branch $selectedBranch");
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


    /**
     * Select a branch from the list, defaulting to the most recent one
     */
    private function selectBranch(
        InputInterface $input,
        OutputInterface $output,
        OutputFormatter $formatter,
        array $branches,
        string $ticketNumber,
        string $repositoryPath
    ): string {
        if (count($branches) === 1) {
            $formatter->info('Found single branch: ' . $branches[0]);
            return $branches[0];
        }

        // If multiple branches, find the most recent one
        try {
            $defaultBranch = $this->gitRepositoryService->findMostRecentBranch($repositoryPath, $branches);
        } catch (RuntimeException $e) {
            $defaultBranch = $branches[0];
        }

        // Prefer branches starting with ticket number if the most recent doesn't
        if (!str_starts_with($defaultBranch, $ticketNumber)) {
            foreach ($branches as $branch) {
                if (str_starts_with($branch, $ticketNumber)) {
                    $defaultBranch = $branch;
                    break;
                }
            }
        }

        $formatter->info('Found ' . count($branches) . ' branches containing ticket number:');
        foreach ($branches as $branch) {
            $marker = ($branch === $defaultBranch) ? ' (most recent)' : '';
            $output->writeln('  <fg=#D2DCE5>- ' . $branch . $marker . '</>');
        }

        $question = new ChoiceQuestion(
            'Select a branch to checkout:',
            $branches,
            $defaultBranch
        );
        $question->setNormalizer(fn($value) => $value);

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $selected = $helper->ask($input, $output, $question);

        return $selected ?? $defaultBranch;
    }
}

