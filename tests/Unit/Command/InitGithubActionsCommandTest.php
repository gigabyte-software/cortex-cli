<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Cortex\Command\InitGithubActionsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class InitGithubActionsCommandTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/cortex_init_gha_' . uniqid();
        mkdir($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
        parent::tearDown();
    }

    public function test_writes_three_workflow_files(): void
    {
        $originalDir = getcwd();
        chdir($this->testDir);

        try {
            $app = new Application();
            $app->add(new InitGithubActionsCommand());
            $command = $app->find('init-github-actions');
            $tester = new CommandTester($command);
            $tester->execute([
                '--repo' => 'acme/shared-workflows',
                '--ref' => 'v1',
                '--ci-workflow-name' => 'CI',
                '--base-branch' => 'develop',
            ]);

            $this->assertSame(0, $tester->getStatusCode());
            $this->assertFileExists($this->testDir . '/.github/workflows/claude-auto-fix.yml');
            $this->assertFileExists($this->testDir . '/.github/workflows/claude-auto-rebase.yml');
            $this->assertFileExists($this->testDir . '/.github/workflows/claude-fix-review-comments.yml');

            $fix = file_get_contents($this->testDir . '/.github/workflows/claude-auto-fix.yml');
            $this->assertIsString($fix);
            $this->assertStringContainsString('acme/shared-workflows/.github/workflows/claude-auto-fix.yml@v1', $fix);
            $this->assertStringContainsString('workflows: ["CI"]', $fix);

            $rebase = file_get_contents($this->testDir . '/.github/workflows/claude-auto-rebase.yml');
            $this->assertIsString($rebase);
            $this->assertStringContainsString("branches: ['develop']", $rebase);
            $this->assertStringContainsString("base-branch: 'develop'", $rebase);
        } finally {
            if ($originalDir !== false) {
                chdir($originalDir);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
