<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit;

use Cortex\Application;
use Cortex\Command\N8n\ImportCommand;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase
{
    public function test_n8n_import_command_is_registered(): void
    {
        $app = new Application();

        $this->assertTrue($app->has('n8n:import'));
        $command = $app->find('n8n:import');
        $this->assertInstanceOf(ImportCommand::class, $command);
    }

    public function test_sync_agents_command_is_registered(): void
    {
        $app = new Application();

        $this->assertTrue($app->has('sync-agents'));
    }

    public function test_init_github_actions_command_is_registered(): void
    {
        $app = new Application();

        $this->assertTrue($app->has('init-github-actions'));
    }
}
