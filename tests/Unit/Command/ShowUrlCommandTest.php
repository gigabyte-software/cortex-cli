<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit\Command;

use Cortex\Command\ShowUrlCommand;
use Cortex\Config\ConfigLoader;
use Cortex\Config\LockFile;
use Cortex\Config\LockFileData;
use Cortex\Config\Schema\CortexConfig;
use Cortex\Config\Schema\DockerConfig;
use Cortex\Config\Schema\N8nConfig;
use Cortex\Config\Schema\SetupConfig;
use Cortex\Docker\PortOffsetManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ShowUrlCommandTest extends TestCase
{
    private ConfigLoader $configLoader;
    private LockFile $lockFile;
    private PortOffsetManager $portOffsetManager;

    protected function setUp(): void
    {
        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->lockFile = $this->createMock(LockFile::class);
        $this->portOffsetManager = $this->createMock(PortOffsetManager::class);
    }

    public function test_command_is_configured_correctly(): void
    {
        $command = $this->createCommand();

        $this->assertSame('show-url', $command->getName());
        $this->assertSame('Display the URL for the development environment', $command->getDescription());
    }

    public function test_it_outputs_url_with_base_port(): void
    {
        $config = $this->createMockConfig('http://localhost');

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->portOffsetManager->expects($this->once())
            ->method('getPrimaryServicePort')
            ->with('docker-compose.yml', 'app')
            ->willReturn(80);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("http://localhost:80\n", $tester->getDisplay());
    }

    public function test_it_applies_port_offset_from_lock_file(): void
    {
        $config = $this->createMockConfig('http://localhost');

        $lockData = new LockFileData(
            namespace: 'cortex-agent-1',
            portOffset: 8000,
            startedAt: '2025-01-07T10:00:00+00:00'
        );

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $this->lockFile->expects($this->once())
            ->method('read')
            ->willReturn($lockData);

        $this->portOffsetManager->expects($this->once())
            ->method('getPrimaryServicePort')
            ->with('docker-compose.yml', 'app')
            ->willReturn(80);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("http://localhost:8080\n", $tester->getDisplay());
    }

    public function test_it_outputs_app_url_when_no_port_exposed(): void
    {
        $config = $this->createMockConfig('http://localhost');

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->portOffsetManager->expects($this->once())
            ->method('getPrimaryServicePort')
            ->with('docker-compose.yml', 'app')
            ->willReturn(null);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertSame("http://localhost\n", $tester->getDisplay());
    }

    private function createCommand(): ShowUrlCommand
    {
        return new ShowUrlCommand(
            $this->configLoader,
            $this->lockFile,
            $this->portOffsetManager
        );
    }

    private function createMockConfig(string $appUrl): CortexConfig
    {
        $dockerConfig = new DockerConfig(
            composeFile: 'docker-compose.yml',
            primaryService: 'app',
            appUrl: $appUrl,
            waitFor: []
        );

        $setupConfig = new SetupConfig(
            preStart: [],
            initialize: []
        );

        $n8nConfig = new N8nConfig(
            workflowsDir: './.n8n'
        );

        return new CortexConfig(
            version: '1.0',
            docker: $dockerConfig,
            setup: $setupConfig,
            n8n: $n8nConfig,
            commands: []
        );
    }
}

