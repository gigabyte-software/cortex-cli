<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\Schema\CommandDefinition;
use Cortex\Config\Schema\CortexConfig;
use Cortex\Orchestrator\CommandOrchestrator;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DynamicCommand extends Command
{
    public function __construct(
        string $name,
        private readonly CommandDefinition $commandDef,
        private readonly CortexConfig $config,
        private readonly CommandOrchestrator $orchestrator,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription($this->commandDef->description);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            $commandName = $this->getName();
            if ($commandName === null) {
                throw new \RuntimeException('Command name is not set');
            }

            $executionTime = $this->orchestrator->run($commandName, $this->config);

            $output->writeln('');
            $output->writeln(sprintf(
                '<fg=#7D55C7>Command completed successfully (%.1fs)</>',
                $executionTime
            ));
            $output->writeln('');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $formatter->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
