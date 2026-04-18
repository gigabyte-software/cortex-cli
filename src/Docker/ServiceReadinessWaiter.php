<?php

declare(strict_types=1);

namespace Cortex\Docker;

use Cortex\Config\Schema\ServiceWaitConfig;
use Cortex\Docker\Exception\ServiceNotHealthyException;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleSectionOutput;

/**
 * Waits for a set of Docker Compose services to become healthy while
 * rendering a live, in-place status panel with rolling log excerpts.
 *
 * Each service shows a single status line followed by up to {@see LOG_LINES}
 * lines of recent container output (dim grey). Once a service is healthy the
 * log excerpt for it disappears, leaving only the final status.
 */
class ServiceReadinessWaiter
{
    private const LOG_LINES = 3;
    private const POLL_INTERVAL_SECONDS = 2;

    public function __construct(
        private readonly DockerCompose $dockerCompose,
        private readonly HealthChecker $healthChecker,
        private readonly OutputFormatter $formatter,
    ) {
    }

    /**
     * Poll each service until healthy or timed out, rendering live status.
     *
     * @param ServiceWaitConfig[] $waitFor
     *
     * @throws ServiceNotHealthyException if any service exceeds its (possibly
     *         multiplied) timeout.
     */
    public function waitForAll(
        string $composeFile,
        array $waitFor,
        ?string $namespace = null,
        int $timeoutMultiplier = 1
    ): void {
        if ($waitFor === []) {
            return;
        }

        $section = $this->formatter->createSection();

        $serviceState = [];
        $startTimes = [];
        $resolvedTimes = [];

        foreach ($waitFor as $waitConfig) {
            $serviceState[$waitConfig->service] = [
                'status' => 'waiting',
                'elapsed' => null,
                'logLines' => [],
            ];
            $startTimes[$waitConfig->service] = microtime(true);
        }

        while (true) {
            $allHealthy = true;

            foreach ($waitFor as $waitConfig) {
                $service = $waitConfig->service;

                if (isset($resolvedTimes[$service])) {
                    continue;
                }

                $status = $this->healthChecker->getHealthStatus($composeFile, $service, $namespace);
                $isHealthy = ($status === 'healthy' || $status === 'running');

                if ($isHealthy) {
                    $elapsed = microtime(true) - $startTimes[$service];
                    $resolvedTimes[$service] = $elapsed;
                    $serviceState[$service] = [
                        'status' => $status,
                        'elapsed' => $elapsed,
                        'logLines' => [],
                    ];
                    continue;
                }

                $allHealthy = false;
                $logLines = $this->dockerCompose->getLatestLogLines(
                    $composeFile,
                    $service,
                    self::LOG_LINES,
                    $namespace
                );
                $serviceState[$service] = [
                    'status' => $status !== '' ? $status : 'waiting',
                    'elapsed' => null,
                    'logLines' => $logLines,
                ];

                $effectiveTimeout = $waitConfig->timeout * $timeoutMultiplier;
                $elapsed = microtime(true) - $startTimes[$service];
                if ($elapsed >= $effectiveTimeout) {
                    if ($section !== null) {
                        $this->formatter->renderServiceStatus($section, $serviceState);
                    }

                    $projectFlag = $namespace !== null ? " -p $namespace" : '';
                    throw new ServiceNotHealthyException(
                        "Service '$service' did not become healthy within {$effectiveTimeout}s. " .
                        "Check logs with: docker-compose -f $composeFile$projectFlag logs $service"
                    );
                }
            }

            if ($section !== null) {
                $this->formatter->renderServiceStatus($section, $serviceState);
            } elseif ($allHealthy) {
                // Non-interactive fallback: print once when everything is ready.
                foreach ($serviceState as $name => $info) {
                    $elapsed = $info['elapsed'] ?? 0.0;
                    $this->formatter->info(sprintf('%s (%s after %.1fs)', $name, $info['status'], $elapsed));
                }
            }

            if ($allHealthy) {
                break;
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }

        if ($section instanceof ConsoleSectionOutput) {
            // Render the final healthy state persistently (no log excerpts)
            // then leave it in the scroll-back.
            $this->formatter->renderServiceStatus($section, $serviceState);
        }
    }
}
