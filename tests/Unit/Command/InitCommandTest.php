<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Cortex\Command\InitCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class InitCommandTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary directory for testing
        $this->testDir = sys_get_temp_dir() . '/cortex_init_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test directory
        if (is_dir($this->testDir)) {
            $this->recursiveRemoveDirectory($this->testDir);
        }
    }

    public function testInitCreatesDirectoryStructure(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());
        $this->assertDirectoryExists($this->testDir . '/.cortex');
        $this->assertDirectoryExists($this->testDir . '/.cortex/tickets');
        $this->assertDirectoryExists($this->testDir . '/.cortex/specs');
        $this->assertDirectoryExists($this->testDir . '/.cortex/meetings');
    }

    public function testInitCreatesGitkeep(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertFileExists($this->testDir . '/.cortex/tickets/.gitkeep');
    }

    public function testInitCreatesReadme(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertFileExists($this->testDir . '/.cortex/README.md');

        $content = file_get_contents($this->testDir . '/.cortex/README.md');
        $this->assertIsString($content, 'Failed to read README.md');
        assert(is_string($content)); // Type narrowing for PHPStan
        $this->assertStringContainsString('# .cortex Folder', $content);
        $this->assertStringContainsString('Core Principle', $content);
    }


    public function testInitCreatesCortexYml(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertFileExists($this->testDir . '/cortex.yml');

        $content = file_get_contents($this->testDir . '/cortex.yml');
        $this->assertIsString($content, 'Failed to read cortex.yml');
        assert(is_string($content)); // Type narrowing for PHPStan
        $this->assertStringContainsString('version: "1.0"', $content);
        $this->assertStringContainsString('docker:', $content);
        $this->assertStringContainsString('compose_file:', $content);
    }

    public function testInitWithSkipYamlOption(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute(['--skip-yaml' => true], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());
        $this->assertDirectoryExists($this->testDir . '/.cortex');
        $this->assertFileDoesNotExist($this->testDir . '/cortex.yml');
    }

    public function testInitFailsWhenAlreadyInitialized(): void
    {
        // Create .cortex directory and .claude/rules/cortex.md (fully initialized)
        mkdir($this->testDir . '/.cortex', 0755, true);
        mkdir($this->testDir . '/.claude/rules', 0755, true);
        file_put_contents($this->testDir . '/.claude/rules/cortex.md', 'existing');

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertEquals(1, $tester->getStatusCode());
        $this->assertStringContainsString('already initialized', $tester->getDisplay());
    }

    public function testInitWithForceOverwrites(): void
    {
        // Create existing files
        mkdir($this->testDir . '/.cortex', 0755, true);
        file_put_contents($this->testDir . '/cortex.yml', 'old content');

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute(['--force' => true], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());

        // Check that files were overwritten
        $content = file_get_contents($this->testDir . '/cortex.yml');
        $this->assertIsString($content, 'Failed to read cortex.yml');
        assert(is_string($content)); // Type narrowing for PHPStan
        $this->assertStringContainsString('version: "1.0"', $content);
        $this->assertStringNotContainsString('old content', $content);
    }

    public function testInitFailsWhenCortexYmlExistsWithoutForce(): void
    {
        // Create cortex.yml and .claude/rules/cortex.md (fully initialized)
        file_put_contents($this->testDir . '/cortex.yml', 'existing content');
        mkdir($this->testDir . '/.claude/rules', 0755, true);
        file_put_contents($this->testDir . '/.claude/rules/cortex.md', 'existing');

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertEquals(1, $tester->getStatusCode());
        $this->assertStringContainsString('already initialized', $tester->getDisplay());
    }

    public function testInitSucceedsWhenClaudeRulesMissing(): void
    {
        // Create cortex.yml but not .claude/rules/cortex.md
        file_put_contents($this->testDir . '/cortex.yml', 'existing content');

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        // Should succeed because cortex.md is missing - allows re-running to create it
        $tester->execute([], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());
        $this->assertFileExists($this->testDir . '/.claude/rules/cortex.md');
    }

    public function testInitDisplaysSuccessMessage(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Cortex initialized successfully', $output);
        $this->assertStringContainsString('Next steps:', $output);
        $this->assertStringContainsString('cortex up', $output);
    }

    public function testInitCreatesClaudeRulesDirectory(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());
        $this->assertDirectoryExists($this->testDir . '/.claude');
        $this->assertDirectoryExists($this->testDir . '/.claude/rules');
    }

    public function testInitCreatesClaudeRulesCortexMd(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertFileExists($this->testDir . '/.claude/rules/cortex.md');

        $content = file_get_contents($this->testDir . '/.claude/rules/cortex.md');
        $this->assertIsString($content, 'Failed to read cortex.md');
        assert(is_string($content)); // Type narrowing for PHPStan

        // Check it contains content from parent.md
        $this->assertStringContainsString('ticket', $content);

        // Check it contains shared steps
        $this->assertStringContainsString('## Shared Step: Ticket', $content);
        $this->assertStringContainsString('## Shared Step: Specs', $content);
        $this->assertStringContainsString('## Shared Step: Approach', $content);
        $this->assertStringContainsString('## Shared Step: Planning', $content);
        $this->assertStringContainsString('## Shared Step: Tests', $content);
        $this->assertStringContainsString('## Shared Step: Code', $content);

        // Check it contains ticket types section
        $this->assertStringContainsString('# Ticket Types', $content);
        $this->assertStringContainsString('## Ticket Type: User Story', $content);
    }

    public function testInitCreatesClaudeRulesInCorrectOrder(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $content = file_get_contents($this->testDir . '/.claude/rules/cortex.md');
        $this->assertIsString($content, 'Failed to read cortex.md');
        assert(is_string($content)); // Type narrowing for PHPStan

        // Verify steps appear in workflow order
        $ticketPos = strpos($content, '## Shared Step: Ticket');
        $specsPos = strpos($content, '## Shared Step: Specs');
        $approachPos = strpos($content, '## Shared Step: Approach');
        $planningPos = strpos($content, '## Shared Step: Planning');
        $testsPos = strpos($content, '## Shared Step: Tests');
        $codePos = strpos($content, '## Shared Step: Code');
        $ticketTypesPos = strpos($content, '# Ticket Types');

        $this->assertNotFalse($ticketPos);
        $this->assertNotFalse($specsPos);
        $this->assertNotFalse($approachPos);
        $this->assertNotFalse($planningPos);
        $this->assertNotFalse($testsPos);
        $this->assertNotFalse($codePos);
        $this->assertNotFalse($ticketTypesPos);

        // Verify order: ticket -> specs -> approach -> planning -> tests -> code -> ticket types
        $this->assertLessThan($specsPos, $ticketPos, 'Ticket should come before Specs');
        $this->assertLessThan($approachPos, $specsPos, 'Specs should come before Approach');
        $this->assertLessThan($planningPos, $approachPos, 'Approach should come before Planning');
        $this->assertLessThan($testsPos, $planningPos, 'Planning should come before Tests');
        $this->assertLessThan($codePos, $testsPos, 'Tests should come before Code');
        $this->assertLessThan($ticketTypesPos, $codePos, 'Code should come before Ticket Types');
    }

    public function testInitAllowsRerunWhenClaudeRulesMissing(): void
    {
        // Create .cortex directory but not .claude/rules/cortex.md
        mkdir($this->testDir . '/.cortex', 0755, true);
        file_put_contents($this->testDir . '/cortex.yml', 'version: "1.0"');

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        // Should succeed because cortex.md is missing
        $tester->execute([], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());
        $this->assertFileExists($this->testDir . '/.claude/rules/cortex.md');
    }

    public function testInitSkipsClaudeRulesWhenAlreadyExists(): void
    {
        // Run init first
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);
        $tester->execute([], ['interactive' => false]);

        // Create a marker in the file
        $cortexMdPath = $this->testDir . '/.claude/rules/cortex.md';
        file_put_contents($cortexMdPath, 'CUSTOM_MARKER_CONTENT');

        // Run init again (without --force)
        $command2 = new InitCommand();
        $tester2 = $this->createCommandTester($command2);
        $tester2->execute([], ['interactive' => false]);

        // File should still contain the marker (not overwritten)
        $content = file_get_contents($cortexMdPath);
        $this->assertIsString($content);
        assert(is_string($content));
        $this->assertStringContainsString('CUSTOM_MARKER_CONTENT', $content);
    }

    public function testInitForceOverwritesClaudeRules(): void
    {
        // Create existing .claude/rules/cortex.md
        mkdir($this->testDir . '/.claude/rules', 0755, true);
        file_put_contents($this->testDir . '/.claude/rules/cortex.md', 'old content');

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute(['--force' => true], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());

        $content = file_get_contents($this->testDir . '/.claude/rules/cortex.md');
        $this->assertIsString($content, 'Failed to read cortex.md');
        assert(is_string($content));
        $this->assertStringNotContainsString('old content', $content);
        $this->assertStringContainsString('## Shared Step:', $content);
    }

    public function testInitSuccessMessageIncludesClaudeRules(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('.claude/rules/cortex.md', $output);
    }

    public function testInitCreatesClaudeMd(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertFileExists($this->testDir . '/.claude/CLAUDE.md');

        $content = file_get_contents($this->testDir . '/.claude/CLAUDE.md');
        $this->assertIsString($content, 'Failed to read CLAUDE.md');
        assert(is_string($content));

        // Check it contains cortex markers
        $this->assertStringContainsString('<!-- CORTEX START -->', $content);
        $this->assertStringContainsString('<!-- CORTEX END -->', $content);

        // Check it contains template content
        $this->assertStringContainsString('cortex up', $content);
    }

    public function testInitAppendsToExistingClaudeMd(): void
    {
        // Create existing CLAUDE.md without cortex section
        mkdir($this->testDir . '/.claude', 0755, true);
        file_put_contents($this->testDir . '/.claude/CLAUDE.md', "# My Project\n\nExisting content here.");

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $content = file_get_contents($this->testDir . '/.claude/CLAUDE.md');
        $this->assertIsString($content, 'Failed to read CLAUDE.md');
        assert(is_string($content));

        // Check it preserves existing content
        $this->assertStringContainsString('# My Project', $content);
        $this->assertStringContainsString('Existing content here.', $content);

        // Check it appended cortex section
        $this->assertStringContainsString('<!-- CORTEX START -->', $content);
        $this->assertStringContainsString('<!-- CORTEX END -->', $content);
        $this->assertStringContainsString('cortex up', $content);

        // Verify existing content comes before cortex section
        $existingPos = strpos($content, '# My Project');
        $cortexPos = strpos($content, '<!-- CORTEX START -->');
        $this->assertNotFalse($existingPos);
        $this->assertNotFalse($cortexPos);
        $this->assertLessThan($cortexPos, $existingPos, 'Existing content should come before Cortex section');
    }

    public function testInitSkipsClaudeMdWhenCortexSectionExists(): void
    {
        // Create existing CLAUDE.md with cortex section
        mkdir($this->testDir . '/.claude', 0755, true);
        $existingContent = "# My Project\n\n<!-- CORTEX START -->\nOld cortex content\n<!-- CORTEX END -->";
        file_put_contents($this->testDir . '/.claude/CLAUDE.md', $existingContent);

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $content = file_get_contents($this->testDir . '/.claude/CLAUDE.md');
        $this->assertIsString($content, 'Failed to read CLAUDE.md');
        assert(is_string($content));

        // Check it preserved the old cortex content (not updated)
        $this->assertStringContainsString('Old cortex content', $content);

        // Check output mentions it was skipped
        $output = $tester->getDisplay();
        $this->assertStringContainsString('already has Cortex section', $output);
    }

    public function testInitSuccessMessageIncludesClaudeMd(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('.claude/CLAUDE.md', $output);
    }

    private function createCommandTester(InitCommand $command): CommandTester
    {
        // Change to test directory
        $originalDir = getcwd();
        chdir($this->testDir);

        $application = new Application();
        $application->add($command);

        $command = $application->find('init');
        $tester = new CommandTester($command);

        // Register a shutdown function to restore directory
        register_shutdown_function(function () use ($originalDir) {
            if ($originalDir !== false) {
                @chdir($originalDir);
            }
        });

        return $tester;
    }

    private function recursiveRemoveDirectory(string $directory): void
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
                $this->recursiveRemoveDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
