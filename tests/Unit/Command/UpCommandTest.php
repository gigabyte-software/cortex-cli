<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit\Command;

use Cortex\Command\UpCommand;
use Cortex\Config\ConfigLoader;
use Cortex\Config\LockFile;
use Cortex\Config\LockFileData;
use Cortex\Config\Schema\CortexConfig;
use Cortex\Config\Schema\DockerConfig;
use Cortex\Config\Schema\SetupConfig;
use Cortex\Docker\ComposeOverrideGenerator;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\NamespaceResolver;
use Cortex\Docker\PortOffsetManager;
use Cortex\Orchestrator\SetupOrchestrator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class UpCommandTest extends TestCase
{
    private ConfigLoader $configLoader;
    private SetupOrchestrator $setupOrchestrator;
    private LockFile $lockFile;
    private NamespaceResolver $namespaceResolver;
    private PortOffsetManager $portOffsetManager;
    private ComposeOverrideGenerator $overrideGenerator;
    private DockerCompose $dockerCompose;

    protected function setUp(): void
    {
        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->setupOrchestrator = $this->createMock(SetupOrchestrator::class);
        $this->lockFile = $this->createMock(LockFile::class);
        $this->namespaceResolver = $this->createMock(NamespaceResolver::class);
        $this->portOffsetManager = $this->createMock(PortOffsetManager::class);
        $this->overrideGenerator = $this->createMock(ComposeOverrideGenerator::class);
        $this->dockerCompose = $this->createMock(DockerCompose::class);
    }

    public function test_command_is_configured_correctly(): void
    {
        $command = $this->createCommand();

        $this->assertSame('up', $command->getName());
        $this->assertSame('Set up the development environment', $command->getDescription());

        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('namespace'));
        $this->assertTrue($definition->hasOption('port-offset'));
        $this->assertTrue($definition->hasOption('avoid-conflicts'));
        $this->assertTrue($definition->hasOption('no-wait'));
        $this->assertTrue($definition->hasOption('skip-init'));
    }

    public function test_it_prevents_duplicate_instances(): void
    {
        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(true);

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('already running', $tester->getDisplay());
    }

    public function test_it_runs_default_mode_without_namespace_or_offset(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        // No namespace resolution in default mode
        $this->namespaceResolver->expects($this->never())
            ->method('deriveFromDirectory');

        // No override generation in default mode
        $this->overrideGenerator->expects($this->never())
            ->method('generate');

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->with(
                $config,
                false,
                false,
                null,
                0
            )
            ->willReturn([
                'time' => 1.5,
                'namespace' => '',
                'port_offset' => 0,
            ]);

        // No lock file in default mode
        $this->lockFile->expects($this->never())
            ->method('write');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_uses_explicit_namespace(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->namespaceResolver->expects($this->once())
            ->method('validate')
            ->with('custom-namespace');

        // Override file should be generated with namespace prefix
        $this->overrideGenerator->expects($this->once())
            ->method('generate')
            ->with('docker-compose.yml', 0, 'custom-namespace');

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->with(
                $config,
                false,
                false,
                'custom-namespace',
                0
            )
            ->willReturn([
                'time' => 1.5,
                'namespace' => 'custom-namespace',
                'port_offset' => 0,
            ]);

        // Lock file should be written when using namespace (even with port offset 0)
        $this->lockFile->expects($this->once())
            ->method('write')
            ->with($this->callback(function (LockFileData $data) {
                return $data->namespace === 'custom-namespace' && $data->portOffset === null;
            }));

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--namespace' => 'custom-namespace']);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_uses_explicit_port_offset(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        // No explicit namespace, so default mode (no namespace)
        $this->namespaceResolver->expects($this->never())
            ->method('deriveFromDirectory');

        $this->overrideGenerator->expects($this->once())
            ->method('generate')
            ->with('docker-compose.yml', 1000, null);

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->with(
                $config,
                false,
                false,
                null,
                1000
            )
            ->willReturn([
                'time' => 1.5,
                'namespace' => '',
                'port_offset' => 1000,
            ]);

        $this->lockFile->expects($this->once())
            ->method('write')
            ->with($this->callback(function (LockFileData $data) {
                return $data->portOffset === 1000;
            }));

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--port-offset' => '1000']);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_auto_allocates_with_avoid_conflicts(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->namespaceResolver->expects($this->once())
            ->method('deriveFromDirectory')
            ->willReturn('cortex-test-project');

        $this->portOffsetManager->expects($this->once())
            ->method('extractBasePorts')
            ->willReturn([80, 443]);

        $this->portOffsetManager->expects($this->once())
            ->method('findAvailableOffset')
            ->with([80, 443])
            ->willReturn(8000);

        // Should generate override with both port offset and namespace prefix
        $this->overrideGenerator->expects($this->once())
            ->method('generate')
            ->with('docker-compose.yml', 8000, 'cortex-test-project');

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->willReturn([
                'time' => 1.5,
                'namespace' => 'cortex-test-project',
                'port_offset' => 8000,
            ]);

        $this->lockFile->expects($this->once())
            ->method('write');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--avoid-conflicts' => true]);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_does_not_generate_override_in_default_mode(): void
    {
        $config = $this->createMockConfig();

        $this->lockFile->expects($this->once())
            ->method('exists')
            ->willReturn(false);

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        // No namespace derivation in default mode
        $this->namespaceResolver->expects($this->never())
            ->method('deriveFromDirectory');

        // No override generation in default mode
        $this->overrideGenerator->expects($this->never())
            ->method('generate');

        $this->setupOrchestrator->expects($this->once())
            ->method('setup')
            ->with($config, false, false, null, 0)
            ->willReturn([
                'time' => 1.5,
                'namespace' => '',
                'port_offset' => 0,
            ]);

        // No lock file in default mode
        $this->lockFile->expects($this->never())
            ->method('write');

        $command = $this->createCommand();
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    private function createCommand(): UpCommand
    {
        return new UpCommand(
            $this->configLoader,
            $this->setupOrchestrator,
            $this->lockFile,
            $this->namespaceResolver,
            $this->portOffsetManager,
            $this->overrideGenerator,
            $this->dockerCompose
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
