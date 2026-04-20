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
}
