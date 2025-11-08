<?php

declare(strict_types=1);

namespace Cortex;

use Cortex\Command\DownCommand;
use Cortex\Command\DynamicCommand;
use Cortex\Command\InitCommand;
use Cortex\Command\SelfUpdateCommand;
use Cortex\Command\ShellCommand;
use Cortex\Command\StatusCommand;
use Cortex\Command\StyleDemoCommand;
use Cortex\Command\UpCommand;
use Cortex\Config\ConfigLoader;
use Cortex\Config\LockFile;
use Cortex\Config\Validator\ConfigValidator;
use Cortex\Docker\ComposeOverrideGenerator;
use Cortex\Docker\ContainerExecutor;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\HealthChecker;
use Cortex\Docker\NamespaceResolver;
use Cortex\Docker\PortOffsetManager;
use Cortex\Executor\HostCommandExecutor;
use Cortex\Orchestrator\CommandOrchestrator;
use Cortex\Orchestrator\SetupOrchestrator;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Output\ConsoleOutput;

class Application extends BaseApplication
{
    protected function getDefaultCommands(): array
    {
        $defaultCommands = [
            new \Symfony\Component\Console\Command\HelpCommand(),
            new \Symfony\Component\Console\Command\ListCommand(),
            new \Symfony\Component\Console\Command\CompleteCommand(), // This is the _complete command for tab completion
        ];
        
        // Only add completion dump command when NOT running as PHAR (it breaks in PHAR)
        if (!\Phar::running()) {
            $defaultCommands[] = new \Symfony\Component\Console\Command\DumpCompletionCommand();
        }
        
        return $defaultCommands;
    }

    public function __construct()
    {
        parent::__construct('Cortex CLI', '1.4.0');

        // Simple dependency injection
        $configValidator = new ConfigValidator();
        $configLoader = new ConfigLoader($configValidator);
        $dockerCompose = new DockerCompose();
        $hostExecutor = new HostCommandExecutor();
        $containerExecutor = new ContainerExecutor();
        $healthChecker = new HealthChecker();
        
        // Multi-instance support services
        $lockFile = new LockFile();
        $namespaceResolver = new NamespaceResolver();
        $portOffsetManager = new PortOffsetManager();
        $overrideGenerator = new ComposeOverrideGenerator();

        // Create output formatter for orchestrators
        $consoleOutput = new ConsoleOutput();
        $outputFormatter = new OutputFormatter($consoleOutput);

        // Create orchestrators
        $setupOrchestrator = new SetupOrchestrator(
            $dockerCompose,
            $hostExecutor,
            $healthChecker,
            $outputFormatter
        );

        $commandOrchestrator = new CommandOrchestrator($outputFormatter);

        // Register built-in commands (these take precedence over custom commands)
        $this->add(new InitCommand());
        $this->add(new UpCommand(
            $configLoader,
            $setupOrchestrator,
            $lockFile,
            $namespaceResolver,
            $portOffsetManager,
            $overrideGenerator
        ));
        $this->add(new DownCommand(
            $configLoader,
            $dockerCompose,
            $lockFile,
            $namespaceResolver,
            $overrideGenerator
        ));
        $this->add(new StatusCommand(
            $configLoader,
            $dockerCompose,
            $healthChecker,
            $lockFile,
            $namespaceResolver
        ));
        $this->add(new ShellCommand(
            $configLoader,
            $containerExecutor,
            $lockFile,
            $namespaceResolver
        ));
        $this->add(new SelfUpdateCommand());
        $this->add(new StyleDemoCommand());

        // Try to load cortex.yml and register custom commands dynamically
        try {
            $configPath = $configLoader->findConfigFile();
            $config = $configLoader->load($configPath);
            
            // Register each custom command as a real command
            foreach ($config->commands as $name => $cmdDef) {
                // Skip if command name conflicts with built-in commands
                if ($this->has($name)) {
                    continue;
                }
                
                $this->add(new DynamicCommand(
                    $name,
                    $cmdDef,
                    $config,
                    $commandOrchestrator
                ));
            }
        } catch (\Exception $e) {
            // Silently ignore if no cortex.yml found
            // User might be running from wrong directory or checking version/help
        }
    }
}

