<?php

declare(strict_types=1);

namespace Cortex\Docker;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class DockerCompose
{
    /**
     * Check if the Docker daemon is running and accessible.
     */
    public function isDockerRunning(): bool
    {
        $process = new Process(['docker', 'info']);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Check if Docker images already exist for this compose project.
     * Returns false on first run when everything needs to be built/pulled.
     */
    public function hasExistingImages(string $composeFile, ?string $projectName = null): bool
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

        $command[] = 'config';
        $command[] = '--images';

        $process = new Process($command);
        $process->setTimeout(15);
        $process->run();

        if (!$process->isSuccessful()) {
            return true;
        }

        $imageNames = array_filter(explode("\n", trim($process->getOutput())));
        if (empty($imageNames)) {
            return true;
        }

        foreach ($imageNames as $image) {
            $inspect = new Process(['docker', 'image', 'inspect', $image]);
            $inspect->setTimeout(5);
            $inspect->run();

            if (!$inspect->isSuccessful()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the latest log line from a service container.
     */
    public function getLatestLogLine(string $composeFile, string $service, ?string $projectName = null): ?string
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

        $command[] = 'logs';
        $command[] = '--tail=1';
        $command[] = '--no-log-prefix';
        $command[] = $service;

        $process = new Process($command);
        $process->setTimeout(5);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $line = trim($process->getOutput());
        if ($line === '') {
            $line = trim($process->getErrorOutput());
        }

        return $line !== '' ? $line : null;
    }

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
