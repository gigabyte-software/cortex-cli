<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Docker\ContainerExecutor;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShellCommand extends Command
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly ContainerExecutor $containerExecutor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('shell')
            ->setDescription('Open an interactive bash shell in the primary service container');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            // Load configuration
            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);

            $primaryService = $config->docker->primaryService;
            $composeFile = $config->docker->composeFile;

            // Build a custom PS1 prompt with Gigabyte brand colors
            // Purple (#7D55C7 - Pantone 2665C) for container name
            // Teal (#2ED9C3 - Pantone 3255C) for directory path
            // Using RGB ANSI escape codes for exact color matching
            $purple = '\\[\\033[38;2;125;85;199m\\]';   // #7D55C7
            $teal = '\\[\\033[38;2;46;217;195m\\]';     // #2ED9C3
            $reset = '\\[\\033[0m\\]';                  // Reset color
            
            $prompt = $purple . $primaryService . $reset . ':' . $teal . '\\w' . $reset . '\\$ ';
            
            // Execute interactive bash shell with custom prompt
            // Use /bin/sh to run a command that exports PS1 and execs bash in interactive mode
            // The -i flag is crucial for bash to recognize PS1
            $shellCommand = sprintf('/bin/sh -c "export PS1=\'%s\'; exec /bin/bash -i"', $prompt);
            $exitCode = $this->containerExecutor->execInteractive($composeFile, $primaryService, $shellCommand);

            return $exitCode;
        } catch (ConfigException $e) {
            $formatter->error("Configuration error: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (\Exception $e) {
            $formatter->error("Error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
