<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit\Command;

use Cortex\Command\ShellCommand;
use Cortex\Config\ConfigLoader;
use Cortex\Config\Schema\CortexConfig;
use Cortex\Config\Schema\DockerConfig;
use Cortex\Config\Schema\SetupConfig;
use Cortex\Docker\ContainerExecutor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ShellCommandTest extends TestCase
{
    public function testCommandIsConfiguredCorrectly(): void
    {
        $configLoader = $this->createMock(ConfigLoader::class);
        $containerExecutor = $this->createMock(ContainerExecutor::class);

        $command = new ShellCommand($configLoader, $containerExecutor);

        $this->assertSame('shell', $command->getName());
        $this->assertSame('Open an interactive bash shell in the primary service container', $command->getDescription());
    }

    public function testExecuteOpensShellInPrimaryService(): void
    {
        $configLoader = $this->createMock(ConfigLoader::class);
        $containerExecutor = $this->createMock(ContainerExecutor::class);

        $dockerConfig = new DockerConfig(
            composeFile: 'docker-compose.yml',
            primaryService: 'app',
            waitFor: []
        );

        $setupConfig = new SetupConfig(
            preStart: [],
            initialize: []
        );

        $config = new CortexConfig(
            version: '1.0',
            docker: $dockerConfig,
            setup: $setupConfig,
            commands: []
        );

        $configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');

        $configLoader->expects($this->once())
            ->method('load')
            ->with('/path/to/cortex.yml')
            ->willReturn($config);

        $purple = '\\[\\033[38;2;125;85;199m\\]';
        $teal = '\\[\\033[38;2;46;217;195m\\]';
        $reset = '\\[\\033[0m\\]';
        $prompt = $purple . 'app' . $reset . ':' . $teal . '\\w' . $reset . '\\$ ';
        $expectedShellCommand = sprintf('/bin/sh -c "export PS1=\'%s\'; exec /bin/bash -i"', $prompt);

        $containerExecutor->expects($this->once())
            ->method('execInteractive')
            ->with('docker-compose.yml', 'app', $expectedShellCommand)
            ->willReturn(0);

        $command = new ShellCommand($configLoader, $containerExecutor);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function testExecuteHandlesConfigException(): void
    {
        $configLoader = $this->createMock(ConfigLoader::class);
        $containerExecutor = $this->createMock(ContainerExecutor::class);

        $configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willThrowException(new \Cortex\Config\Exception\ConfigException('Config not found'));

        $command = new ShellCommand($configLoader, $containerExecutor);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Configuration error: Config not found', $tester->getDisplay());
    }
}
