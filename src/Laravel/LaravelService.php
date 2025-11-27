<?php

declare(strict_types=1);

namespace Cortex\Laravel;

use Cortex\Docker\ContainerExecutor;
use Symfony\Component\Process\Process;

final class LaravelService
{
    public function __construct(
        private readonly ContainerExecutor $containerExecutor
    ) {
    }

    /**
     * Check if Laravel artisan file exists in the container
     */
    public function hasArtisan(string $composeFile, string $service, ?string $namespace): bool
    {
        $checkProcess = $this->containerExecutor->exec(
            $composeFile,
            $service,
            '[ -f artisan ] || [ -f /var/www/html/artisan ] || [ -f /app/artisan ]',
            10,
            null,
            $namespace
        );

        return $checkProcess->isSuccessful();
    }

    /**
     * Clear application caches
     */
    public function clearCaches(string $composeFile, string $service, ?string $namespace): bool
    {
        $clearProcess = $this->containerExecutor->exec(
            $composeFile,
            $service,
            'php artisan optimize:clear',
            120,
            null,
            $namespace
        );

        return $clearProcess->isSuccessful();
    }

    /**
     * Reset development database
     */
    public function resetDatabase(string $composeFile, string $service, ?string $namespace): bool
    {
        $migrateProcess = $this->containerExecutor->exec(
            $composeFile,
            $service,
            'php artisan migrate:fresh --seed',
            300,
            null,
            $namespace
        );

        return $migrateProcess->isSuccessful();
    }
}

