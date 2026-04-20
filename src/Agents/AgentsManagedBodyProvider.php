<?php

declare(strict_types=1);

namespace Cortex\Agents;

use Cortex\Support\TicketInstructionsMarkdownCompiler;
use Cortex\Templates\TemplateDirectory;

/**
 * Builds the inner markdown for the Cortex-managed AGENTS.md block (without markers).
 */
final class AgentsManagedBodyProvider
{
    public function __construct(
        private readonly TicketInstructionsMarkdownCompiler $workflowCompiler = new TicketInstructionsMarkdownCompiler(),
    ) {
    }

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

        $workflow = trim($this->workflowCompiler->compile($templatesRoot));
        if ($workflow !== '') {
            $sections[] = $workflow;
        }

        return implode("\n\n---\n\n", $sections);
    }
}
