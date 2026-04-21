<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class InitGithubActionsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('init-github-actions')
            ->setDescription('Create .github/workflows caller files that use the shared Claude automation workflows')
            ->addOption(
                'repo',
                null,
                InputOption::VALUE_REQUIRED,
                'GitHub repository (owner/name) that hosts reusable workflows',
                'gigabyte-software/shared-workflows'
            )
            ->addOption(
                'ref',
                null,
                InputOption::VALUE_REQUIRED,
                'Git ref for reusable workflows (branch, tag, or SHA)',
                'main'
            )
            ->addOption(
                'ci-workflow-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Name of the CI workflow to watch for claude-auto-fix (workflow_run.workflows)',
                'Tests'
            )
            ->addOption(
                'base-branch',
                null,
                InputOption::VALUE_REQUIRED,
                'Default branch (auto-rebase push filter and rebase target)',
                'main'
            )
            ->addOption(
                'php-version',
                null,
                InputOption::VALUE_REQUIRED,
                'PHP version passed to reusable workflows (setup-php)',
                '8.2'
            )
            ->addOption(
                'node-version',
                null,
                InputOption::VALUE_REQUIRED,
                'Node.js version passed to reusable workflows',
                '20'
            )
            ->addOption('no-composer', null, InputOption::VALUE_NONE, 'Set run-composer-install=false on reusable workflow inputs')
            ->addOption('no-npm', null, InputOption::VALUE_NONE, 'Set run-npm-ci=false on reusable workflow inputs')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing workflow files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        try {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new \RuntimeException('Failed to get current working directory');
            }

            $githubDir = $cwd . '/.github/workflows';
            if (!is_dir($githubDir) && !mkdir($githubDir, 0755, true)) {
                throw new \RuntimeException("Failed to create directory: $githubDir");
            }

            $sharedRepo = (string) $input->getOption('repo');
            $sharedRef = (string) $input->getOption('ref');
            $ciName = (string) $input->getOption('ci-workflow-name');
            $baseBranch = (string) $input->getOption('base-branch');
            $phpVersion = (string) $input->getOption('php-version');
            $nodeVersion = (string) $input->getOption('node-version');
            $runComposer = $input->getOption('no-composer') ? 'false' : 'true';
            $runNpm = $input->getOption('no-npm') ? 'false' : 'true';
            $force = (bool) $input->getOption('force');

            $replacements = [
                '{{SHARED_REPO}}' => $sharedRepo,
                '{{SHARED_REF}}' => $sharedRef,
                '{{CI_WORKFLOW_NAME}}' => $ciName,
                '{{BASE_BRANCH}}' => $baseBranch,
                '{{PHP_VERSION}}' => $phpVersion,
                '{{NODE_VERSION}}' => $nodeVersion,
                '{{RUN_COMPOSER}}' => $runComposer,
                '{{RUN_NPM}}' => $runNpm,
            ];

            $formatter->welcome('Init GitHub Actions (shared workflows)');
            $formatter->section('Writing caller workflows');

            $written = [];
            foreach ($this->workflowSpecs() as $spec) {
                $dest = $githubDir . '/' . $spec['filename'];
                if (file_exists($dest) && !$force) {
                    $formatter->warning("⚠ Skipped {$spec['filename']} (exists; use --force to overwrite)");

                    continue;
                }

                $templatePath = $this->getTemplatesRoot() . '/github-actions/' . $spec['template'];
                if (!is_file($templatePath)) {
                    throw new \RuntimeException("Missing template: $templatePath");
                }

                $content = file_get_contents($templatePath);
                if ($content === false) {
                    throw new \RuntimeException("Failed to read template: $templatePath");
                }

                $content = strtr($content, $replacements);
                if (file_put_contents($dest, $content) === false) {
                    throw new \RuntimeException("Failed to write: $dest");
                }
                $written[] = $spec['filename'];
                $formatter->info("✓ Wrote .github/workflows/{$spec['filename']}");
            }

            if ($written === []) {
                $formatter->info('No files were written.');
            } else {
                $formatter->section('Next steps');
                $formatter->info('1. Ensure repository secrets exist: CLAUDE_FIXER_APP_ID, CLAUDE_FIXER_APP_PRIVATE_KEY, ANTHROPIC_API_KEY');
                $formatter->info('2. Optionally add COMPOSER_GITHUB_TOKEN for private Composer packages');
                $formatter->info('3. Confirm the CI workflow name matches --ci-workflow-name (currently: ' . $ciName . ')');
                $formatter->info('4. Pin --ref to a tag or SHA in production rather than a moving branch');
                $formatter->info('5. See shared repo README: https://github.com/' . $sharedRepo);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $formatter->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @return list<array{template: string, filename: string}>
     */
    private function workflowSpecs(): array
    {
        return [
            ['template' => 'claude-auto-fix.caller.yml.template', 'filename' => 'claude-auto-fix.yml'],
            ['template' => 'claude-auto-rebase.caller.yml.template', 'filename' => 'claude-auto-rebase.yml'],
            ['template' => 'claude-fix-review-comments.caller.yml.template', 'filename' => 'claude-fix-review-comments.yml'],
        ];
    }

    private function getTemplatesRoot(): string
    {
        if (\Phar::running() !== '') {
            return \Phar::running() . '/templates';
        }

        return dirname(__DIR__, 2) . '/templates';
    }
}
