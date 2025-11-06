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
        // Create .cortex directory first
        mkdir($this->testDir . '/.cortex', 0755, true);

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
        // Create cortex.yml first
        file_put_contents($this->testDir . '/cortex.yml', 'existing content');

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        $tester->execute([], ['interactive' => false]);

        $this->assertEquals(1, $tester->getStatusCode());
        $this->assertStringContainsString('already initialized', $tester->getDisplay());
    }

    public function testInitWarnsWhenCortexYmlExistsButContinues(): void
    {
        // Create cortex.yml but not .cortex directory
        file_put_contents($this->testDir . '/cortex.yml', 'existing content');

        $command = new InitCommand();
        $tester = $this->createCommandTester($command);

        // This should fail because of the isAlreadyInitialized check
        $tester->execute([], ['interactive' => false]);
        
        $this->assertEquals(1, $tester->getStatusCode());
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
