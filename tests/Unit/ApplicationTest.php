<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit;

use Cortex\Application;
use Cortex\Command\N8n\ImportCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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

    /**
     * Running a read-only/utility command inside a directory that contains a cortex.yml
     * must not create or mutate AGENTS.md. Tab completion (_complete), list, help, etc.
     * should never write to the project.
     */
    public function test_list_command_does_not_write_agents_md(): void
    {
        $originalCwd = getcwd();
        $this->assertIsString($originalCwd);

        $tmp = sys_get_temp_dir() . '/cortex-app-test-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0o755, true);

        try {
            file_put_contents($tmp . '/cortex.yml', "project: tmp\nservices: []\n");
            chdir($tmp);

            $app = new Application();
            $app->setAutoExit(false);
            $app->setCatchExceptions(false);

            $exit = $app->run(new ArrayInput(['command' => 'list']), new BufferedOutput());
            $this->assertSame(0, $exit);
            $this->assertFileDoesNotExist($tmp . '/AGENTS.md');
        } finally {
            chdir($originalCwd);
            @unlink($tmp . '/cortex.yml');
            @unlink($tmp . '/AGENTS.md');
            @rmdir($tmp);
        }
    }
}
