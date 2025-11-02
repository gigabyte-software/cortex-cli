<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit\Config;

use Cortex\Config\Exception\ConfigException;
use Cortex\Config\Validator\ConfigValidator;
use PHPUnit\Framework\TestCase;

class ConfigValidatorTest extends TestCase
{
    private ConfigValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ConfigValidator();
    }

    public function test_it_validates_valid_config(): void
    {
        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
            ],
        ];

        $this->validator->validate($config);
        $this->assertTrue(true); // If no exception, validation passed
    }

    public function test_it_throws_exception_for_missing_version(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Missing required field: version');

        $config = [
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_throws_exception_for_missing_docker_section(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Missing required field: docker');

        $config = [
            'version' => '1.0',
        ];

        $this->validator->validate($config);
    }

    public function test_it_throws_exception_for_missing_compose_file(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Missing required field: docker.compose_file');

        $config = [
            'version' => '1.0',
            'docker' => [
                'primary_service' => 'app',
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_throws_exception_for_missing_primary_service(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Missing required field: docker.primary_service');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_validates_wait_for_section(): void
    {
        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'wait_for' => [
                    [
                        'service' => 'db',
                        'timeout' => 60,
                    ],
                ],
            ],
        ];

        $this->validator->validate($config);
        $this->assertTrue(true);
    }

    public function test_it_throws_exception_for_invalid_timeout(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('timeout must be a positive integer');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
                'wait_for' => [
                    [
                        'service' => 'db',
                        'timeout' => -1,
                    ],
                ],
            ],
        ];

        $this->validator->validate($config);
    }

    public function test_it_validates_command_definitions(): void
    {
        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
            ],
            'setup' => [
                'initialize' => [
                    [
                        'command' => 'composer install',
                        'description' => 'Install dependencies',
                        'timeout' => 300,
                        'retry' => 2,
                    ],
                ],
            ],
        ];

        $this->validator->validate($config);
        $this->assertTrue(true);
    }

    public function test_it_throws_exception_for_missing_command(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('missing required field: command');

        $config = [
            'version' => '1.0',
            'docker' => [
                'compose_file' => 'docker-compose.yml',
                'primary_service' => 'app',
            ],
            'setup' => [
                'initialize' => [
                    [
                        'description' => 'Install dependencies',
                    ],
                ],
            ],
        ];

        $this->validator->validate($config);
    }
}

