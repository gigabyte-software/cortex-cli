<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Agents\AgentsMdSynchronizer;
use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Config\Validator\ConfigValidator;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncAgentsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('sync-agents')
            ->setDescription('Update the Cortex-managed section in AGENTS.md for the current Cortex project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            $configLoader = new ConfigLoader(new ConfigValidator());
            $configPath = $configLoader->findConfigFile();
            $projectRoot = dirname($configPath);
        } catch (ConfigException) {
            $formatter->error('No cortex.yml found in this directory or parent directories.');

            return Command::FAILURE;
        }

        $sync = new AgentsMdSynchronizer();
        $changed = $sync->sync($projectRoot);

        if ($changed) {
            $formatter->success('✓ AGENTS.md updated.');
        } else {
            $formatter->info('AGENTS.md is already up to date (or CORTEX_SKIP_AGENTS_SYNC is set).');
        }

        return Command::SUCCESS;
    }
}
