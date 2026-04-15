<?php

declare(strict_types=1);

namespace Cortex;

use Cortex\Command\DownCommand;
use Cortex\Command\DynamicCommand;
use Cortex\Command\InitCommand;
use Cortex\Command\RebuildCommand;
use Cortex\Command\LogsCommand;
use Cortex\Command\ReviewCommand;
use Cortex\Command\SecureCommand;
use Cortex\Command\SelfUpdateCommand;
use Cortex\Command\ShellCommand;
use Cortex\Command\ShowUrlCommand;
use Cortex\Command\StatusCommand;
use Cortex\Command\StyleDemoCommand;
use Cortex\Command\UpCommand;
use Cortex\Command\N8n\ExportCommand;
use Cortex\Command\N8n\ImportCommand;
use Cortex\Command\N8n\NormaliseCommand;
use Cortex\Config\ConfigLoader;
use Cortex\Config\ConfigWarningChecker;
use Cortex\Config\LockFile;
use Cortex\Config\Validator\ConfigValidator;
use Cortex\Docker\ComposeOverrideGenerator;
use Cortex\Docker\ContainerExecutor;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\HealthChecker;
use Cortex\Docker\NamespaceResolver;
use Cortex\Docker\PortOffsetManager;
use Cortex\Herd\HerdService;
use Cortex\Executor\HostCommandExecutor;
use Cortex\Git\GitRepositoryService;
use Cortex\Laravel\LaravelLogParser;
use Cortex\Laravel\LaravelService;
use Cortex\Orchestrator\CommandOrchestrator;
use Cortex\Orchestrator\SetupOrchestrator;
use Cortex\Output\OutputFormatter;
use GuzzleHttp\Client;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class Application extends BaseApplication
{
    private const SKIP_WARNINGS_FOR = [
        'init', 'self-update', 'list', 'help', '_complete', 'completion', 'style-demo',
        'up', 'rebuild',
    ];

    /** @var list<string> */
    private array $configWarnings = [];

    /** @return list<string> */
    public function getConfigWarnings(): array
    {
        return $this->configWarnings;
    }
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
        parent::__construct('Cortex CLI', '2.5.0');

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
        $herdService = new HerdService();

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

        // Create Git and Laravel services
        $gitRepositoryService = new GitRepositoryService();
        $laravelService = new LaravelService($containerExecutor);
        $logParser = new LaravelLogParser();

        // Register built-in commands (these take precedence over custom commands)
        $this->add(new InitCommand());
        $this->add(new UpCommand(
            $configLoader,
            $setupOrchestrator,
            $lockFile,
            $namespaceResolver,
            $portOffsetManager,
            $overrideGenerator,
            $dockerCompose,
            $herdService
        ));
        $this->add(new DownCommand(
            $configLoader,
            $dockerCompose,
            $lockFile,
            $overrideGenerator,
            $herdService
        ));
        $this->add(new ReviewCommand(
            $configLoader,
            $dockerCompose,
            $lockFile,
            $gitRepositoryService,
            $laravelService
        ));
        $this->add(new StatusCommand(
            $configLoader,
            $dockerCompose,
            $healthChecker,
            $lockFile
        ));
        $this->add(new ShellCommand(
            $configLoader,
            $containerExecutor,
            $lockFile
        ));
        $this->add(new LogsCommand(
            $configLoader,
            $containerExecutor,
            $lockFile,
            $laravelService,
            $logParser
        ));
        $this->add(new SelfUpdateCommand());
        $this->add(new ShowUrlCommand(
            $configLoader,
            $lockFile,
            $portOffsetManager
        ));
        $this->add(new SecureCommand($configLoader));
        $this->add(new StyleDemoCommand());

        // Create HTTP client for n8n export command
        $httpClient = new Client([
            'timeout' => 10,
            'verify' => false,
        ]);

        $this->add(new ExportCommand(
            $configLoader,
            $httpClient
        ));

        $this->add(new ImportCommand(
            $configLoader,
            $httpClient
        ));

        $this->add(new NormaliseCommand(
            $configLoader,
            $httpClient
        ));

        $this->add(new RebuildCommand(
            $configLoader,
            $dockerCompose,
            $healthChecker,
            $commandOrchestrator,
            $lockFile,
            $overrideGenerator
        ));

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

            // Check for missing recommended commands
            $warningChecker = new ConfigWarningChecker();
            $this->configWarnings = $warningChecker->check($config);
        } catch (\Exception $e) {
            // Silently ignore if no cortex.yml found
            // User might be running from wrong directory or checking version/help
        }
    }

    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        if ($this->configWarnings !== [] && !in_array($command->getName(), self::SKIP_WARNINGS_FOR, true)) {
            $formatter = new OutputFormatter($output);
            foreach ($this->configWarnings as $warning) {
                $formatter->warning("  ⚠ $warning");
            }
            $output->writeln('');
        }

        return parent::doRunCommand($command, $input, $output);
    }
}
