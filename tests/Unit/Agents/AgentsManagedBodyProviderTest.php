<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Cortex\Agents\AgentsManagedBodyProvider;
use PHPUnit\Framework\TestCase;

class AgentsManagedBodyProviderTest extends TestCase
{
    public function test_get_markdown_is_non_empty_and_includes_workflow(): void
    {
        $provider = new AgentsManagedBodyProvider();
        $markdown = $provider->getMarkdown();

        $this->assertNotSame('', trim($markdown));
        $this->assertStringContainsString('Development environment', $markdown);
        $this->assertStringContainsString('Shared Steps', $markdown);
    }

    public function test_get_markdown_includes_branch_pr_and_completion_conventions(): void
    {
        $provider = new AgentsManagedBodyProvider();
        $markdown = $provider->getMarkdown();

        $this->assertStringContainsString('Branch naming', $markdown);
        $this->assertStringContainsString('Never open draft PRs', $markdown);
        $this->assertStringContainsString('completion.md', $markdown);
        $this->assertStringContainsString('Click to Test', $markdown);
    }

    public function test_ticket_folder_path_is_under_dot_cortex(): void
    {
        $provider = new AgentsManagedBodyProvider();
        $markdown = $provider->getMarkdown();

        $this->assertStringContainsString('.cortex/tickets/[ticket-id]/', $markdown);
        $this->assertStringNotContainsString('`tickets/[ticket-id]/', $markdown);
    }
}
