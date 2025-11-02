<?php

declare(strict_types=1);

namespace Cortex\Executor;

use Cortex\Config\Schema\CommandDefinition;
use Cortex\Docker\ContainerExecutor;
use Cortex\Executor\Result\ExecutionResult;

class ContainerCommandExecutor
{
    public function __construct(
        private readonly ContainerExecutor $containerExecutor,
        private readonly string $composeFile,
        private readonly string $service,
    ) {
    }

    /**
     * Execute a command inside the Docker container
     */
    public function execute(CommandDefinition $cmd): ExecutionResult
    {
        $startTime = microtime(true);

        $process = $this->containerExecutor->exec(
            composeFile: $this->composeFile,
            service: $this->service,
            command: $cmd->command,
            timeout: $cmd->timeout,
        );

        $executionTime = microtime(true) - $startTime;

        return new ExecutionResult(
            exitCode: $process->getExitCode() ?? -1,
            output: $process->getOutput(),
            errorOutput: $process->getErrorOutput(),
            successful: $process->isSuccessful(),
            executionTime: $executionTime,
        );
    }
}

