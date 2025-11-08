<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit\Command;

use Cortex\Command\DownCommand;
use Cortex\Config\ConfigLoader;
use Cortex\Config\LockFile;
use Cortex\Config\LockFileData;
use Cortex\Config\Schema\CortexConfig;
use Cortex\Config\Schema\DockerConfig;
use Cortex\Config\Schema\SetupConfig;
use Cortex\Docker\ComposeOverrideGenerator;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\NamespaceResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class DownCommandTest extends TestCase
{
    private ConfigLoader $configLoader;
    private DockerCompose $dockerCompose;
    private LockFile $lockFile;
    private NamespaceResolver $namespaceResolver;
    private ComposeOverrideGenerator $overrideGenerator;

    protected function setUp(): void
    {
        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->lockFile = $this->createMock(LockFile::class);
        $this->namespaceResolver = $this->createMock(NamespaceResolver::class);
        $this->overrideGenerator = $this->createMock(ComposeOverrideGenerator::class);
    }

    public function test_command_is_configured_correctly(): void
    {
        $command = $this->createCommand();

        $this->assertSame('down', $command->getName());
        $this->assertSame('Tear down the development environment', $command->getDescription());
        
        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('volumes'));
    }

    public function test_it_stops_services_with_namespace_from_lock_file(): void
    {
        $config = $this->createMockConfig();
        
        $lockData = new LockFileData(
            namespace: 'cortex-agent-1-project',
            portOffset: 1000,
            startedAt: '2025-11-08T10:30:00+00:00'
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

        $this->dockerCompose->expects($this->once())
            ->method('down')
            ->with('docker-compose.yml', false, 'cortex-agent-1-project');

        $this->overrideGenerator->expects($this->once())
            ->method('cleanup');

        $this->lockFile->expects($this->once())
            ->method('delete');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_derives_namespace_when_no_lock_file(): void
    {
        $config = $this->createMockConfig();

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->namespaceResolver->expects($this->once())
            ->method('deriveFromDirectory')
            ->willReturn('cortex-test-project');

        $this->dockerCompose->expects($this->once())
            ->method('down')
            ->with('docker-compose.yml', false, 'cortex-test-project');

        $this->overrideGenerator->expects($this->once())
            ->method('cleanup');

        $this->lockFile->expects($this->once())
            ->method('delete');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_removes_volumes_when_requested(): void
    {
        $config = $this->createMockConfig();

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->namespaceResolver->expects($this->once())
            ->method('deriveFromDirectory')
            ->willReturn('cortex-test-project');

        $this->dockerCompose->expects($this->once())
            ->method('down')
            ->with('docker-compose.yml', true, 'cortex-test-project');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--volumes' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('volumes removed', $tester->getDisplay());
    }

    public function test_it_always_cleans_up_override_and_lock_files(): void
    {
        $config = $this->createMockConfig();

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->namespaceResolver->expects($this->once())
            ->method('deriveFromDirectory')
            ->willReturn('cortex-test-project');

        $this->dockerCompose->expects($this->once())
            ->method('down');

        $this->overrideGenerator->expects($this->once())
            ->method('cleanup');

        $this->lockFile->expects($this->once())
            ->method('delete');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    private function createCommand(): DownCommand
    {
        return new DownCommand(
            $this->configLoader,
            $this->dockerCompose,
            $this->lockFile,
            $this->namespaceResolver,
            $this->overrideGenerator
        );
    }

    private function createMockConfig(): CortexConfig
    {
        $dockerConfig = new DockerConfig(
            composeFile: 'docker-compose.yml',
            primaryService: 'app',
            appUrl: 'http://localhost:80',
            waitFor: []
        );

        $setupConfig = new SetupConfig(
            preStart: [],
            initialize: []
        );

        return new CortexConfig(
            version: '1.0',
            docker: $dockerConfig,
            setup: $setupConfig,
            commands: []
        );
    }
}

