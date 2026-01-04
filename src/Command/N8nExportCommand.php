<?php

declare(strict_types=1);

namespace Cortex\Command;

use Cortex\Config\ConfigLoader;
use Cortex\Output\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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

    private function loadEnv(string $path): array
    {
        if (!file_exists($path)) {
            // Create empty .env
            file_put_contents($path, '');
            return [];
        }

        $dotenv = new Dotenv();
        return $dotenv->parse(file_get_contents($path));
    }

    private function promptForMissingEnvValues(
        array $env,
        InputInterface $input,
        OutputInterface $output
    ): array {
        $helper = $this->getHelper('question');

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

    private function performExport(array $env, string $dest, bool $force, OutputFormatter $formatter): void
    {
        $options = [
            'headers' => [
            'X-N8N-API-KEY' => $env['CORTEX_N8N_API_KEY'],
            'Accept'       => 'application/json',
            ]
        ];

        try {
            $client = new Client([
                'base_uri' => "{$env['CORTEX_N8N_HOST']}:{$env['CORTEX_N8N_PORT']}",
                'timeout'  => 10,
                'verify' => false,
            ]);

            $responseWorkflows = $client->get('api/v1/workflows', $options);

            $dataWorkflows = json_decode($responseWorkflows->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);

            foreach ($dataWorkflows['data'] as $workflow) {

                $destFile = $dest . '/' . $workflow['name'] . '.json';

                if (file_exists($destFile) && !$force) {
                    $formatter->info(sprintf('File "%s" already exists', $destFile));
                }

                $response = $client->get('/api/v1/workflows/' . $workflow['id'], $options);

                $rawJson = (string) $response->getBody();
                $data = json_decode($rawJson, true, flags: JSON_THROW_ON_ERROR);

                $prettyJson = json_encode(
                    $data,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );

                file_put_contents($destFile, $prettyJson);
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
    }


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = new OutputFormatter($output);

        $force = (bool) $input->getOption('force');

        $envPath = getcwd() . '/.env';

        try {
            $env = $this->loadEnv($envPath);

            $env = $this->promptForMissingEnvValues(
                $env,
                $input,
                $output
            );

            $this->writeEnv($envPath, $env);

            $formatter->success('<info>✓ .env is configured correctly</info>');

            $configPath = $this->configLoader->findConfigFile();
            $config = $this->configLoader->load($configPath);
            $formatter->info("Loaded configuration from: $configPath");

            $dest = $config->n8n->workflowsDir;
            if (!is_dir($dest)) {
                mkdir($dest, 0755, true);
            }

            $this->performExport($env, $dest, $force, $formatter);
            $formatter->success(sprintf('<info>✓ Workflows have been written to %s</info>', $dest));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $formatter->error('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

    }
}