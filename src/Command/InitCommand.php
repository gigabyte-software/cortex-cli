<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('init')
            ->setDescription('Initialize Cortex configuration and directory structure')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('skip-yaml', null, InputOption::VALUE_NONE, 'Only create .cortex directory (skip cortex.yml)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);
        
        try {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new \RuntimeException('Failed to get current working directory');
            }

            $force = $input->getOption('force');
            $skipYaml = $input->getOption('skip-yaml');

            // Check if already initialized
            if (!$force && $this->isAlreadyInitialized($cwd, $skipYaml)) {
                $formatter->error('Cortex is already initialized in this directory');
                $formatter->info('Use --force to overwrite existing files');
                return Command::FAILURE;
            }

            $formatter->welcome();
            $formatter->section('Initializing Cortex');

            // Create .cortex directory structure
            $this->createCortexDirectory($cwd, $formatter);

            // Create cortex.yml (unless --skip-yaml)
            if (!$skipYaml) {
                $this->createCortexYml($cwd, $formatter, $force);
            }

            // Show success message
            $this->showSuccessMessage($formatter, $skipYaml);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $formatter->error("Initialization failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function isAlreadyInitialized(string $cwd, bool $skipYaml): bool
    {
        $cortexDir = $cwd . '/.cortex';
        $cortexYml = $cwd . '/cortex.yml';

        // If skipping YAML, only check for .cortex directory
        if ($skipYaml) {
            return is_dir($cortexDir);
        }

        // Otherwise, check for either
        return is_dir($cortexDir) || file_exists($cortexYml);
    }

    private function createCortexDirectory(string $cwd, OutputFormatter $formatter): void
    {
        $cortexDir = $cwd . '/.cortex';

        // Create main .cortex directory
        if (!is_dir($cortexDir)) {
            if (!mkdir($cortexDir, 0755, true)) {
                throw new \RuntimeException("Failed to create directory: $cortexDir");
            }
            $formatter->info('✓ Created .cortex/ directory');
        } else {
            $formatter->info('✓ .cortex/ directory already exists');
        }

        // Create subdirectories
        $subdirectories = [
            'tickets',
            'specs',
            'meetings',
        ];

        foreach ($subdirectories as $subdir) {
            $path = $cortexDir . '/' . $subdir;
            if (!is_dir($path)) {
                if (!mkdir($path, 0755, true)) {
                    throw new \RuntimeException("Failed to create directory: $path");
                }
                $formatter->info("✓ Created .cortex/$subdir/ directory");
            }
        }

        // Create .gitkeep in tickets directory
        $this->createGitkeep($cortexDir . '/tickets', $formatter);

        // Create README.md in .cortex
        $this->createCortexReadme($cortexDir, $formatter);

        // Create meetings/index.json
        $this->createMeetingsIndex($cortexDir . '/meetings', $formatter);
    }

    private function createGitkeep(string $directory, OutputFormatter $formatter): void
    {
        $gitkeepPath = $directory . '/.gitkeep';
        
        if (!file_exists($gitkeepPath)) {
            if (file_put_contents($gitkeepPath, '') === false) {
                throw new \RuntimeException("Failed to create .gitkeep in $directory");
            }
            $formatter->info('✓ Created .cortex/tickets/.gitkeep');
        }
    }

    private function createCortexReadme(string $cortexDir, OutputFormatter $formatter): void
    {
        $readmePath = $cortexDir . '/README.md';
        $templatePath = $this->getTemplatePath('cortex-readme.md.template');

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Template file not found: $templatePath");
        }

        $content = file_get_contents($templatePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read template: $templatePath");
        }

        if (file_put_contents($readmePath, $content) === false) {
            throw new \RuntimeException("Failed to create README.md");
        }

        $formatter->info('✓ Created .cortex/README.md');
    }

    private function createMeetingsIndex(string $meetingsDir, OutputFormatter $formatter): void
    {
        $indexPath = $meetingsDir . '/index.json';
        $templatePath = $this->getTemplatePath('meetings-index.json.template');

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Template file not found: $templatePath");
        }

        $content = file_get_contents($templatePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read template: $templatePath");
        }

        if (file_put_contents($indexPath, $content) === false) {
            throw new \RuntimeException("Failed to create meetings/index.json");
        }

        $formatter->info('✓ Created .cortex/meetings/index.json');
    }

    private function createCortexYml(string $cwd, OutputFormatter $formatter, bool $force): void
    {
        $cortexYmlPath = $cwd . '/cortex.yml';

        // Check if file exists and force is not set
        if (file_exists($cortexYmlPath) && !$force) {
            $formatter->warning('⚠ cortex.yml already exists (use --force to overwrite)');
            return;
        }

        $templatePath = $this->getTemplatePath('cortex.yml.template');

        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Template file not found: $templatePath");
        }

        $content = file_get_contents($templatePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read template: $templatePath");
        }

        if (file_put_contents($cortexYmlPath, $content) === false) {
            throw new \RuntimeException("Failed to create cortex.yml");
        }

        $formatter->info('✓ Created cortex.yml');
    }

    private function getTemplatePath(string $templateName): string
    {
        // When running as PHAR, templates are bundled inside
        $pharPath = \Phar::running(false);
        
        if (!empty($pharPath)) {
            // Running as PHAR - templates are in phar://path/to/cortex.phar/templates/
            return \Phar::running() . '/templates/' . $templateName;
        }

        // Running from source - look for templates relative to project root
        // This file is in src/Command/, so go up two levels to reach project root
        $projectRoot = dirname(__DIR__, 2);
        return $projectRoot . '/templates/' . $templateName;
    }

    private function showSuccessMessage(OutputFormatter $formatter, bool $skipYaml): void
    {
        $formatter->section('Initialization Complete');
        
        $output = $formatter->getOutput();
        $output->writeln('');
        $output->writeln('<fg=#7D55C7>✓ Cortex initialized successfully!</fg>');
        $output->writeln('');
        
        $formatter->info('Created:');
        $formatter->info('  ✓ .cortex/ directory structure');
        $formatter->info('  ✓ .cortex/README.md');
        $formatter->info('  ✓ .cortex/tickets/.gitkeep');
        $formatter->info('  ✓ .cortex/meetings/index.json');
        
        if (!$skipYaml) {
            $formatter->info('  ✓ cortex.yml');
        }
        
        $output->writeln('');
        $formatter->info('Next steps:');
        
        if (!$skipYaml) {
            $formatter->info('  1. Review and customize cortex.yml for your project');
            $formatter->info('  2. Ensure docker-compose.yml exists in your project');
            $formatter->info('  3. Run: cortex up');
        } else {
            $formatter->info('  1. Create a cortex.yml file (see cortex.example.yml)');
            $formatter->info('  2. Run: cortex up');
        }
        
        $formatter->info('  4. Read .cortex/README.md for documentation');
        $output->writeln('');
        $formatter->info('For help: cortex --help');
        $output->writeln('');
    }
}
