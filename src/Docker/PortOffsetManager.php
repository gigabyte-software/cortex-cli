<?php

declare(strict_types=1);

namespace Cortex\Docker;

use Symfony\Component\Yaml\Yaml;

/**
 * Manages port offset allocation and conflict detection
 */
class PortOffsetManager
{
    private const DEFAULT_SCAN_START = 8000;
    private const DEFAULT_SCAN_END = 9000;

    /**
     * Extract base ports from docker-compose.yml
     *
     * @return int[] Array of base port numbers
     */
    public function extractBasePorts(string $composeFile): array
    {
        if (!file_exists($composeFile)) {
            return [];
        }

        $content = file_get_contents($composeFile);
        if ($content === false) {
            return [];
        }

        $config = Yaml::parse($content);

        $ports = [];

        if (!isset($config['services'])) {
            return [];
        }

        foreach ($config['services'] as $service) {
            if (!isset($service['ports'])) {
                continue;
            }

            foreach ($service['ports'] as $portMapping) {
                $port = $this->parsePortMapping($portMapping);
                if ($port !== null) {
                    $ports[] = $port;
                }
            }
        }

        return array_unique($ports);
    }

    /**
     * Find an available port offset by scanning for conflicts
     *
     * @param int[] $basePorts Base ports to check
     * @return int Available port offset
     * @throws \RuntimeException If no available offset found
     */
    public function findAvailableOffset(array $basePorts): int
    {
        if (empty($basePorts)) {
            return 0;
        }

        // First check if base ports are available (offset = 0)
        if ($this->arePortsAvailable($basePorts, 0)) {
            return 0;
        }

        // Scan for available offset
        for ($offset = self::DEFAULT_SCAN_START; $offset <= self::DEFAULT_SCAN_END; $offset += 100) {
            if ($this->arePortsAvailable($basePorts, $offset)) {
                return $offset;
            }
        }

        throw new \RuntimeException(
            sprintf(
                'No available port offset found in range %d-%d. Please stop other services or specify a custom --port-offset.',
                self::DEFAULT_SCAN_START,
                self::DEFAULT_SCAN_END
            )
        );
    }

    /**
     * Check if all ports with given offset are available
     *
     * @param int[] $basePorts
     * @param int $offset
     * @return bool
     */
    private function arePortsAvailable(array $basePorts, int $offset): bool
    {
        foreach ($basePorts as $basePort) {
            $port = $basePort + $offset;
            if (!$this->isPortAvailable($port)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if a specific port is available on the host
     */
    private function isPortAvailable(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

        if ($socket !== false) {
            fclose($socket);
            return false; // Port is in use
        }

        return true; // Port is available
    }

    /**
     * Parse port mapping string to extract host port number
     *
     * Supports formats:
     *   - "80:80"
     *   - "8080:80"
     *   - "127.0.0.1:8080:80"
     *   - Array format: {"target": 80, "published": 8080}
     */
    private function parsePortMapping(mixed $portMapping): ?int
    {
        if (is_string($portMapping)) {
            // Handle "80:80" or "127.0.0.1:80:80" format
            $parts = explode(':', $portMapping);

            if (count($parts) === 2) {
                // "80:80" format - host port is first
                return (int) $parts[0];
            } elseif (count($parts) === 3) {
                // "127.0.0.1:80:80" format - host port is second
                return (int) $parts[1];
            }
        } elseif (is_array($portMapping)) {
            // Long format: {target: 80, published: 8080}
            return isset($portMapping['published']) ? (int) $portMapping['published'] : null;
        }

        return null;
    }
}
