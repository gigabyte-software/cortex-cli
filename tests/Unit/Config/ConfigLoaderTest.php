<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit\Config;

use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Config\Validator\ConfigValidator;
use PHPUnit\Framework\TestCase;

class ConfigLoaderTest extends TestCase
{
    private ConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new ConfigLoader(new ConfigValidator());
    }

    public function test_it_loads_valid_config(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/cortex.yml');

        $this->assertEquals('1.0', $config->version);
        $this->assertEquals('app', $config->docker->primaryService);
        $this->assertCount(1, $config->docker->waitFor);
        $this->assertCount(1, $config->setup->preStart);
        $this->assertCount(1, $config->setup->initialize);
        $this->assertCount(1, $config->commands);
    }

    public function test_it_throws_exception_for_missing_file(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Configuration file not found');

        $this->loader->load('/nonexistent/cortex.yml');
    }

    public function test_it_resolves_relative_compose_file_path(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/cortex.yml');

        // The compose file path should be absolute after loading
        $this->assertStringContainsString('docker-compose.test.yml', $config->docker->composeFile);
        $this->assertTrue(str_starts_with($config->docker->composeFile, '/'));
    }

    public function test_it_loads_app_url(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/cortex.yml');

        $this->assertEquals('http://localhost:8080', $config->docker->appUrl);
    }

    public function test_it_parses_command_definitions(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/cortex.yml');

        $initCommand = $config->setup->initialize[0];
        $this->assertEquals("echo 'Initialize command executed'", $initCommand->command);
        $this->assertEquals('Test initialize command', $initCommand->description);
        $this->assertEquals(120, $initCommand->timeout);
    }

    public function test_it_parses_custom_commands(): void
    {
        $config = $this->loader->load(__DIR__ . '/../../fixtures/cortex.yml');

        $this->assertArrayHasKey('test', $config->commands);
        $testCommand = $config->commands['test'];
        $this->assertEquals("echo 'Running tests'", $testCommand->command);
        $this->assertEquals('Run test suite', $testCommand->description);
    }
}

