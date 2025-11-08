<?php

declare(strict_types=1);

namespace Cortex\Config\Validator;

use Cortex\Config\Exception\ConfigException;

class ConfigValidator
{
    /**
     * @param array<string, mixed> $config
     * @throws ConfigException
     */
    public function validate(array $config): void
    {
        $this->validateRequiredFields($config);
        $this->validateDockerSection($config['docker']);

        if (isset($config['setup'])) {
            $this->validateSetupSection($config['setup']);
        }

        if (isset($config['commands'])) {
            $this->validateCommandsSection($config['commands']);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @throws ConfigException
     */
    private function validateRequiredFields(array $config): void
    {
        if (!isset($config['version'])) {
            throw new ConfigException('Missing required field: version');
        }

        if (!isset($config['docker'])) {
            throw new ConfigException('Missing required field: docker');
        }
    }

    /**
     * @param array<string, mixed> $docker
     * @throws ConfigException
     */
    public function validateDockerSection(array $docker): void
    {
        if (!isset($docker['compose_file'])) {
            throw new ConfigException('Missing required field: docker.compose_file');
        }

        if (!isset($docker['primary_service'])) {
            throw new ConfigException('Missing required field: docker.primary_service');
        }

        if (!isset($docker['app_url'])) {
            throw new ConfigException('Missing required field: docker.app_url');
        }

        if (isset($docker['wait_for']) && !is_array($docker['wait_for'])) {
            throw new ConfigException('docker.wait_for must be an array');
        }

        if (isset($docker['wait_for'])) {
            foreach ($docker['wait_for'] as $index => $waitConfig) {
                if (!isset($waitConfig['service'])) {
                    throw new ConfigException("docker.wait_for[$index] missing required field: service");
                }
                if (!isset($waitConfig['timeout'])) {
                    throw new ConfigException("docker.wait_for[$index] missing required field: timeout");
                }
                if (!is_int($waitConfig['timeout']) || $waitConfig['timeout'] <= 0) {
                    throw new ConfigException("docker.wait_for[$index].timeout must be a positive integer");
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $setup
     * @throws ConfigException
     */
    public function validateSetupSection(array $setup): void
    {
        if (isset($setup['pre_start'])) {
            $this->validateCommandList($setup['pre_start'], 'setup.pre_start');
        }

        if (isset($setup['initialize'])) {
            $this->validateCommandList($setup['initialize'], 'setup.initialize');
        }
    }

    /**
     * @param array<string, mixed> $commands
     * @throws ConfigException
     */
    private function validateCommandsSection(array $commands): void
    {
        foreach ($commands as $name => $command) {
            $this->validateCommandDefinition($command, "commands.$name");
        }
    }

    /**
     * @param array<int, mixed> $commands
     * @throws ConfigException
     */
    private function validateCommandList(array $commands, string $path): void
    {
        foreach ($commands as $index => $command) {
            $this->validateCommandDefinition($command, "$path[$index]");
        }
    }

    /**
     * @param mixed $command
     * @throws ConfigException
     */
    private function validateCommandDefinition(mixed $command, string $path): void
    {
        if (!is_array($command)) {
            throw new ConfigException("$path must be an array");
        }

        if (!isset($command['command'])) {
            throw new ConfigException("$path missing required field: command");
        }

        if (!isset($command['description'])) {
            throw new ConfigException("$path missing required field: description");
        }

        if (isset($command['timeout']) && (!is_int($command['timeout']) || $command['timeout'] <= 0)) {
            throw new ConfigException("$path.timeout must be a positive integer");
        }

        if (isset($command['retry']) && (!is_int($command['retry']) || $command['retry'] < 0)) {
            throw new ConfigException("$path.retry must be a non-negative integer");
        }

        if (isset($command['ignore_failure']) && !is_bool($command['ignore_failure'])) {
            throw new ConfigException("$path.ignore_failure must be a boolean");
        }
    }
}
