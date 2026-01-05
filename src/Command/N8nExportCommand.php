<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class N8nExportCommand extends Command
{
    private const REQUIRED_ENV_KEYS = [
        'CORTEX_N8N_HOST',
        'CORTEX_N8N_PORT',
        'CORTEX_N8N_API_KEY',
    ];

    /**
     * @return array<string, string>
     */
    private function loadEnv(string $path): array
    {
        if (!file_exists($path)) {
            // Create empty .env
            file_put_contents($path, '');
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read .env file: {$path}");
        }

        $dotenv = new Dotenv();
        return $dotenv->parse($content);
    }

    /**
     * @param array<string, string> $env
     * @return array<string, string>
     */
    private function promptForMissingEnvValues(
        array $env,
        InputInterface $input,
        OutputInterface $output
    ): array {
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            throw new \RuntimeException('Question helper is not available');
        }

        foreach (self::REQUIRED_ENV_KEYS as $key) {
            if (!isset($env[$key]) || trim((string) $env[$key]) === '') {
                $question = new Question(
                    sprintf('Enter value for %s: ', $key)
                );

                // Hide API key input
                if ($key === 'CORTEX_N8N_API_KEY') {
                    $question->setHidden(true);
                    $question->setHiddenFallback(false);
                }

                $value = $helper->ask($input, $output, $question);

                if ($value === null || trim($value) === '') {
                    throw new \RuntimeException("{$key} is required");
                }

                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * @param array<string, string> $env
     */
    private function writeEnv(string $path, array $env): void
    {
        ksort($env);

        $lines = [];
        foreach ($env as $key => $value) {
            $lines[] = $key . '=' . $this->escapeEnvValue((string) $value);
        }

        file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private function escapeEnvValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (preg_match('/\s|["\'$`\\\\]/', $value)) {
            return '"' . addcslashes($value, '"\\') . '"';
        }

        return $value;
    }

    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly Client $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('n8n:export')
            ->setDescription('Export n8n workflows')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files');
    }

    /**
     * @param array<string, string> $env
     * @return array<string, array<string, string>>
     */
    private function buildApiOptions(array $env): array
    {
        return [
            'headers' => [
                'X-N8N-API-KEY' => $env['CORTEX_N8N_API_KEY'],
                'Accept' => 'application/json',
            ]
        ];
    }

    /**
     * @param array<string, string> $env
     */
    private function buildBaseUri(array $env): string
    {
        return "{$env['CORTEX_N8N_HOST']}:{$env['CORTEX_N8N_PORT']}";
    }

    private function buildWorkflowsUri(string $baseUri): string
    {
        return rtrim($baseUri, '/') . '/api/v1/workflows';
    }

    private function buildWorkflowUri(string $baseUri, string $workflowId): string
    {
        return rtrim($baseUri, '/') . '/api/v1/workflows/' . $workflowId;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private function fetchWorkflowsList(string $workflowsUri, array $options): array
    {
        $response = $this->httpClient->request('GET', $workflowsUri, $options);
        $data = json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
        
        if (!isset($data['data']) || !is_array($data['data'])) {
            throw new \RuntimeException('Invalid workflows response: missing data array');
        }
        
        return $data['data'];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function fetchWorkflowDetails(string $workflowUri, array $options): array
    {
        $response = $this->httpClient->request('GET', $workflowUri, $options);
        $rawJson = (string) $response->getBody();
        return json_decode($rawJson, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatWorkflowJson(array $data): string
    {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function saveWorkflow(string $destFile, string $jsonContent): void
    {
        file_put_contents($destFile, $jsonContent);
    }

    private function shouldSkipFile(string $destFile, bool $force): bool
    {
        return file_exists($destFile) && !$force;
    }

    /**
     * @param array<string, string> $env
     */
    private function performExport(array $env, string $dest, bool $force, OutputFormatter $formatter): bool
    {
        $skipped = false;
        $baseUri = $this->buildBaseUri($env);
        $options = $this->buildApiOptions($env);

        try {
            $workflowsUri = $this->buildWorkflowsUri($baseUri);
            $workflows = $this->fetchWorkflowsList($workflowsUri, $options);

            foreach ($workflows as $workflow) {
                if (!isset($workflow['id']) || !isset($workflow['name'])) {
                    continue; // Skip invalid workflow entries
                }

                $destFile = $dest . '/' . $workflow['name'] . '.json';

                if ($this->shouldSkipFile($destFile, $force)) {
                    $formatter->info(sprintf('File "%s" already exists', $destFile));
                    $skipped = true;
                    continue;
                }

                $workflowUri = $this->buildWorkflowUri($baseUri, $workflow['id']);
                $workflowData = $this->fetchWorkflowDetails($workflowUri, $options);
                $prettyJson = $this->formatWorkflowJson($workflowData);
                $this->saveWorkflow($destFile, $prettyJson);
            }
        } catch (GuzzleException | \JsonException $e) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to export n8n workflow: %s',
                    $e->getMessage()
                ),
                0,
                $e
            );
        }
        
        return $skipped;
    }


    private function getEnvPath(): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new \RuntimeException('Failed to get current working directory');
        }
        return $cwd . '/.env';
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);
        $force = (bool) $input->getOption('force');

        try {
            $envPath = $this->getEnvPath();
            $env = $this->loadEnv($envPath);
            $env = $this->promptForMissingEnvValues($env, $input, $output);
            $this->writeEnv($envPath, $env);

            $formatter->success('<info>✓ .env is configured correctly</info>');

            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);
            $formatter->info("Loaded configuration from: $configPath");

            $dest = $config->n8n->workflowsDir;
            $this->ensureDirectoryExists($dest);

            $skipped = $this->performExport($env, $dest, $force, $formatter);
            $skippedMessage = $skipped ? 'Some exports were skipped. Use -f (force)' : '';
            $formatter->success(sprintf(
                '<info>✓ Workflow export complete to %s. %s</info>',
                $dest,
                $skippedMessage
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $formatter->error('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}