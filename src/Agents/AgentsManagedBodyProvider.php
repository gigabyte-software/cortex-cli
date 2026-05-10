<?php

declare(strict_types=1);

namespace Cortex\Agents;

use Cortex\Templates\TemplateDirectory;

/**
 * Builds the inner markdown for the Cortex-managed AGENTS.md block (without markers).
 *
 * Currently sources content only from templates/agents/*.md. The longer-form
 * ticket workflow templates under templates/steps and templates/ticket-types
 * are intentionally NOT included here — they describe a workflow we are not
 * actively running, but are kept on disk so we can revive them (e.g. when we
 * incorporate specs) without recreating them.
 */
final class AgentsManagedBodyProvider
{
    public function getMarkdown(): string
    {
        $templatesRoot = TemplateDirectory::resolve();
        $agentsDir = $templatesRoot . '/agents';

        $sections = [];

        if (is_dir($agentsDir)) {
            $files = glob($agentsDir . '/[0-9][0-9]-*.md') ?: [];
            sort($files, SORT_STRING);

            foreach ($files as $file) {
                $chunk = file_get_contents($file);
                if ($chunk !== false && trim($chunk) !== '') {
                    $sections[] = rtrim($chunk);
                }
            }
        }

        return implode("\n\n---\n\n", $sections);
    }
}
