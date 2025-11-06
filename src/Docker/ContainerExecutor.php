<?php

declare(strict_types=1);

namespace Cortex\Docker;

use Symfony\Component\Process\Process;

class ContainerExecutor
{
    /**
     * Execute a command inside a Docker container
     * 
     * @param callable|null $outputCallback Optional callback for real-time output
     * @throws \RuntimeException
     */
    public function exec(
        string $composeFile,
        string $service,
        string $command,
        int $timeout = 60,
        ?callable $outputCallback = null
    ): Process {
        // Use docker-compose exec to run command in container
        $process = new Process([
            'docker-compose',
            '-f',
            $composeFile,
            'exec',
            '-T', // Disable pseudo-TTY allocation for non-interactive
            $service,
            'sh',
            '-c',
            $command,
        ]);

        $process->setTimeout($timeout);
        
        if ($outputCallback !== null) {
            $process->run($outputCallback);
        } else {
            $process->run();
        }

        return $process;
    }

    /**
     * Execute an interactive command (like opening a shell)
     * 
     * @return int Exit code from the command
     */
    public function execInteractive(string $composeFile, string $service, string $command): int
    {
        // For interactive commands, use passthru to maintain TTY
        $escapedFile = escapeshellarg($composeFile);
        $escapedService = escapeshellarg($service);
        // Don't escape command to allow proper shell interaction
        
        $resultCode = 0;
        passthru("docker-compose -f $escapedFile exec $escapedService $command", $resultCode);
        
        return $resultCode;
    }

    /**
     * Execute an interactive command with environment variables
     * 
     * @param array<string, string> $envVars Environment variables to pass
     * @return int Exit code from the command
     */
    public function execInteractiveWithEnv(string $composeFile, string $service, string $command, array $envVars = []): int
    {
        // For interactive commands, use passthru to maintain TTY
        $escapedFile = escapeshellarg($composeFile);
        $escapedService = escapeshellarg($service);
        
        // Build environment variable flags
        $envFlags = '';
        foreach ($envVars as $key => $value) {
            $envFlags .= ' -e ' . escapeshellarg($key . '=' . $value);
        }
        
        $resultCode = 0;
        passthru("docker-compose -f $escapedFile exec$envFlags $escapedService $command", $resultCode);
        
        return $resultCode;
    }
}

