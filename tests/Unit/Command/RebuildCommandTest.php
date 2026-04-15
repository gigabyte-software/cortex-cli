<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit\Command;

use Cortex\Command\RebuildCommand;
use Cortex\Config\ConfigLoader;
use Cortex\Config\LockFile;
use Cortex\Config\LockFileData;
use Cortex\Config\Schema\CommandDefinition;
use Cortex\Config\Schema\CortexConfig;
use Cortex\Config\Schema\DockerConfig;
use Cortex\Config\Schema\N8nConfig;
use Cortex\Config\Schema\ServiceWaitConfig;
use Cortex\Config\Schema\SetupConfig;
use Cortex\Docker\ComposeOverrideGenerator;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\HealthChecker;
use Cortex\Orchestrator\CommandOrchestrator;
use Cortex\Output\OutputFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class RebuildCommandTest extends TestCase
{
    private ConfigLoader $configLoader;
    private DockerCompose $dockerCompose;
    private HealthChecker $healthChecker;
    private CommandOrchestrator $commandOrchestrator;
    private LockFile $lockFile;
    private ComposeOverrideGenerator $overrideGenerator;

    protected function setUp(): void
    {
        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->healthChecker = $this->createMock(HealthChecker::class);
        $this->lockFile = $this->createMock(LockFile::class);
        $this->overrideGenerator = $this->createMock(ComposeOverrideGenerator::class);

        $output = $this->createMock(OutputInterface::class);
        $formatter = new OutputFormatter($output);
        $this->commandOrchestrator = $this->createMock(CommandOrchestrator::class);
    }

    public function test_command_is_configured_correctly(): void
    {
        $command = $this->createCommand();

        $this->assertSame('rebuild', $command->getName());
        $this->assertSame('Rebuild Docker images, recreate containers, and run fresh', $command->getDescription());
    }

    public function test_it_tears_down_rebuilds_and_runs_fresh(): void
    {
        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(
                command: 'php artisan migrate:fresh --seed',
                description: 'Reset database',
            ),
        ]);

        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->dockerCompose->expects($this->once())
            ->method('down')
            ->with('docker-compose.yml', false, null);

        $this->overrideGenerator->expects($this->once())
            ->method('cleanup');

        $this->dockerCompose->expects($this->once())
            ->method('upWithBuild')
            ->with('docker-compose.yml', null);

        $this->commandOrchestrator->expects($this->once())
            ->method('run')
            ->with('fresh', $config)
            ->willReturn(1.5);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('rebuilt successfully', $tester->getDisplay());
    }

    public function test_it_uses_namespace_from_lock_file(): void
    {
        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(
                command: 'php artisan migrate:fresh --seed',
                description: 'Reset database',
            ),
        ]);

        $this->setupConfigLoader($config);

        $lockData = new LockFileData(
            namespace: 'my-project',
            portOffset: 1000,
            startedAt: '2025-11-08T10:30:00+00:00',
        );

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $this->lockFile->expects($this->once())
            ->method('read')
            ->willReturn($lockData);

        $this->dockerCompose->expects($this->once())
            ->method('down')
            ->with('docker-compose.yml', false, 'my-project');

        $this->dockerCompose->expects($this->once())
            ->method('upWithBuild')
            ->with('docker-compose.yml', 'my-project');

        $this->commandOrchestrator->expects($this->once())
            ->method('run')
            ->willReturn(1.0);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_warns_when_fresh_is_not_defined(): void
    {
        $config = $this->createMockConfig([]);

        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->dockerCompose->expects($this->once())->method('down');
        $this->dockerCompose->expects($this->once())->method('upWithBuild');

        $this->commandOrchestrator->expects($this->never())->method('run');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('fresh', $tester->getDisplay());
        $this->assertStringContainsString('not defined', $tester->getDisplay());
    }

    public function test_it_skips_fresh_when_command_string_is_empty(): void
    {
        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(
                command: '',
                description: 'Reset database',
            ),
        ]);

        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->dockerCompose->expects($this->once())->method('down');
        $this->dockerCompose->expects($this->once())->method('upWithBuild');

        $this->commandOrchestrator->expects($this->never())->method('run');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('not defined', $tester->getDisplay());
    }

    public function test_it_waits_for_services_when_configured(): void
    {
        $config = $this->createMockConfig(
            [
                'fresh' => new CommandDefinition(
                    command: 'php artisan migrate:fresh --seed',
                    description: 'Reset database',
                ),
            ],
            [new ServiceWaitConfig(service: 'db', timeout: 60)]
        );

        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->dockerCompose->expects($this->once())->method('down');
        $this->dockerCompose->expects($this->once())->method('upWithBuild');

        $this->healthChecker->expects($this->once())
            ->method('waitForHealth')
            ->with('docker-compose.yml', 'db', 60, null);

        $this->commandOrchestrator->expects($this->once())
            ->method('run')
            ->willReturn(1.0);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_handles_down_failure_gracefully(): void
    {
        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(
                command: 'php artisan migrate:fresh --seed',
                description: 'Reset database',
            ),
        ]);

        $this->setupConfigLoader($config);

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->dockerCompose->expects($this->once())
            ->method('down')
            ->willThrowException(new \RuntimeException('No containers running'));

        $this->dockerCompose->expects($this->once())->method('upWithBuild');
        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Could not stop containers', $tester->getDisplay());
    }

    private function createCommand(): RebuildCommand
    {
        return new RebuildCommand(
            $this->configLoader,
            $this->dockerCompose,
            $this->healthChecker,
            $this->commandOrchestrator,
            $this->lockFile,
            $this->overrideGenerator,
        );
    }

    private function setupConfigLoader(CortexConfig $config): void
    {
        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);
    }

    /**
     * @param array<string, CommandDefinition> $commands
     * @param ServiceWaitConfig[] $waitFor
     */
    private function createMockConfig(array $commands, array $waitFor = []): CortexConfig
    {
        return new CortexConfig(
            version: '1.0',
            docker: new DockerConfig(
                composeFile: 'docker-compose.yml',
                primaryService: 'app',
                appUrl: 'http://localhost:80',
                waitFor: $waitFor,
            ),
            setup: new SetupConfig(preStart: [], initialize: []),
            n8n: new N8nConfig(workflowsDir: './.n8n'),
            commands: $commands,
        );
    }
}
