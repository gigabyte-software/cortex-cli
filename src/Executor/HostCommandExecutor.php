<?php

declare(strict_types=1);

namespace Cortex\Executor;

use Cortex\Config\Schema\CommandDefinition;
use Cortex\Executor\Result\ExecutionResult;
use Symfony\Component\Process\Process;

class HostCommandExecutor
{
    /**
     * Execute a command on the host machine
     */
    public function execute(CommandDefinition $cmd): ExecutionResult
    {
        $startTime = microtime(true);

        $process = Process::fromShellCommandline($cmd->command);
        $process->setTimeout($cmd->timeout);
        $process->run();

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

