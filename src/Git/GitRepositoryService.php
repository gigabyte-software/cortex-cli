<?php

declare(strict_types=1);

namespace Cortex\Git;

use RuntimeException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Process\Process;

class GitRepositoryService
{
    /**
     * Fetch from origin, pruning remote-tracking refs for branches that no longer exist
     * on the remote so the candidate branch list stays in sync with origin.
     */
    public function fetchFromOrigin(string $repositoryPath): bool
    {
        $fetchProcess = new Process(['git', 'fetch', '--prune', 'origin'], $repositoryPath);
        $fetchProcess->setTimeout(60);
        $fetchProcess->run();

        return $fetchProcess->isSuccessful();
    }

    /**
     * Find branches containing a search string
     *
     * @return array<string> Array of branch names (without origin/ prefix)
     */
    public function findBranchesContaining(string $repositoryPath, string $searchString): array
    {
        $escapedSearch = escapeshellarg($searchString);

        $branchProcess = Process::fromShellCommandline("git branch -r | grep $escapedSearch", $repositoryPath);
        $branchProcess->setTimeout(30);
        $branchProcess->run();

        if (!$branchProcess->isSuccessful()) {
            return [];
        }

        $branchOutput = trim($branchProcess->getOutput());
        if (empty($branchOutput)) {
            return [];
        }

        $branchLines = explode("\n", $branchOutput);
        $branches = array_filter(
            array_map('trim', $branchLines),
            fn ($branch): bool => is_string($branch) && $branch !== '' && !str_contains($branch, 'HEAD ->')
        );

        /** @var array<string> $branchNames */
        $branchNames = [];
        foreach ($branches as $branch) {
            $branchName = preg_replace('/^origin\//', '', $branch) ?? $branch;
            if (str_contains($branchName, '->')) {
                $parts = explode('->', $branchName);
                $branchName = trim(end($parts));
                $branchName = preg_replace('/^origin\//', '', $branchName) ?? $branchName;
            }
            if ($branchName !== '') {
                $branchNames[] = $branchName;
            }
        }

        return array_values(array_unique($branchNames));
    }

    /**
     * Checkout the specified branch, fast-forwarding from origin if it already exists locally.
     *
     * If the local branch exists, it is checked out and fast-forward merged from origin/<branch>
     * so a re-run of `cortex review` picks up new commits pushed since the last checkout. If the
     * local branch has diverged (local-only commits), the fast-forward fails and this returns
     * false so the caller can surface an error rather than silently serve stale code.
     */
    public function checkoutBranch(string $repositoryPath, string $branch): bool
    {
        $escapedBranch = escapeshellarg($branch);

        $existsProcess = Process::fromShellCommandline(
            "git show-ref --verify --quiet refs/heads/$escapedBranch",
            $repositoryPath
        );
        $existsProcess->setTimeout(10);
        $existsProcess->run();

        if ($existsProcess->isSuccessful()) {
            $command = "git checkout $escapedBranch && git merge --ff-only origin/$escapedBranch";
        } else {
            $command = "git checkout -b $escapedBranch origin/$escapedBranch";
        }

        $checkoutProcess = Process::fromShellCommandline($command, $repositoryPath);
        $checkoutProcess->setTimeout(60);
        $checkoutProcess->run();

        return $checkoutProcess->isSuccessful();
    }

    /**
     * Find the most recent branch by checking commit dates
     *
     * @param array<string> $branches
     * @throws RuntimeException If no branches provided
     */
    public function findMostRecentBranch(string $repositoryPath, array $branches): string
    {
        if (empty($branches)) {
            throw new RuntimeException('No branches found');
        }

        $mostRecentBranch = null;
        $mostRecentDate = 0;

        foreach ($branches as $branch) {
            $dateProcess = new Process(['git', 'log', '-1', '--format=%ct', "origin/$branch"], $repositoryPath);
            $dateProcess->setTimeout(30);
            $dateProcess->run();

            if ($dateProcess->isSuccessful()) {
                $timestamp = (int) trim($dateProcess->getOutput());
                if ($timestamp > $mostRecentDate) {
                    $mostRecentDate = $timestamp;
                    $mostRecentBranch = $branch;
                }
            }
        }

        if ($mostRecentBranch === null) {
            return $branches[0];
        }

        return $mostRecentBranch;
    }

    /**
     * Select a branch from the list, defaulting to the most recent one
     *
     * @param array<string> $branches
     * @param callable(string): bool $preferenceCallback Optional callback to prefer certain branches (returns true if branch should be preferred)
     */
    public function selectBranch(
        string $repositoryPath,
        array $branches,
        InputInterface $input,
        OutputInterface $output,
        callable $infoCallback,
        callable $warningCallback,
        ?callable $preferenceCallback = null
    ): string {
        if (count($branches) === 1) {
            $infoCallback('Found single branch: ' . $branches[0]);
            return $branches[0];
        }

        // If multiple branches, find the most recent one
        try {
            $defaultBranch = $this->findMostRecentBranch($repositoryPath, $branches);
        } catch (RuntimeException $e) {
            $defaultBranch = $branches[0];
        }

        // Apply preference callback if provided
        if ($preferenceCallback !== null && !$preferenceCallback($defaultBranch)) {
            foreach ($branches as $branch) {
                if ($preferenceCallback($branch)) {
                    $defaultBranch = $branch;
                    break;
                }
            }
        }

        $infoCallback('Found ' . count($branches) . ' branches:');
        foreach ($branches as $branch) {
            $marker = ($branch === $defaultBranch) ? ' (most recent)' : '';
            $output->writeln('  <fg=#D2DCE5>- ' . $branch . $marker . '</>');
        }

        $question = new ChoiceQuestion(
            'Select a branch to checkout:',
            $branches,
            $defaultBranch
        );
        $question->setNormalizer(fn ($value) => $value);

        $helper = new QuestionHelper();
        $selected = $helper->ask($input, $output, $question);

        return $selected ?? $defaultBranch;
    }
}
