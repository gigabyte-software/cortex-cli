<?php

declare(strict_types=1);

namespace Cortex\Output;

use Cortex\Config\Schema\CommandDefinition;
use Symfony\Component\Console\Output\OutputInterface;

class OutputFormatter
{
    // Gigabyte Brand Colors
    private const COLOR_TEAL = '#2ED9C3';    // Pantone 3255C
    private const COLOR_PURPLE = '#7D55C7';  // Pantone 2665C
    private const COLOR_SMOKE = '#D2DCE5';   // Pantone 5455C

    public function __construct(
        private readonly OutputInterface $output,
    ) {
    }

    public function section(string $title): void
    {
        $this->output->writeln('');
        $this->output->writeln("<fg=" . self::COLOR_TEAL . ">▸ $title</>");
    }

    public function command(CommandDefinition $cmd): void
    {
        $this->output->writeln("  <fg=" . self::COLOR_SMOKE . ">{$cmd->description}</>");
    }

    public function success(string $message): void
    {
        $this->output->writeln("<fg=" . self::COLOR_PURPLE . ">$message</>");
    }

    public function error(string $message): void
    {
        $this->output->writeln("<fg=red>$message</>");
    }

    public function warning(string $message): void
    {
        $this->output->writeln("<fg=yellow>$message</>");
    }

    public function info(string $message): void
    {
        $this->output->writeln("<fg=" . self::COLOR_SMOKE . ">  $message</>");
    }

    public function commandOutput(string $output): void
    {
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            $this->output->writeln("    <fg=gray>$line</>");
        }
    }

    public function welcome(string $title = 'Starting Development Environment'): void
    {
        $this->output->writeln('');
        $this->output->writeln('<fg=' . self::COLOR_PURPLE . '>──────────────────────────────────────────────────</>');
        $this->output->writeln('<fg=' . self::COLOR_PURPLE . '> ' . $title . '</>');
        $this->output->writeln('<fg=' . self::COLOR_PURPLE . '>──────────────────────────────────────────────────</>');
        $this->output->writeln('');
    }

    public function completionSummary(float $totalTime, ?string $appUrl = null): void
    {
        $this->output->writeln('');
        $this->output->writeln(sprintf('<fg=' . self::COLOR_PURPLE . '>Environment ready! (%.1fs)</>', $totalTime));
        if ($appUrl !== null) {
            $this->output->writeln(sprintf('<fg=' . self::COLOR_TEAL . '>➜ Application: %s</>', $appUrl));
        }
        $this->output->writeln('');
    }
}

