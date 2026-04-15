<?php

declare(strict_types=1);

namespace Cortex\Config\Validator;

use Cortex\Config\Schema\SecretsConfig;

class SecretsValidator
{
    /**
     * Validate that all required secrets are available.
     *
     * @return string[] List of missing secret names (empty if all present)
     */
    public function validate(SecretsConfig $secrets): array
    {
        if (empty($secrets->required)) {
            return [];
        }

        $missing = [];
        foreach ($secrets->required as $name) {
            if ($this->getEnvVar($name) === false) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    protected function getEnvVar(string $name): string|false
    {
        return getenv($name);
    }
}
