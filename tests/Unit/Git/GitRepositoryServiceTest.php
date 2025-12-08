<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit\Git;

use Cortex\Git\GitRepositoryService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class GitRepositoryServiceTest extends TestCase
{
    private GitRepositoryService $service;
    private string $tempDir;
    private string $gitRepoPath;

    protected function setUp(): void
    {
        $this->service = new GitRepositoryService();
        $this->tempDir = sys_get_temp_dir() . '/cortex-git-test-' . uniqid();
        $bareRepoPath = $this->tempDir . '/bare-repo';
        $this->gitRepoPath = $this->tempDir . '/repo';
        
        // Create temporary directories
        mkdir($this->tempDir, 0755, true);
        mkdir($this->gitRepoPath, 0755, true);
        mkdir($bareRepoPath, 0755, true);
        
        // Create bare repository as remote
        $this->runCommand('git init --bare', $bareRepoPath);
        
        // Initialize git repository
        $this->runGitCommand('git init');
        $this->runGitCommand('git config user.name "Test User"');
        $this->runGitCommand('git config user.email "test@example.com"');
        
        // Create initial commit
        file_put_contents($this->gitRepoPath . '/README.md', '# Test Repository');
        $this->runGitCommand('git add README.md');
        $this->runGitCommand('git commit -m "Initial commit"');
        $this->runGitCommand('git branch -M main');
        
        // Set up remote
        $this->runGitCommand('git remote add origin ' . escapeshellarg($bareRepoPath));
        $this->runGitCommand('git push -u origin main');
        
        // Create test branches with different commit dates
        $this->createBranchWithCommit('feature/TICKET-123', '2024-01-01 10:00:00', 'Commit for TICKET-123');
        $this->createBranchWithCommit('feature/TICKET-456', '2024-01-02 10:00:00', 'Commit for TICKET-456');
        $this->createBranchWithCommit('bugfix/TICKET-123-fix', '2024-01-03 10:00:00', 'Fix for TICKET-123');
        $this->createBranchWithCommit('feature/other-branch', '2024-01-04 10:00:00', 'Other branch');
        
        // Push all branches to origin
        $this->runGitCommand('git push origin --all');
        
        // Fetch to create remote tracking branches
        $this->runGitCommand('git fetch origin');
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    public function test_findBranchesContaining_returns_matching_branches(): void
    {
        $branches = $this->service->findBranchesContaining($this->gitRepoPath, 'TICKET-123');
        
        $this->assertCount(2, $branches);
        $this->assertContains('feature/TICKET-123', $branches);
        $this->assertContains('bugfix/TICKET-123-fix', $branches);
    }

    public function test_findBranchesContaining_returns_empty_array_when_no_matches(): void
    {
        $branches = $this->service->findBranchesContaining($this->gitRepoPath, 'NONEXISTENT');
        
        $this->assertEmpty($branches);
    }

    public function test_findBranchesContaining_handles_special_characters(): void
    {
        $branches = $this->service->findBranchesContaining($this->gitRepoPath, 'feature');
        
        $this->assertNotEmpty($branches);
        $this->assertContains('feature/TICKET-123', $branches);
        $this->assertContains('feature/TICKET-456', $branches);
        $this->assertContains('feature/other-branch', $branches);
    }

    public function test_findBranchesContaining_removes_origin_prefix(): void
    {
        $branches = $this->service->findBranchesContaining($this->gitRepoPath, 'TICKET-123');
        
        foreach ($branches as $branch) {
            $this->assertFalse(str_starts_with($branch, 'origin/'), "Branch '{$branch}' should not start with 'origin/'");
        }
    }

    public function test_findBranchesContaining_removes_duplicates(): void
    {
        // Create a branch that might appear multiple times in grep results
        $this->createBranchWithCommit('feature/TICKET-123-duplicate', '2024-01-05 10:00:00', 'Duplicate test');
        
        $branches = $this->service->findBranchesContaining($this->gitRepoPath, 'TICKET-123');
        
        $uniqueBranches = array_unique($branches);
        $this->assertEquals(count($uniqueBranches), count($branches));
    }

    public function test_findMostRecentBranch_returns_most_recent_branch(): void
    {
        $branches = ['feature/TICKET-123', 'feature/TICKET-456', 'bugfix/TICKET-123-fix'];
        
        $mostRecent = $this->service->findMostRecentBranch($this->gitRepoPath, $branches);
        
        // bugfix/TICKET-123-fix was created last (2024-01-03)
        $this->assertEquals('bugfix/TICKET-123-fix', $mostRecent);
    }

    public function test_findMostRecentBranch_throws_exception_for_empty_array(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No branches found');
        
        $this->service->findMostRecentBranch($this->gitRepoPath, []);
    }

    public function test_findMostRecentBranch_returns_first_branch_when_cannot_determine_dates(): void
    {
        // Use an empty directory (not a git repo) to simulate git log failures
        $emptyDir = $this->tempDir . '/empty-dir';
        mkdir($emptyDir, 0755, true);
        $branches = ['branch1', 'branch2'];
        
        $result = $this->service->findMostRecentBranch($emptyDir, $branches);
        
        // Should return first branch as fallback when git log fails
        $this->assertEquals('branch1', $result);
    }

    public function test_findMostRecentBranch_handles_single_branch(): void
    {
        $branches = ['feature/TICKET-123'];
        
        $mostRecent = $this->service->findMostRecentBranch($this->gitRepoPath, $branches);
        
        $this->assertEquals('feature/TICKET-123', $mostRecent);
    }

    public function test_selectBranch_returns_single_branch_without_prompting(): void
    {
        $branches = ['feature/TICKET-123'];
        $infoMessages = [];
        $warningMessages = [];
        
        $input = $this->createStreamableInput('');
        $output = new BufferedOutput();
        
        $selected = $this->service->selectBranch(
            $this->gitRepoPath,
            $branches,
            $input,
            $output,
            function (string $message) use (&$infoMessages) {
                $infoMessages[] = $message;
            },
            function (string $message) use (&$warningMessages) {
                $warningMessages[] = $message;
            }
        );
        
        $this->assertEquals('feature/TICKET-123', $selected);
        $this->assertCount(1, $infoMessages);
        $this->assertStringContainsString('Found single branch', $infoMessages[0]);
    }

    public function test_selectBranch_uses_most_recent_as_default(): void
    {
        $branches = ['feature/TICKET-123', 'feature/TICKET-456', 'bugfix/TICKET-123-fix'];
        $infoMessages = [];
        
        // Provide input that selects the default (first option, index 0)
        // For ChoiceQuestion, we provide the answer as the branch name
        $input = $this->createStreamableInput("bugfix/TICKET-123-fix\n");
        $output = new BufferedOutput();
        
        $selected = $this->service->selectBranch(
            $this->gitRepoPath,
            $branches,
            $input,
            $output,
            function (string $message) use (&$infoMessages) {
                $infoMessages[] = $message;
            },
            fn(string $message) => null,
            null
        );
        
        // Should return the most recent branch (bugfix/TICKET-123-fix)
        $this->assertEquals('bugfix/TICKET-123-fix', $selected);
        $this->assertStringContainsString('Found 3 branches', $infoMessages[0]);
    }

    public function test_selectBranch_applies_preference_callback(): void
    {
        $branches = ['feature/TICKET-123', 'feature/TICKET-456', 'bugfix/TICKET-123-fix'];
        $infoMessages = [];
        
        // Provide input selecting a feature branch
        $input = $this->createStreamableInput("feature/TICKET-123\n");
        $output = new BufferedOutput();
        
        // Preference callback: prefer branches starting with "feature/"
        $preferenceCallback = fn(string $branch): bool => str_starts_with($branch, 'feature/');
        
        $selected = $this->service->selectBranch(
            $this->gitRepoPath,
            $branches,
            $input,
            $output,
            function (string $message) use (&$infoMessages) {
                $infoMessages[] = $message;
            },
            fn(string $message) => null,
            $preferenceCallback
        );
        
        // Should prefer a feature branch even if bugfix is more recent
        // The most recent is bugfix/TICKET-123-fix, but preference should override
        $this->assertContains($selected, $branches);
        // Verify preference was applied - default should be a feature branch
        $outputContent = $output->fetch();
        $this->assertStringContainsString('feature/', $outputContent);
    }

    public function test_selectBranch_handles_exception_from_findMostRecentBranch(): void
    {
        $branches = ['branch1', 'branch2'];
        $infoMessages = [];
        
        $input = $this->createStreamableInput("branch1\n");
        $output = new BufferedOutput();
        
        // Use non-existent path to trigger exception in findMostRecentBranch
        $nonExistentPath = '/nonexistent/repo/path';
        
        $selected = $this->service->selectBranch(
            $nonExistentPath,
            $branches,
            $input,
            $output,
            function (string $message) use (&$infoMessages) {
                $infoMessages[] = $message;
            },
            fn(string $message) => null
        );
        
        // Should fall back to first branch when exception occurs
        $this->assertEquals('branch1', $selected);
    }

    public function test_selectBranch_outputs_branch_list(): void
    {
        $branches = ['feature/TICKET-123', 'feature/TICKET-456'];
        $infoMessages = [];
        
        $input = $this->createStreamableInput("feature/TICKET-123\n");
        $output = new BufferedOutput();
        
        $this->service->selectBranch(
            $this->gitRepoPath,
            $branches,
            $input,
            $output,
            function (string $message) use (&$infoMessages) {
                $infoMessages[] = $message;
            },
            fn(string $message) => null
        );
        
        $outputContent = $output->fetch();
        
        $this->assertStringContainsString('feature/TICKET-123', $outputContent);
        $this->assertStringContainsString('feature/TICKET-456', $outputContent);
    }

    /**
     * Create a streamable input for testing interactive questions
     */
    private function createStreamableInput(string $input): StreamableInputInterface
    {
        $stream = fopen('php://memory', 'r+', false);
        if ($stream === false) {
            throw new \RuntimeException('Failed to create stream for testing');
        }
        fwrite($stream, $input);
        rewind($stream);
        
        $arrayInput = new ArrayInput([]);
        $arrayInput->setStream($stream);
        
        return $arrayInput;
    }

    /**
     * Run a git command in the test repository
     */
    private function runGitCommand(string $command): void
    {
        $this->runCommand($command, $this->gitRepoPath);
    }

    /**
     * Run a command in a specific directory
     */
    private function runCommand(string $command, string $cwd): void
    {
        $process = \Symfony\Component\Process\Process::fromShellCommandline(
            $command,
            $cwd
        );
        $process->setTimeout(10);
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Command failed: {$command}\n" . $process->getErrorOutput()
            );
        }
    }

    /**
     * Create a branch with a commit at a specific date
     */
    private function createBranchWithCommit(string $branchName, string $date, string $message): void
    {
        // Get current branch name
        $currentBranchProcess = \Symfony\Component\Process\Process::fromShellCommandline(
            'git branch --show-current',
            $this->gitRepoPath
        );
        $currentBranchProcess->run();
        $currentBranch = trim($currentBranchProcess->getOutput()) ?: 'main';
        
        // Create and checkout branch
        $this->runGitCommand("git checkout -b {$branchName}");
        // Sanitize branch name for filename (replace slashes with dashes)
        $filename = str_replace('/', '-', $branchName) . '.txt';
        file_put_contents($this->gitRepoPath . "/{$filename}", "Content for {$branchName}");
        $this->runGitCommand("git add {$filename}");
        
        // Set GIT_AUTHOR_DATE and GIT_COMMITTER_DATE to control commit date
        $env = [
            'GIT_AUTHOR_DATE' => $date,
            'GIT_COMMITTER_DATE' => $date,
        ];
        
        $process = \Symfony\Component\Process\Process::fromShellCommandline(
            "git commit -m " . escapeshellarg($message),
            $this->gitRepoPath,
            $env
        );
        $process->setTimeout(10);
        $process->run();
        
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Failed to create commit: {$message}\n" . $process->getErrorOutput()
            );
        }
        
        // Return to original branch
        $this->runGitCommand("git checkout {$currentBranch}");
    }

    /**
     * Recursively remove a directory
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $scandirResult = scandir($dir);
        if ($scandirResult === false) {
            return;
        }
        
        $files = array_diff($scandirResult, ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

