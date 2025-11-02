<?php

declare(strict_types=1);

namespace Cortex;

use Cortex\Command\DownCommand;
use Cortex\Command\StatusCommand;
use Cortex\Command\StyleDemoCommand;
use Cortex\Command\UpCommand;
use Cortex\Config\ConfigLoader;
use Cortex\Config\Validator\ConfigValidator;
use Cortex\Docker\ContainerExecutor;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\HealthChecker;
use Cortex\Executor\HostCommandExecutor;
use Symfony\Component\Console\Application as BaseApplication;

class Application extends BaseApplication
{
    public function __construct()
    {
        parent::__construct('Cortex CLI', '1.0.0');

        // Simple dependency injection
        $configValidator = new ConfigValidator();
        $configLoader = new ConfigLoader($configValidator);
        $dockerCompose = new DockerCompose();
        $hostExecutor = new HostCommandExecutor();
        $containerExecutor = new ContainerExecutor();
        $healthChecker = new HealthChecker();

        // Register commands
        $this->add(new UpCommand(
            $configLoader,
            $dockerCompose,
            $hostExecutor,
            $containerExecutor,
            $healthChecker
        ));
        $this->add(new DownCommand($configLoader, $dockerCompose));
        $this->add(new StatusCommand($configLoader, $dockerCompose, $healthChecker));
        $this->add(new StyleDemoCommand());
    }
}

