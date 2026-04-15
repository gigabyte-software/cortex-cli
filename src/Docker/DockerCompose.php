<?php

declare(strict_types=1);

namespace Cortex\Docker;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class DockerCompose
{
    /**
     * Start Docker Compose services
     *
     * @param string $composeFile Path to docker-compose.yml
     * @param string|null $projectName Optional project name for container isolation
     * @throws \RuntimeException
     */
    public function up(string $composeFile, ?string $projectName = null, bool $rebuild = false, ?int $timeout = null): void
    {
        $command = ['docker-compose', '-f', $composeFile];

        // Add override file if it exists
        $overrideFile = dirname($composeFile) . '/docker-compose.override.yml';
        if (file_exists($overrideFile)) {
            $command[] = '-f';
            $command[] = $overrideFile;
        }

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        $command[] = 'up';
        $command[] = '-d';

        if ($rebuild) {
            $command[] = '--build';
        }

        // #region agent log
        try {
            $logPath = '/home/kryten/gigabyte/projects/.cursor/debug-ae03ef.log';
            $logEntry = [
                'sessionId' => 'ae03ef',
                'runId' => 'pre-fix',
                'hypothesisId' => 'A',
                'location' => 'src/Docker/DockerCompose.php:up:before',
                'message' => 'DockerCompose up starting',
                'data' => [
                    'composeFile' => $composeFile,
                    'projectName' => $projectName,
                    'cwd' => getcwd() ?: null,
                    'home' => getenv('HOME') ?: null,
                    'dockerConfig' => getenv('DOCKER_CONFIG') ?: null,
                    'command' => $command,
                ],
                'timestamp' => (int) (microtime(true) * 1000),
            ];
            @file_put_contents($logPath, json_encode($logEntry) . "\n", FILE_APPEND);
        } catch (\Throwable) {
            // Best-effort debug logging; ignore all failures
        }
        // #endregion agent log

        $effectiveTimeout = $timeout ?? ($rebuild ? 1500 : 300);

        $process = new Process($command);
        $process->setTimeout($effectiveTimeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new \RuntimeException(
                "Docker Compose timed out after {$effectiveTimeout}s. Use --timeout to increase the limit (e.g. --timeout 3600)."
            );
        }

        if (!$process->isSuccessful()) {
            // #region agent log
            try {
                $logPath = '/home/kryten/gigabyte/projects/.cursor/debug-ae03ef.log';
                $logEntry = [
                    'sessionId' => 'ae03ef',
                    'runId' => 'pre-fix',
                    'hypothesisId' => 'A',
                    'location' => 'src/Docker/DockerCompose.php:up:after',
                    'message' => 'DockerCompose up failed',
                    'data' => [
                        'exitCode' => $process->getExitCode(),
                        'errorOutput' => $process->getErrorOutput(),
                        'standardOutput' => $process->getOutput(),
                    ],
                    'timestamp' => (int) (microtime(true) * 1000),
                ];
                @file_put_contents($logPath, json_encode($logEntry) . "\n", FILE_APPEND);
            } catch (\Throwable) {
                // Best-effort debug logging; ignore all failures
            }
            // #endregion agent log

            throw new \RuntimeException(
                "Failed to start Docker Compose services: {$process->getErrorOutput()}"
            );
        }
    }

    /**
     * Rebuild images and start Docker Compose services
     *
     * @param string $composeFile Path to docker-compose.yml
     * @param string|null $projectName Optional project name for container isolation
     * @throws \RuntimeException
     */
    public function upWithBuild(string $composeFile, ?string $projectName = null): void
    {
        $command = ['docker-compose', '-f', $composeFile];

        $overrideFile = dirname($composeFile) . '/docker-compose.override.yml';
        if (file_exists($overrideFile)) {
            $command[] = '-f';
            $command[] = $overrideFile;
        }

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        $command[] = 'up';
        $command[] = '-d';
        $command[] = '--build';

        $process = new Process($command);
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Failed to rebuild Docker Compose services: {$process->getErrorOutput()}"
            );
        }
    }

    /**
     * Stop Docker Compose services
     *
     * @param string $composeFile Path to docker-compose.yml
     * @param bool $volumes Remove volumes as well
     * @param string|null $projectName Optional project name for container isolation
     * @throws \RuntimeException
     */
    public function down(string $composeFile, bool $volumes = false, ?string $projectName = null): void
    {
        $command = ['docker-compose', '-f', $composeFile];

        // Add override file if it exists
        $overrideFile = dirname($composeFile) . '/docker-compose.override.yml';
        if (file_exists($overrideFile)) {
            $command[] = '-f';
            $command[] = $overrideFile;
        }

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        $command[] = 'down';

        if ($volumes) {
            $command[] = '-v';
        }

        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Failed to stop Docker Compose services: {$process->getErrorOutput()}"
            );
        }
    }

    /**
     * List running services
     *
     * @param string $composeFile Path to docker-compose.yml
     * @param string|null $projectName Optional project name for container isolation
     * @return array<string, array<string, string>>
     */
    public function ps(string $composeFile, ?string $projectName = null): array
    {
        $command = ['docker-compose', '-f', $composeFile];

        // Add override file if it exists
        $overrideFile = dirname($composeFile) . '/docker-compose.override.yml';
        if (file_exists($overrideFile)) {
            $command[] = '-f';
            $command[] = $overrideFile;
        }

        if ($projectName !== null) {
            $command[] = '-p';
            $command[] = $projectName;
        }

        $command[] = 'ps';
        $command[] = '--format';
        $command[] = 'json';

        $process = new Process($command);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        $output = trim($process->getOutput());
        if (empty($output)) {
            return [];
        }

        $services = [];
        foreach (explode("\n", $output) as $line) {
            $data = json_decode($line, true);
            if ($data !== null && isset($data['Service'])) {
                $services[$data['Service']] = $data;
            }
        }

        return $services;
    }

    /**
     * Check if any services are running
     *
     * @param string $composeFile Path to docker-compose.yml
     * @param string|null $projectName Optional project name for container isolation
     */
    public function isRunning(string $composeFile, ?string $projectName = null): bool
    {
        return !empty($this->ps($composeFile, $projectName));
    }
}
