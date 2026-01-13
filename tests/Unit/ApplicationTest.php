<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit;

use Cortex\Application;
use Cortex\Command\N8nImportCommand;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase
{
    public function test_n8n_import_command_is_registered(): void
    {
        $app = new Application();

        $this->assertTrue($app->has('n8n:import'));
        $command = $app->find('n8n:import');
        $this->assertInstanceOf(N8nImportCommand::class, $command);
    }
}
