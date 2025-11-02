<?php

declare(strict_types=1);

namespace Cortex\Docker;

use Symfony\Component\Process\Process;

class DockerCompose
{
    /**
     * Start Docker Compose services
     * 
     * @throws \RuntimeException
     */
    public function up(string $composeFile): void
    {
        $process = new Process(['docker-compose', '-f', $composeFile, 'up', '-d']);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Failed to start Docker Compose services: {$process->getErrorOutput()}"
            );
        }
    }

    /**
     * Stop Docker Compose services
     * 
     * @throws \RuntimeException
     */
    public function down(string $composeFile, bool $volumes = false): void
    {
        $command = ['docker-compose', '-f', $composeFile, 'down'];
        
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
     * @return array<string, array<string, string>>
     */
    public function ps(string $composeFile): array
    {
        $process = new Process(['docker-compose', '-f', $composeFile, 'ps', '--format', 'json']);
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
     */
    public function isRunning(string $composeFile): bool
    {
        return !empty($this->ps($composeFile));
    }
}

