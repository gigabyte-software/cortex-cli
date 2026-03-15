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
        int $timeout = 600,
        ?callable $outputCallback = null,
        ?string $projectName = null
    ): Process {
        // Use docker-compose exec to run command in container
        $cmd = ['docker-compose', '-f', $composeFile];

        // Add override file if it exists
        $overrideFile = dirname($composeFile) . '/docker-compose.override.yml';
        if (file_exists($overrideFile)) {
            $cmd[] = '-f';
            $cmd[] = $overrideFile;
        }

        if ($projectName !== null) {
            $cmd[] = '-p';
            $cmd[] = $projectName;
        }

        $cmd = array_merge($cmd, [
            'exec',
            '-T', // Disable pseudo-TTY allocation for non-interactive
            $service,
            'sh',
            '-c',
            $command,
        ]);

        $process = new Process($cmd);
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
    public function execInteractive(string $composeFile, string $service, string $command, ?string $projectName = null): int
    {
        $cmd = ['docker-compose', '-f', $composeFile];

        $overrideFile = dirname($composeFile) . '/docker-compose.override.yml';
        if (file_exists($overrideFile)) {
            $cmd[] = '-f';
            $cmd[] = $overrideFile;
        }

        if ($projectName !== null) {
            $cmd[] = '-p';
            $cmd[] = $projectName;
        }

        $cmd = array_merge($cmd, ['exec', $service, ...explode(' ', $command)]);

        // Use proc_open with direct FD inheritance to preserve TTY for readline
        $process = proc_open($cmd, [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($process)) {
            return 1;
        }

        return proc_close($process);
    }

    /**
     * Execute an interactive command with environment variables
     *
     * @param array<string, string> $envVars Environment variables to pass
     * @return int Exit code from the command
     */
    public function execInteractiveWithEnv(
        string $composeFile,
        string $service,
        string $command,
        array $envVars = [],
        ?string $projectName = null
    ): int {
        $cmd = ['docker-compose', '-f', $composeFile];

        $overrideFile = dirname($composeFile) . '/docker-compose.override.yml';
        if (file_exists($overrideFile)) {
            $cmd[] = '-f';
            $cmd[] = $overrideFile;
        }

        if ($projectName !== null) {
            $cmd[] = '-p';
            $cmd[] = $projectName;
        }

        $cmd[] = 'exec';

        foreach ($envVars as $key => $value) {
            $cmd[] = '-e';
            $cmd[] = $key . '=' . $value;
        }

        $cmd[] = $service;
        $cmd[] = $command;

        // Use proc_open with direct FD inheritance to preserve TTY for readline
        $process = proc_open($cmd, [STDIN, STDOUT, STDERR], $pipes);
        if (!is_resource($process)) {
            return 1;
        }

        return proc_close($process);
    }
}
