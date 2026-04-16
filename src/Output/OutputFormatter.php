<?php

declare(strict_types=1);

namespace Cortex\Output;

use Cortex\Config\Schema\CommandDefinition;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class OutputFormatter
{
    // Gigabyte Brand Colors
    public const COLOR_TEAL = '#2ED9C3';    // Pantone 3255C
    public const COLOR_PURPLE = '#7D55C7';  // Pantone 2665C
    public const COLOR_SMOKE = '#D2DCE5';   // Pantone 5455C

    private const SERVICE_NAME_PAD = 2;

    public function __construct(
        private readonly OutputInterface $output,
    ) {
    }

    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    /**
     * Create a rewritable console section (for live-updating output).
     * Falls back to null if the output doesn't support sections.
     */
    public function createSection(): ?ConsoleSectionOutput
    {
        if ($this->output instanceof ConsoleOutput) {
            return $this->output->section();
        }

        return null;
    }

    /**
     * Render the live container status block into a console section.
     *
     * @param ConsoleSectionOutput $section
     * @param array<string, array{status: string, elapsed: float|null, log: string|null}> $services
     */
    public function renderServiceStatus(ConsoleSectionOutput $section, array $services): void
    {
        $maxNameLen = 0;
        foreach ($services as $name => $_) {
            $maxNameLen = max($maxNameLen, mb_strlen($name));
        }
        $nameWidth = $maxNameLen + self::SERVICE_NAME_PAD;

        $lines = [];
        foreach ($services as $name => $info) {
            $paddedName = str_pad($name, $nameWidth);
            $statusText = $this->formatStatus($info['status'], $info['elapsed']);
            $lines[] = "  <fg=" . self::COLOR_SMOKE . ">{$paddedName}</>{$statusText}";

            if ($info['log'] !== null && !$this->isHealthyStatus($info['status'])) {
                $truncatedLog = mb_strlen($info['log']) > 80
                    ? mb_substr($info['log'], 0, 77) . '...'
                    : $info['log'];
                $lines[] = "  " . str_repeat(' ', $nameWidth) . "<fg=" . self::COLOR_SMOKE . ">{$truncatedLog}</>";
            }
        }

        $section->overwrite($lines);
    }

    public function section(string $title): void
    {
        $this->output->writeln('');
        $this->output->writeln('<fg=' . self::COLOR_TEAL . ">▸ $title</>");
    }

    public function command(CommandDefinition $cmd): void
    {
        $this->output->writeln('  <fg=' . self::COLOR_SMOKE . ">{$cmd->description}</>");
    }

    public function success(string $message): void
    {
        $this->output->writeln('<fg=' . self::COLOR_PURPLE . ">$message</>");
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
        $this->output->writeln('<fg=' . self::COLOR_SMOKE . ">  $message</>");
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

    public function url(string $label, string $url): void
    {
        $this->output->writeln(sprintf('<fg=' . self::COLOR_TEAL . '>➜ %s:</> %s', $label, $url));
    }

    private function formatStatus(string $status, ?float $elapsed): string
    {
        $color = match ($status) {
            'unhealthy', 'exited', 'restarting' => 'red',
            default => self::COLOR_PURPLE,
        };

        $label = $status;
        if ($this->isHealthyStatus($status) && $elapsed !== null) {
            $label = sprintf('%s (%.1fs)', $status, $elapsed);
        }

        return "<fg={$color}>{$label}</>";
    }

    private function isHealthyStatus(string $status): bool
    {
        return $status === 'healthy' || $status === 'running';
    }
}
