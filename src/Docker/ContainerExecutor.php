<?php

declare(strict_types=1);

namespace Cortex\Docker;

use Symfony\Component\Process\Process;

class ContainerExecutor
{
    /**
     * Execute a command inside a Docker container
     * 
     * @throws \RuntimeException
     */
    public function exec(string $composeFile, string $service, string $command, int $timeout = 60): Process
    {
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
        $process->run();

        return $process;
    }

    /**
     * Execute an interactive command (like opening a shell)
     */
    public function execInteractive(string $composeFile, string $service, string $command): void
    {
        // For interactive commands, use passthru to maintain TTY
        $escapedFile = escapeshellarg($composeFile);
        $escapedService = escapeshellarg($service);
        $escapedCommand = escapeshellarg($command);

        passthru("docker-compose -f $escapedFile exec $escapedService $escapedCommand");
    }
}

