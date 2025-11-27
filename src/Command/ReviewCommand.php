<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Config\LockFile;
use Cortex\Docker\ContainerExecutor;
use Cortex\Docker\DockerCompose;
use Cortex\Output\OutputFormatter;
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
        private readonly ContainerExecutor $containerExecutor,
        private readonly DockerCompose $dockerCompose,
        private readonly LockFile $lockFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('review')
            ->setDescription('Review a ticket by checking out its branch and resetting the database')
            ->addArgument('ticket', InputArgument::REQUIRED, 'The ticket number to review');
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

            $formatter->section("Reviewing Ticket: $ticketNumber");

            // Step 1: Fetch from origin
            $formatter->info('Fetching latest changes from origin...');
            
            // First, check if remote uses SSH and convert to HTTPS if needed
            $remoteUrlProcess = $this->containerExecutor->exec(
                $composeFile,
                $primaryService,
                'git remote get-url origin',
                30,
                null,
                $namespace
            );

            $remoteUrl = '';
            if ($remoteUrlProcess->isSuccessful()) {
                $remoteUrl = trim($remoteUrlProcess->getOutput());
            }

            // If remote uses SSH, try to convert to HTTPS for this operation
            $fetchCommand = 'git fetch origin';
            $remoteChanged = false;
            if (str_starts_with($remoteUrl, 'git@') || str_starts_with($remoteUrl, 'ssh://')) {
                // Convert SSH URL to HTTPS
                // git@github.com:user/repo.git -> https://github.com/user/repo.git
                // ssh://git@github.com/user/repo.git -> https://github.com/user/repo.git
                $httpsUrl = $remoteUrl;
                
                // Remove ssh:// prefix if present
                $httpsUrl = preg_replace('/^ssh:\/\//', '', $httpsUrl);
                
                // Replace git@host: with https://host/
                $httpsUrl = preg_replace('/^git@([^:]+):(.+)$/', 'https://$1/$2', $httpsUrl);
                
                // Remove .git suffix if present
                $httpsUrl = preg_replace('/\.git$/', '', $httpsUrl);
                
                // Escape for shell
                $escapedHttpsUrl = escapeshellarg($httpsUrl);
                $escapedOriginalUrl = escapeshellarg($remoteUrl);
                
                // Temporarily set remote to HTTPS for fetch, then restore
                $fetchCommand = "git remote set-url origin $escapedHttpsUrl && git fetch origin && git remote set-url origin $escapedOriginalUrl";
                $remoteChanged = true;
                $formatter->info('Converting SSH remote to HTTPS for fetch...');
            }

            $fetchProcess = $this->containerExecutor->exec(
                $composeFile,
                $primaryService,
                $fetchCommand,
                60,
                null,
                $namespace
            );

            if (!$fetchProcess->isSuccessful()) {
                $errorOutput = $fetchProcess->getErrorOutput();
                if (str_contains($errorOutput, 'ssh') || str_contains($errorOutput, 'No such file or directory')) {
                    $formatter->error('Git remote is configured to use SSH, but SSH is not available in the container.');
                    $formatter->info('Please configure your git remote to use HTTPS, or ensure SSH is available in your Docker container.');
                    $formatter->info('You can change the remote URL with: git remote set-url origin <https-url>');
                } else {
                    $formatter->error('Failed to fetch from origin: ' . $errorOutput);
                }
                return Command::FAILURE;
            }

            // Step 2: Find branches containing ticket number
            $formatter->info('Searching for branches containing ticket number...');
            $escapedTicket = escapeshellarg($ticketNumber);
            $branchProcess = $this->containerExecutor->exec(
                $composeFile,
                $primaryService,
                "git branch -r | grep $escapedTicket",
                30,
                null,
                $namespace
            );

            if (!$branchProcess->isSuccessful()) {
                $formatter->error('Failed to search for branches: ' . $branchProcess->getErrorOutput());
                return Command::FAILURE;
            }

            $branchOutput = trim($branchProcess->getOutput());
            if (empty($branchOutput)) {
                $formatter->error("No branches found containing ticket number: $ticketNumber");
                return Command::FAILURE;
            }

            // Parse branches
            $branches = array_filter(
                array_map('trim', explode("\n", $branchOutput)),
                fn($branch) => !empty($branch)
            );

            // Remove 'origin/' prefix and get local branch names
            $branchNames = array_map(function ($branch) {
                return preg_replace('/^origin\//', '', $branch);
            }, $branches);

            // Step 3: Select branch (if multiple)
            $selectedBranch = $this->selectBranch(
                $input,
                $output,
                $formatter,
                $branchNames,
                $ticketNumber,
                $composeFile,
                $primaryService,
                $namespace
            );

            // Step 4: Checkout branch
            $formatter->info("Checking out branch: $selectedBranch");
            $escapedBranch = escapeshellarg($selectedBranch);
            // Try to checkout directly, if it fails create a tracking branch
            $checkoutProcess = $this->containerExecutor->exec(
                $composeFile,
                $primaryService,
                "git checkout $escapedBranch 2>/dev/null || git checkout -b $escapedBranch origin/$escapedBranch",
                60,
                null,
                $namespace
            );

            if (!$checkoutProcess->isSuccessful()) {
                $formatter->error('Failed to checkout branch: ' . $checkoutProcess->getErrorOutput());
                return Command::FAILURE;
            }

            // Step 5: Clear caches (if Laravel is present)
            if ($this->hasArtisan($composeFile, $primaryService, $namespace)) {
                $formatter->info('Clearing application caches...');
                $clearProcess = $this->containerExecutor->exec(
                    $composeFile,
                    $primaryService,
                    'php artisan optimize:clear',
                    120,
                    null,
                    $namespace
                );

                if (!$clearProcess->isSuccessful()) {
                    $formatter->error('Failed to clear caches: ' . $clearProcess->getErrorOutput());
                    return Command::FAILURE;
                }
            } else {
                $formatter->warning('Laravel artisan not found, skipping cache clear');
            }

            // Step 6: Reset database (if Laravel is present)
            if ($this->hasArtisan($composeFile, $primaryService, $namespace)) {
                $formatter->info('Resetting development database...');
                $migrateProcess = $this->containerExecutor->exec(
                    $composeFile,
                    $primaryService,
                    'php artisan migrate:fresh --seed',
                    300,
                    null,
                    $namespace
                );

                if (!$migrateProcess->isSuccessful()) {
                    $formatter->error('Failed to reset database: ' . $migrateProcess->getErrorOutput());
                    return Command::FAILURE;
                }
            } else {
                $formatter->warning('Laravel artisan not found, skipping database reset');
            }

            $formatter->success("✓ Successfully reviewed ticket $ticketNumber on branch $selectedBranch");
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
        string $composeFile,
        string $primaryService,
        ?string $namespace
    ): string {
        if (count($branches) === 1) {
            $formatter->info('Found single branch: ' . $branches[0]);
            return $branches[0];
        }

        // If multiple branches, find the most recent one
        $defaultBranch = $this->findMostRecentBranch($branches, $ticketNumber, $composeFile, $primaryService, $namespace);

        $formatter->info('Found ' . count($branches) . ' branches containing ticket number:');
        foreach ($branches as $branch) {
            $marker = ($branch === $defaultBranch) ? ' (most recent)' : '';
            $output->writeln('  <fg=#D2DCE5>- $branch$marker</>');
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

    /**
     * Find the most recent branch by checking commit dates
     */
    private function findMostRecentBranch(
        array $branches,
        string $ticketNumber,
        string $composeFile,
        string $primaryService,
        ?string $namespace
    ): string {
        if (empty($branches)) {
            throw new \RuntimeException('No branches found');
        }

        // Try to find the branch with the most recent commit
        $mostRecentBranch = null;
        $mostRecentDate = 0;

        foreach ($branches as $branch) {
            // Get the last commit date for this branch
            $dateProcess = $this->containerExecutor->exec(
                $composeFile,
                $primaryService,
                'git log -1 --format=%ct origin/' . escapeshellarg($branch),
                30,
                null,
                $namespace
            );

            if ($dateProcess->isSuccessful()) {
                $timestamp = (int) trim($dateProcess->getOutput());
                if ($timestamp > $mostRecentDate) {
                    $mostRecentDate = $timestamp;
                    $mostRecentBranch = $branch;
                }
            }
        }

        // If we couldn't determine the most recent, prefer branches starting with ticket number
        if ($mostRecentBranch === null) {
            foreach ($branches as $branch) {
                if (str_starts_with($branch, $ticketNumber)) {
                    return $branch;
                }
            }
            return $branches[0];
        }

        return $mostRecentBranch;
    }

    /**
     * Check if Laravel artisan file exists in the container
     */
    private function hasArtisan(string $composeFile, string $service, ?string $namespace): bool
    {
        // Check if artisan exists in current directory or common locations
        $checkProcess = $this->containerExecutor->exec(
            $composeFile,
            $service,
            '[ -f artisan ] || [ -f /var/www/html/artisan ] || [ -f /app/artisan ]',
            10,
            null,
            $namespace
        );

        return $checkProcess->isSuccessful();
    }
}

