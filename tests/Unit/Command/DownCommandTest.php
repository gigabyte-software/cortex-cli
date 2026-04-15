<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit\Command;

use Cortex\Command\DownCommand;
use Cortex\Config\ConfigLoader;
use Cortex\Config\LockFile;
use Cortex\Config\LockFileData;
use Cortex\Config\Schema\CortexConfig;
use Cortex\Config\Schema\DockerConfig;
use Cortex\Config\Schema\N8nConfig;
use Cortex\Config\Schema\SetupConfig;
use Cortex\Docker\ComposeOverrideGenerator;
use Cortex\Docker\DockerCompose;
use Cortex\Herd\HerdService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class DownCommandTest extends TestCase
{
    private ConfigLoader $configLoader;
    private DockerCompose $dockerCompose;
    private LockFile $lockFile;
    private ComposeOverrideGenerator $overrideGenerator;
    private HerdService $herdService;

    protected function setUp(): void
    {
        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->lockFile = $this->createMock(LockFile::class);
        $this->overrideGenerator = $this->createMock(ComposeOverrideGenerator::class);
        $this->herdService = $this->createMock(HerdService::class);
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

    public function test_it_uses_default_mode_when_no_lock_file(): void
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

        $this->dockerCompose->expects($this->once())
            ->method('down')
            ->with('docker-compose.yml', false, null);

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

        $this->dockerCompose->expects($this->once())
            ->method('down')
            ->with('docker-compose.yml', true, null);

        $this->overrideGenerator->expects($this->once())
            ->method('cleanup');

        $this->lockFile->expects($this->once())
            ->method('delete');

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

        $this->dockerCompose->expects($this->once())
            ->method('down')
            ->with('docker-compose.yml', false, null);

        $this->overrideGenerator->expects($this->once())
            ->method('cleanup');

        $this->lockFile->expects($this->once())
            ->method('delete');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_restarts_herd_when_it_was_stopped(): void
    {
        $config = $this->createMockConfig();

        $lockData = new LockFileData(
            namespace: null,
            portOffset: null,
            startedAt: '2025-11-08T10:30:00+00:00',
            herdStopped: true
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
            ->method('down');

        $this->herdService->expects($this->once())
            ->method('start');

        $this->lockFile->expects($this->once())
            ->method('delete');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Restarting Herd services', $tester->getDisplay());
        $this->assertStringContainsString('Herd services restarted', $tester->getDisplay());
    }

    public function test_it_does_not_restart_herd_when_it_was_not_stopped(): void
    {
        $config = $this->createMockConfig();

        $lockData = new LockFileData(
            namespace: null,
            portOffset: null,
            startedAt: '2025-11-08T10:30:00+00:00',
            herdStopped: false
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
            ->method('down');

        $this->herdService->expects($this->never())
            ->method('start');

        $this->lockFile->expects($this->once())
            ->method('delete');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('Restarting Herd', $tester->getDisplay());
    }

    public function test_it_warns_but_succeeds_when_herd_restart_fails(): void
    {
        $config = $this->createMockConfig();

        $lockData = new LockFileData(
            namespace: null,
            portOffset: null,
            startedAt: '2025-11-08T10:30:00+00:00',
            herdStopped: true
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
            ->method('down');

        $this->herdService->expects($this->once())
            ->method('start')
            ->willThrowException(new \RuntimeException('Herd binary not found'));

        $this->lockFile->expects($this->once())
            ->method('delete');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Could not restart Herd', $tester->getDisplay());
        $this->assertStringContainsString('herd start', $tester->getDisplay());
    }

    private function createCommand(): DownCommand
    {
        return new DownCommand(
            $this->configLoader,
            $this->dockerCompose,
            $this->lockFile,
            $this->overrideGenerator,
            $this->herdService
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
