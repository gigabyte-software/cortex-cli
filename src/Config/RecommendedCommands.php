<?php

declare(strict_types=1);

namespace Cortex\Config;

final class RecommendedCommands
{
    /** @var array<string, array{description: string, example: string}> */
    public const COMMANDS = [
        'clear' => [
            'description' => 'clear caches, install deps, run migrations',
            'example' => 'composer install && php artisan migrate && php artisan optimize:clear',
        ],
        'fresh' => [
            'description' => 'drop tables, re-migrate, re-seed, install deps, clear caches',
            'example' => 'composer install && php artisan migrate:fresh --seed && php artisan optimize:clear',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::COMMANDS);
    }
}
