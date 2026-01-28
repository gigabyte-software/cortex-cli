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
            ->addOption('skip-yaml', null, InputOption::VALUE_NONE, 'Skip creating cortex.yml')
            ->addOption('skip-claude', null, InputOption::VALUE_NONE, 'Skip creating/updating ~/.claude files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new \RuntimeException('Failed to get current working directory');
            }

            $homeDir = $this->getHomeDirectory();
            $force = $input->getOption('force');
            $skipYaml = $input->getOption('skip-yaml');
            $skipClaude = $input->getOption('skip-claude');

            $formatter->welcome('Initializing Cortex');
            $formatter->section('Setting up directory structure');

            // Create .cortex directory structure (handles "already exists" internally)
            $this->createCortexDirectory($cwd, $formatter);

            // Create cortex.yml (unless --skip-yaml)
            if (!$skipYaml) {
                $this->createCortexYml($cwd, $formatter, $force);
            }

            // Create/update ~/.claude files (unless --skip-claude)
            if (!$skipClaude) {
                // Create/update ~/.claude/rules/cortex.md (user-wide rules)
                $this->createClaudeRules($homeDir, $formatter, $force);

                // Create/update ~/.claude/CLAUDE.md (user-wide memory)
                $this->createClaudeMd($homeDir, $formatter);
            } else {
                $formatter->info('⊘ Skipped ~/.claude files (--skip-claude)');
            }

            // Show success message
            $this->showSuccessMessage($formatter, $skipYaml, $skipClaude);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $formatter->error("Initialization failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function getHomeDirectory(): string
    {
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            // Fallback for Windows or if HOME is not set
            $home = getenv('USERPROFILE');
        }
        if ($home === false || $home === '') {
            throw new \RuntimeException('Unable to determine home directory');
        }
        return $home;
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

        // Create .gitkeep in all subdirectories
        foreach ($subdirectories as $subdir) {
            $this->createGitkeep($cortexDir . '/' . $subdir, $formatter, $subdir);
        }

        // Create README.md in .cortex
        $this->createCortexReadme($cortexDir, $formatter);
    }

    private function createGitkeep(string $directory, OutputFormatter $formatter, string $subdirName): void
    {
        $gitkeepPath = $directory . '/.gitkeep';

        if (!file_exists($gitkeepPath)) {
            if (file_put_contents($gitkeepPath, '') === false) {
                throw new \RuntimeException("Failed to create .gitkeep in $directory");
            }
            $formatter->info("✓ Created .cortex/$subdirName/.gitkeep");
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
            throw new \RuntimeException('Failed to create README.md');
        }

        $formatter->info('✓ Created .cortex/README.md');
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
            throw new \RuntimeException('Failed to create cortex.yml');
        }

        $formatter->info('✓ Created cortex.yml');
    }

    private function createClaudeRules(string $homeDir, OutputFormatter $formatter, bool $force): void
    {
        $claudeDir = $homeDir . '/.claude';
        $rulesDir = $claudeDir . '/rules';
        $cortexMdPath = $rulesDir . '/cortex.md';

        // Compile templates into cortex.md
        $newContent = $this->compileClaudeRulesContent();

        // Check if file exists and compare content
        if (file_exists($cortexMdPath) && !$force) {
            $existingContent = file_get_contents($cortexMdPath);
            if ($existingContent !== false && md5($existingContent) === md5($newContent)) {
                $formatter->info('✓ ~/.claude/rules/cortex.md is up to date');
                return;
            }
        }

        // Create ~/.claude directory if it doesn't exist
        if (!is_dir($claudeDir)) {
            if (!mkdir($claudeDir, 0755, true)) {
                throw new \RuntimeException("Failed to create directory: $claudeDir");
            }
            $formatter->info('✓ Created ~/.claude/ directory');
        }

        // Create ~/.claude/rules directory if it doesn't exist
        if (!is_dir($rulesDir)) {
            if (!mkdir($rulesDir, 0755, true)) {
                throw new \RuntimeException("Failed to create directory: $rulesDir");
            }
            $formatter->info('✓ Created ~/.claude/rules/ directory');
        }

        $isUpdate = file_exists($cortexMdPath);

        if (file_put_contents($cortexMdPath, $newContent) === false) {
            throw new \RuntimeException('Failed to create ~/.claude/rules/cortex.md');
        }

        if ($isUpdate) {
            $formatter->info('✓ Updated ~/.claude/rules/cortex.md');
        } else {
            $formatter->info('✓ Created ~/.claude/rules/cortex.md');
        }
    }

    private const CORTEX_MARKER_START = '<!-- CORTEX START -->';
    private const CORTEX_MARKER_END = '<!-- CORTEX END -->';

    private function createClaudeMd(string $homeDir, OutputFormatter $formatter): void
    {
        $claudeDir = $homeDir . '/.claude';
        $claudeMdPath = $claudeDir . '/CLAUDE.md';

        // Create ~/.claude directory if it doesn't exist
        if (!is_dir($claudeDir)) {
            if (!mkdir($claudeDir, 0755, true)) {
                throw new \RuntimeException("Failed to create directory: $claudeDir");
            }
            $formatter->info('✓ Created ~/.claude/ directory');
        }

        // Get the cortex content wrapped in markers
        $templatePath = $this->getTemplatePath('CLAUDE.md.template');
        if (!file_exists($templatePath)) {
            throw new \RuntimeException("Template file not found: $templatePath");
        }

        $templateContent = file_get_contents($templatePath);
        if ($templateContent === false) {
            throw new \RuntimeException("Failed to read template: $templatePath");
        }

        $cortexSection = self::CORTEX_MARKER_START . "\n" . $templateContent . "\n" . self::CORTEX_MARKER_END;

        if (file_exists($claudeMdPath)) {
            // File exists - check if it already has cortex section
            $existingContent = file_get_contents($claudeMdPath);
            if ($existingContent === false) {
                throw new \RuntimeException("Failed to read existing CLAUDE.md");
            }

            if (str_contains($existingContent, self::CORTEX_MARKER_START)) {
                // Extract existing cortex section and compare
                $existingCortexSection = $this->extractCortexSection($existingContent);
                if ($existingCortexSection !== null && md5($existingCortexSection) === md5($cortexSection)) {
                    $formatter->info('✓ ~/.claude/CLAUDE.md Cortex section is up to date');
                    return;
                }

                // Replace existing cortex section with new one
                $newContent = $this->replaceCortexSection($existingContent, $cortexSection);
                if (file_put_contents($claudeMdPath, $newContent) === false) {
                    throw new \RuntimeException('Failed to update ~/.claude/CLAUDE.md');
                }

                $formatter->info('✓ Updated Cortex section in ~/.claude/CLAUDE.md');
                return;
            }

            // Append cortex section to existing content
            $newContent = $existingContent . "\n\n" . $cortexSection;
            if (file_put_contents($claudeMdPath, $newContent) === false) {
                throw new \RuntimeException('Failed to update ~/.claude/CLAUDE.md');
            }

            $formatter->info('✓ Appended Cortex section to ~/.claude/CLAUDE.md');
        } else {
            // Create new file with cortex section
            if (file_put_contents($claudeMdPath, $cortexSection) === false) {
                throw new \RuntimeException('Failed to create ~/.claude/CLAUDE.md');
            }

            $formatter->info('✓ Created ~/.claude/CLAUDE.md');
        }
    }

    private function extractCortexSection(string $content): ?string
    {
        $startPos = strpos($content, self::CORTEX_MARKER_START);
        $endPos = strpos($content, self::CORTEX_MARKER_END);

        if ($startPos === false || $endPos === false) {
            return null;
        }

        return substr($content, $startPos, $endPos - $startPos + strlen(self::CORTEX_MARKER_END));
    }

    private function replaceCortexSection(string $content, string $newSection): string
    {
        $pattern = '/' . preg_quote(self::CORTEX_MARKER_START, '/') . '.*?' . preg_quote(self::CORTEX_MARKER_END, '/') . '/s';
        return preg_replace($pattern, $newSection, $content) ?? $content;
    }

    private function compileClaudeRulesContent(): string
    {
        $content = '';

        // 1. Start with parent.md (intro content)
        $parentPath = $this->getTemplatePath('ticket-types/parent.md');
        if (file_exists($parentPath)) {
            $parentContent = file_get_contents($parentPath);
            if ($parentContent !== false) {
                $content .= $parentContent;
            }
        }

        // 2. Add all shared steps in workflow order
        $stepOrder = [
            'ticket.md',
            'specs.md',
            'approach.md',
            'planning.md',
            'tests.md',
            'code.md',
        ];

        foreach ($stepOrder as $stepFile) {
            $stepPath = $this->getTemplatePath('steps/' . $stepFile);
            if (file_exists($stepPath)) {
                $stepContent = file_get_contents($stepPath);
                if ($stepContent !== false) {
                    $content .= "\n\n" . $stepContent;
                }
            }
        }

        // 3. Add ticket types section header
        $content .= "\n\n# Ticket Types\n";

        // 4. Add all ticket types (except parent.md), alphabetically
        $ticketTypesDir = $this->getTemplatesDirectory() . '/ticket-types';
        if (is_dir($ticketTypesDir)) {
            $ticketTypeFiles = [];
            $files = scandir($ticketTypesDir);
            if ($files !== false) {
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..' && $file !== 'parent.md' && pathinfo($file, PATHINFO_EXTENSION) === 'md') {
                        $ticketTypeFiles[] = $file;
                    }
                }
            }
            sort($ticketTypeFiles);

            foreach ($ticketTypeFiles as $ticketTypeFile) {
                $ticketTypePath = $ticketTypesDir . '/' . $ticketTypeFile;
                $ticketTypeContent = file_get_contents($ticketTypePath);
                if ($ticketTypeContent !== false) {
                    $content .= "\n\n" . $ticketTypeContent;
                }
            }
        }

        return $content;
    }

    private function getTemplatesDirectory(): string
    {
        // When running as PHAR, templates are bundled inside
        $pharPath = \Phar::running(false);

        if (!empty($pharPath)) {
            // Running as PHAR - templates are in phar://path/to/cortex.phar/templates/
            return \Phar::running() . '/templates';
        }

        // Running from source - look for templates relative to project root
        $projectRoot = dirname(__DIR__, 2);
        return $projectRoot . '/templates';
    }

    private function getTemplatePath(string $templateName): string
    {
        return $this->getTemplatesDirectory() . '/' . $templateName;
    }

    private function showSuccessMessage(OutputFormatter $formatter, bool $skipYaml, bool $skipClaude = false): void
    {
        $formatter->section('Initialization Complete');
        $formatter->info('');
        $formatter->success('✓ Cortex initialized successfully!');

        $formatter->info('');
        $formatter->info('Created (project):');
        $formatter->info('  ✓ .cortex/ directory structure');
        $formatter->info('  ✓ .cortex/README.md');
        $formatter->info('  ✓ .cortex/tickets/.gitkeep');
        $formatter->info('  ✓ .cortex/specs/.gitkeep');
        $formatter->info('  ✓ .cortex/meetings/.gitkeep');

        if (!$skipYaml) {
            $formatter->info('  ✓ cortex.yml');
        }

        if (!$skipClaude) {
            $formatter->info('');
            $formatter->info('Created (user-wide):');
            $formatter->info('  ✓ ~/.claude/CLAUDE.md');
            $formatter->info('  ✓ ~/.claude/rules/cortex.md');
        }

        $formatter->info('');
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
        $formatter->info('');
        $formatter->info('For help: cortex --help');
    }
}
