<?php

declare(strict_types=1);

namespace Cortex\Git;

use RuntimeException;
use Symfony\Component\Process\Process;

final class GitRepositoryService
{
    /**
     * Fetch from origin
     */
    public function fetchFromOrigin(string $repositoryPath): bool
    {
        $fetchProcess = new Process(['git', 'fetch', 'origin'], $repositoryPath);
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

        $branches = array_filter(
            array_map('trim', explode("\n", $branchOutput)),
            fn($branch) => !empty($branch) && !str_contains($branch, 'HEAD ->')
        );

        $branchNames = array_map(function ($branch) {
            $branch = preg_replace('/^origin\//', '', $branch);
            if (str_contains($branch, '->')) {
                $parts = explode('->', $branch);
                $branch = trim(end($parts));
                $branch = preg_replace('/^origin\//', '', $branch);
            }
            return $branch;
        }, $branches);
        
        return array_values(array_unique($branchNames));
    }

    /**
     * Checkout the specified branch
     */
    public function checkoutBranch(string $repositoryPath, string $branch): bool
    {
        $escapedBranch = escapeshellarg($branch);
        
        $checkoutProcess = Process::fromShellCommandline(
            "git checkout $escapedBranch 2>/dev/null || git checkout -b $escapedBranch origin/$escapedBranch",
            $repositoryPath
        );
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
}

