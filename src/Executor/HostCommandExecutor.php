<?php

declare(strict_types=1);

namespace Cortex\Executor;

use Cortex\Config\Schema\CommandDefinition;
use Cortex\Executor\Result\ExecutionResult;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class HostCommandExecutor
{
    /**
     * Execute a command on the host machine
     * 
     * @param callable|null $outputCallback Optional callback for real-time output
     */
    public function execute(CommandDefinition $cmd, ?callable $outputCallback = null): ExecutionResult
    {
        $startTime = microtime(true);

        $process = Process::fromShellCommandline($cmd->command);
        $process->setTimeout($cmd->timeout);
        
        try {
            if ($outputCallback !== null) {
                // Run with real-time output streaming
                $process->run($outputCallback);
            } else {
                // Run without streaming
                $process->run();
            }
        } catch (ProcessTimedOutException $e) {
            // Process timed out - continue to return result with failure status
        }

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

