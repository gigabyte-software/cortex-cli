<?php

declare(strict_types=1);

namespace Cortex\Docker;

use Cortex\Docker\Exception\ServiceNotHealthyException;
use Symfony\Component\Process\Process;

class HealthChecker
{
    /**
     * Check if a service is healthy
     */
    public function isHealthy(string $composeFile, string $service): bool
    {
        $status = $this->getHealthStatus($composeFile, $service);
        return $status === 'healthy' || $status === 'running';
    }

    /**
     * Wait for a service to become healthy
     * 
     * @throws ServiceNotHealthyException
     */
    public function waitForHealth(string $composeFile, string $service, int $timeout): void
    {
        $startTime = time();
        $pollInterval = 2; // Check every 2 seconds

        while (true) {
            if ($this->isHealthy($composeFile, $service)) {
                return;
            }

            $elapsed = time() - $startTime;
            if ($elapsed >= $timeout) {
                throw new ServiceNotHealthyException(
                    "Service '$service' did not become healthy within {$timeout}s. " .
                    "Check logs with: docker-compose -f $composeFile logs $service"
                );
            }

            sleep($pollInterval);
        }
    }

    /**
     * Get the health status of a service
     * 
     * @return string Status: 'healthy', 'unhealthy', 'starting', 'running', 'exited', 'unknown'
     */
    public function getHealthStatus(string $composeFile, string $service): string
    {
        // First, get the container name for this service
        $process = new Process([
            'docker-compose',
            '-f',
            $composeFile,
            'ps',
            '-q',
            $service,
        ]);
        $process->run();

        $containerId = trim($process->getOutput());
        if (empty($containerId)) {
            return 'unknown';
        }

        // Check if container has healthcheck
        $process = new Process([
            'docker',
            'inspect',
            '--format',
            '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}',
            $containerId,
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            return 'unknown';
        }

        return trim($process->getOutput());
    }
}

