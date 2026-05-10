<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Cortex\Agents\AgentsManagedBodyProvider;
use PHPUnit\Framework\TestCase;

class AgentsManagedBodyProviderTest extends TestCase
{
    public function test_get_markdown_is_non_empty_and_includes_agents_sections(): void
    {
        $provider = new AgentsManagedBodyProvider();
        $markdown = $provider->getMarkdown();

        $this->assertNotSame('', trim($markdown));
        $this->assertStringContainsString('Development environment', $markdown);
    }

    public function test_get_markdown_excludes_long_form_ticket_workflow(): void
    {
        $provider = new AgentsManagedBodyProvider();
        $markdown = $provider->getMarkdown();

        $this->assertStringNotContainsString('Shared Steps', $markdown);
        $this->assertStringNotContainsString('Shared Step:', $markdown);
        $this->assertStringNotContainsString('# Ticket Types', $markdown);
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

    public function test_get_markdown_includes_linear_ticket_conventions(): void
    {
        $provider = new AgentsManagedBodyProvider();
        $markdown = $provider->getMarkdown();

        $this->assertStringContainsString('Linear Ticket Conventions', $markdown);
        $this->assertStringContainsString('Routing labels', $markdown);
        $this->assertStringContainsString('Dependencies and ordering', $markdown);
    }
}
