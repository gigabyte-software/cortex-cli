<?php

declare(strict_types=1);

namespace Cortex\Config;

use Cortex\Config\Schema\CortexConfig;

class ConfigWarningChecker
{
    /**
     * Check config for missing or unconfigured recommended commands.
     *
     * @return list<string>
     */
    public function check(CortexConfig $config): array
    {
        $warnings = [];

        foreach (RecommendedCommands::COMMANDS as $name => $meta) {
            if (!isset($config->commands[$name])) {
                $warnings[] = "Recommended command '$name' is not defined in cortex.yml — {$meta['description']}";
                continue;
            }

            if (trim($config->commands[$name]->command) === '') {
                $warnings[] = "Recommended command '$name' has an empty command string in cortex.yml — define it to use this workflow";
            }
        }

        return $warnings;
    }
}
