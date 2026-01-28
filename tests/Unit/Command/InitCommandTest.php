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
    private string $testHomeDir;
    private string|false $originalHome;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary directory for testing (project directory)
        $this->testDir = sys_get_temp_dir() . '/cortex_init_test_' . uniqid();
        mkdir($this->testDir, 0755, true);

        // Create a temporary home directory for testing
        $this->testHomeDir = sys_get_temp_dir() . '/cortex_home_test_' . uniqid();
        mkdir($this->testHomeDir, 0755, true);

        // Save original HOME and set test home directory
        $this->originalHome = getenv('HOME');
        putenv('HOME=' . $this->testHomeDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Restore original HOME
        if ($this->originalHome !== false) {
            putenv('HOME=' . $this->originalHome);
        } else {
            putenv('HOME');
        }

        // Clean up test directories
        if (is_dir($this->testDir)) {
            $this->recursiveRemoveDirectory($this->testDir);
        }
        if (is_dir($this->testHomeDir)) {
            $this->recursiveRemoveDirectory($this->testHomeDir);
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

    public function testInitWithSkipClaudeOption(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute(['--skip-claude' => true], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());

        // Project files should be created
        $this->assertDirectoryExists($this->testDir . '/.cortex');
        $this->assertFileExists($this->testDir . '/cortex.yml');

        // User-level claude files should NOT be created
        $this->assertFileDoesNotExist($this->testHomeDir . '/.claude/CLAUDE.md');
        $this->assertFileDoesNotExist($this->testHomeDir . '/.claude/rules/cortex.md');

        // Output should mention skipping
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Skipped ~/.claude files', $output);
    }

    public function testInitIsIdempotent(): void
    {
        // Run init first time
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);
        $tester->execute([], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());

        // Run init second time - should succeed with "up to date" messages
        $command2 = new InitCommand();
        $tester2 = $this->createCommandTester($command2);
        $tester2->execute([], ['interactive' => false]);

        $this->assertEquals(0, $tester2->getStatusCode());
        $output = $tester2->getDisplay();
        $this->assertStringContainsString('already exists', $output);
        $this->assertStringContainsString('up to date', $output);
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

    public function testInitUpdatesClaudeFilesWhenCortexYmlExists(): void
    {
        // Create cortex.yml with custom content and outdated claude files
        file_put_contents($this->testDir . '/cortex.yml', 'existing content');
        mkdir($this->testHomeDir . '/.claude/rules', 0755, true);
        file_put_contents($this->testHomeDir . '/.claude/rules/cortex.md', 'outdated content');

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        // Should succeed and update claude files
        $this->assertEquals(0, $tester->getStatusCode());

        // cortex.yml should not be overwritten (no --force)
        $cortexYmlContent = file_get_contents($this->testDir . '/cortex.yml');
        $this->assertIsString($cortexYmlContent);
        $this->assertStringContainsString('existing content', $cortexYmlContent);

        // claude files should be updated
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Updated ~/.claude/rules/cortex.md', $output);
    }

    public function testInitSucceedsWhenClaudeRulesMissing(): void
    {
        // Create cortex.yml but not ~/.claude/rules/cortex.md
        file_put_contents($this->testDir . '/cortex.yml', 'existing content');

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        // Should succeed because cortex.md is missing - allows re-running to create it
        $tester->execute([], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());
        $this->assertFileExists($this->testHomeDir . '/.claude/rules/cortex.md');
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
        $this->assertDirectoryExists($this->testHomeDir . '/.claude');
        $this->assertDirectoryExists($this->testHomeDir . '/.claude/rules');
    }

    public function testInitCreatesClaudeRulesCortexMd(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertFileExists($this->testHomeDir . '/.claude/rules/cortex.md');

        $content = file_get_contents($this->testHomeDir . '/.claude/rules/cortex.md');
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

        $content = file_get_contents($this->testHomeDir . '/.claude/rules/cortex.md');
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
        // Create .cortex directory but not ~/.claude/rules/cortex.md
        mkdir($this->testDir . '/.cortex', 0755, true);
        file_put_contents($this->testDir . '/cortex.yml', 'version: "1.0"');

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        // Should succeed because cortex.md is missing
        $tester->execute([], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());
        $this->assertFileExists($this->testHomeDir . '/.claude/rules/cortex.md');
    }

    public function testInitSkipsClaudeRulesWhenUpToDate(): void
    {
        // Run init first
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);
        $tester->execute([], ['interactive' => false]);

        // Run init again (without --force) - should show "up to date"
        $command2 = new InitCommand();
        $tester2 = $this->createCommandTester($command2);
        $tester2->execute([], ['interactive' => false]);

        $output = $tester2->getDisplay();
        $this->assertStringContainsString('is up to date', $output);
    }

    public function testInitUpdatesClaudeRulesWhenContentChanged(): void
    {
        // Run init first
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);
        $tester->execute([], ['interactive' => false]);

        // Modify the file to simulate outdated content
        $cortexMdPath = $this->testHomeDir . '/.claude/rules/cortex.md';
        file_put_contents($cortexMdPath, 'OLD_CONTENT_THAT_DIFFERS');

        // Run init again - should update the file
        $command2 = new InitCommand();
        $tester2 = $this->createCommandTester($command2);
        $tester2->execute([], ['interactive' => false]);

        $output = $tester2->getDisplay();
        $this->assertStringContainsString('Updated ~/.claude/rules/cortex.md', $output);

        // Verify content was updated
        $content = file_get_contents($cortexMdPath);
        $this->assertIsString($content);
        assert(is_string($content));
        $this->assertStringNotContainsString('OLD_CONTENT_THAT_DIFFERS', $content);
        $this->assertStringContainsString('## Shared Step:', $content);
    }

    public function testInitForceOverwritesClaudeRules(): void
    {
        // Create existing ~/.claude/rules/cortex.md
        mkdir($this->testHomeDir . '/.claude/rules', 0755, true);
        file_put_contents($this->testHomeDir . '/.claude/rules/cortex.md', 'old content');

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute(['--force' => true], ['interactive' => false]);

        $this->assertEquals(0, $tester->getStatusCode());

        $content = file_get_contents($this->testHomeDir . '/.claude/rules/cortex.md');
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
        $this->assertStringContainsString('~/.claude/rules/cortex.md', $output);
    }

    public function testInitCreatesClaudeMd(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertFileExists($this->testHomeDir . '/.claude/CLAUDE.md');

        $content = file_get_contents($this->testHomeDir . '/.claude/CLAUDE.md');
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
        mkdir($this->testHomeDir . '/.claude', 0755, true);
        file_put_contents($this->testHomeDir . '/.claude/CLAUDE.md', "# My Project\n\nExisting content here.");

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $content = file_get_contents($this->testHomeDir . '/.claude/CLAUDE.md');
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

    public function testInitSkipsClaudeMdWhenCortexSectionUpToDate(): void
    {
        // Run init first to create the file with correct content
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);
        $tester->execute([], ['interactive' => false]);

        // Run init again - should show "up to date"
        $command2 = new InitCommand();
        $tester2 = $this->createCommandTester($command2);
        $tester2->execute([], ['interactive' => false]);

        $output = $tester2->getDisplay();
        $this->assertStringContainsString('Cortex section is up to date', $output);
    }

    public function testInitUpdatesClaudeMdWhenCortexSectionChanged(): void
    {
        // Create existing CLAUDE.md with outdated cortex section
        mkdir($this->testHomeDir . '/.claude', 0755, true);
        $existingContent = "# My Project\n\n<!-- CORTEX START -->\nOld cortex content\n<!-- CORTEX END -->";
        file_put_contents($this->testHomeDir . '/.claude/CLAUDE.md', $existingContent);

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $content = file_get_contents($this->testHomeDir . '/.claude/CLAUDE.md');
        $this->assertIsString($content, 'Failed to read CLAUDE.md');
        assert(is_string($content));

        // Check the cortex section was updated
        $this->assertStringNotContainsString('Old cortex content', $content);
        $this->assertStringContainsString('cortex up', $content);

        // Check it preserved user content outside the markers
        $this->assertStringContainsString('# My Project', $content);

        // Check output mentions it was updated
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Updated Cortex section', $output);
    }

    public function testInitSuccessMessageIncludesClaudeMd(): void
    {
        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('~/.claude/CLAUDE.md', $output);
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
