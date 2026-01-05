<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit\Command;

use Cortex\Command\N8nExportCommand;
use Cortex\Config\ConfigLoader;
use Cortex\Config\Exception\ConfigException;
use Cortex\Config\Schema\CortexConfig;
use Cortex\Config\Schema\DockerConfig;
use Cortex\Config\Schema\N8nConfig;
use Cortex\Config\Schema\SetupConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class N8nExportCommandTest extends TestCase
{
    private ConfigLoader $configLoader;
    private Client $httpClient;
    private string $testDir;
    private string $envPath;
    private string $workflowsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->httpClient = $this->createMock(Client::class);
        $this->testDir = sys_get_temp_dir() . '/cortex_n8n_test_' . uniqid();
        mkdir($this->testDir, 0755, true);
        $this->envPath = $this->testDir . '/.env';
        $this->workflowsDir = $this->testDir . '/.n8n';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->testDir)) {
            $this->recursiveRemoveDirectory($this->testDir);
        }
    }

    // ==================== Command Configuration Tests ====================

    public function test_command_is_configured_correctly(): void
    {
        $command = new N8nExportCommand($this->configLoader, $this->httpClient);

        $this->assertSame('n8n:export', $command->getName());
        $this->assertSame('Export n8n workflows', $command->getDescription());

        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('force'));
        $this->assertTrue($definition->hasShortcut('f'));
        $this->assertFalse($definition->getOption('force')->isValueRequired());
    }

    // ==================== loadEnv() Tests ====================

    public function test_loadEnv_creates_empty_env_file_when_missing(): void
    {
        if (file_exists($this->envPath)) {
            @unlink($this->envPath);
        }

        $command = $this->createCommand();
        $result = $this->invokeMethod($command, 'loadEnv', [$this->envPath]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
        if (file_exists($this->envPath)) {
            $fileContent = file_get_contents($this->envPath);
            $this->assertIsString($fileContent);
            $this->assertSame('', $fileContent);
        } else {
            $this->markTestSkipped('Cannot create .env file (sandbox restrictions)');
        }
    }

    public function test_loadEnv_loads_existing_env_file(): void
    {
        $envContent = "CORTEX_N8N_HOST=http://localhost\nCORTEX_N8N_PORT=5678\nCORTEX_N8N_API_KEY=test-key-123";
        if (@file_put_contents($this->envPath, $envContent) === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        $command = $this->createCommand();
        $result = $this->invokeMethod($command, 'loadEnv', [$this->envPath]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('CORTEX_N8N_HOST', $result);
        $this->assertArrayHasKey('CORTEX_N8N_PORT', $result);
        $this->assertArrayHasKey('CORTEX_N8N_API_KEY', $result);
        $this->assertSame('http://localhost', $result['CORTEX_N8N_HOST']);
        $this->assertSame('5678', $result['CORTEX_N8N_PORT']);
        $this->assertSame('test-key-123', $result['CORTEX_N8N_API_KEY']);
    }

    // ==================== escapeEnvValue() Tests ====================

    public function test_escapeEnvValue_handles_empty_string(): void
    {
        $command = $this->createCommand();
        $result = $this->invokeMethod($command, 'escapeEnvValue', ['']);
        $this->assertSame('""', $result);
    }

    public function test_escapeEnvValue_handles_values_with_special_characters(): void
    {
        $command = $this->createCommand();
        $testCases = [
            'value with spaces' => '"value with spaces"',
            'value"with"quotes' => '"value\"with\"quotes"',
            'value\\with\\backslashes' => '"value\\\\with\\\\backslashes"',
            'value$with$dollar' => '"value$with$dollar"',
            'value`with`backtick' => '"value`with`backtick"',
            "value'with'single" => '"value\'with\'single"',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $this->invokeMethod($command, 'escapeEnvValue', [$input]);
            $this->assertSame($expected, $result, "Failed for input: $input");
        }
    }

    public function test_escapeEnvValue_handles_normal_values_without_escaping(): void
    {
        $command = $this->createCommand();
        $normalValues = ['localhost', '5678', 'test-key-123', 'http://localhost'];

        foreach ($normalValues as $value) {
            $result = $this->invokeMethod($command, 'escapeEnvValue', [$value]);
            $this->assertSame($value, $result, "Failed for value: $value");
        }
    }

    // ==================== writeEnv() Tests ====================

    public function test_writeEnv_writes_sorted_env_variables(): void
    {
        $command = $this->createCommand();
        $env = [
            'CORTEX_N8N_API_KEY' => 'test-key',
            'CORTEX_N8N_HOST' => 'http://localhost',
            'CORTEX_N8N_PORT' => '5678',
        ];

        $this->invokeMethod($command, 'writeEnv', [$this->envPath, $env]);

        if (file_exists($this->envPath)) {
            $content = file_get_contents($this->envPath);
            $this->assertIsString($content);
            $lines = array_filter(explode(PHP_EOL, trim($content)), fn($line) => $line !== '');
            $this->assertStringStartsWith('CORTEX_N8N_API_KEY', $lines[0] ?? '');
            $this->assertStringStartsWith('CORTEX_N8N_HOST', $lines[1] ?? '');
            $this->assertStringStartsWith('CORTEX_N8N_PORT', $lines[2] ?? '');
        } else {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }
    }

    public function test_writeEnv_escapes_special_characters(): void
    {
        $command = $this->createCommand();
        $env = [
            'CORTEX_N8N_API_KEY' => 'key with spaces and "quotes"',
        ];

        $this->invokeMethod($command, 'writeEnv', [$this->envPath, $env]);

        if (file_exists($this->envPath)) {
            $content = file_get_contents($this->envPath);
            $this->assertIsString($content);
            // Quotes inside a quoted string should be escaped with backslashes
            $this->assertStringContainsString('"key with spaces and \"quotes\""', $content);
        } else {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }
    }

    // ==================== promptForMissingEnvValues() Tests ====================

    public function test_promptForMissingEnvValues_returns_env_when_all_values_present(): void
    {
        $command = $this->createCommandWithHelperSet();
        $input = $this->createMock(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);

        $env = [
            'CORTEX_N8N_HOST' => 'http://localhost',
            'CORTEX_N8N_PORT' => '5678',
            'CORTEX_N8N_API_KEY' => 'test-key',
        ];

        $result = $this->invokeMethod($command, 'promptForMissingEnvValues', [$env, $input, $output]);
        $this->assertSame($env, $result);
    }

    public function test_promptForMissingEnvValues_prompts_for_missing_values(): void
    {
        $command = $this->createCommandWithHelperSet();
        $input = $this->createMock(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);
        $questionHelper = $this->createMock(QuestionHelper::class);
        $questionHelper->expects($this->exactly(3))
            ->method('ask')
            ->willReturnOnConsecutiveCalls('http://localhost', '5678', 'test-key');

        $helperSet = new HelperSet(['question' => $questionHelper]);
        $command->setHelperSet($helperSet);

        $result = $this->invokeMethod($command, 'promptForMissingEnvValues', [[], $input, $output]);

        $this->assertArrayHasKey('CORTEX_N8N_HOST', $result);
        $this->assertArrayHasKey('CORTEX_N8N_PORT', $result);
        $this->assertArrayHasKey('CORTEX_N8N_API_KEY', $result);
    }

    public function test_promptForMissingEnvValues_throws_exception_on_null_input(): void
    {
        $command = $this->createCommandWithHelperSet();
        $input = $this->createMock(InputInterface::class);
        $output = $this->createMock(OutputInterface::class);
        $questionHelper = $this->createMock(QuestionHelper::class);
        $questionHelper->expects($this->once())
            ->method('ask')
            ->willReturn(null);

        $helperSet = new HelperSet(['question' => $questionHelper]);
        $command->setHelperSet($helperSet);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CORTEX_N8N_HOST is required');

        $this->invokeMethod($command, 'promptForMissingEnvValues', [[], $input, $output]);
    }

    // ==================== buildApiOptions() Tests ====================

    public function test_buildApiOptions_creates_correct_headers(): void
    {
        $command = $this->createCommand();
        $env = [
            'CORTEX_N8N_HOST' => 'http://localhost',
            'CORTEX_N8N_PORT' => '5678',
            'CORTEX_N8N_API_KEY' => 'test-api-key',
        ];

        $result = $this->invokeMethod($command, 'buildApiOptions', [$env]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('X-N8N-API-KEY', $result['headers']);
        $this->assertArrayHasKey('Accept', $result['headers']);
        $this->assertSame('test-api-key', $result['headers']['X-N8N-API-KEY']);
        $this->assertSame('application/json', $result['headers']['Accept']);
    }

    // ==================== buildBaseUri() Tests ====================

    public function test_buildBaseUri_constructs_uri_from_env(): void
    {
        $command = $this->createCommand();
        $env = [
            'CORTEX_N8N_HOST' => 'http://localhost',
            'CORTEX_N8N_PORT' => '5678',
            'CORTEX_N8N_API_KEY' => 'test-key',
        ];

        $result = $this->invokeMethod($command, 'buildBaseUri', [$env]);

        $this->assertSame('http://localhost:5678', $result);
    }

    public function test_buildBaseUri_handles_host_with_trailing_slash(): void
    {
        $command = $this->createCommand();
        $env = [
            'CORTEX_N8N_HOST' => 'http://localhost/',
            'CORTEX_N8N_PORT' => '5678',
            'CORTEX_N8N_API_KEY' => 'test-key',
        ];

        $result = $this->invokeMethod($command, 'buildBaseUri', [$env]);
        $this->assertSame('http://localhost/:5678', $result);
    }

    // ==================== buildWorkflowsUri() Tests ====================

    public function test_buildWorkflowsUri_appends_endpoint(): void
    {
        $command = $this->createCommand();
        $baseUri = 'http://localhost:5678';

        $result = $this->invokeMethod($command, 'buildWorkflowsUri', [$baseUri]);

        $this->assertSame('http://localhost:5678/api/v1/workflows', $result);
    }

    public function test_buildWorkflowsUri_handles_trailing_slash(): void
    {
        $command = $this->createCommand();
        $baseUri = 'http://localhost:5678/';

        $result = $this->invokeMethod($command, 'buildWorkflowsUri', [$baseUri]);

        $this->assertSame('http://localhost:5678/api/v1/workflows', $result);
    }

    // ==================== buildWorkflowUri() Tests ====================

    public function test_buildWorkflowUri_appends_workflow_id(): void
    {
        $command = $this->createCommand();
        $baseUri = 'http://localhost:5678';
        $workflowId = '123';

        $result = $this->invokeMethod($command, 'buildWorkflowUri', [$baseUri, $workflowId]);

        $this->assertSame('http://localhost:5678/api/v1/workflows/123', $result);
    }

    public function test_buildWorkflowUri_handles_trailing_slash(): void
    {
        $command = $this->createCommand();
        $baseUri = 'http://localhost:5678/';
        $workflowId = '456';

        $result = $this->invokeMethod($command, 'buildWorkflowUri', [$baseUri, $workflowId]);

        $this->assertSame('http://localhost:5678/api/v1/workflows/456', $result);
    }

    // ==================== fetchWorkflowsList() Tests ====================

    public function test_fetchWorkflowsList_returns_workflows_array(): void
    {
        $command = $this->createCommand();
        $workflowsData = [
            'data' => [
                ['id' => '1', 'name' => 'Workflow 1'],
                ['id' => '2', 'name' => 'Workflow 2'],
            ]
        ];

        $response = $this->createMockResponseFromJson($workflowsData);
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $result = $this->invokeMethod($command, 'fetchWorkflowsList', ['http://localhost/api/v1/workflows', []]);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('1', $result[0]['id']);
        $this->assertSame('2', $result[1]['id']);
    }

    public function test_fetchWorkflowsList_throws_exception_on_missing_data_key(): void
    {
        $command = $this->createCommand();
        $invalidResponse = ['workflows' => []]; // Missing 'data' key

        $response = $this->createMockResponseFromJson($invalidResponse);
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid workflows response: missing data array');

        $this->invokeMethod($command, 'fetchWorkflowsList', ['http://localhost/api/v1/workflows', []]);
    }

    public function test_fetchWorkflowsList_throws_exception_on_non_array_data(): void
    {
        $command = $this->createCommand();
        $invalidResponse = ['data' => 'not-an-array'];

        $response = $this->createMockResponseFromJson($invalidResponse);
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid workflows response: missing data array');

        $this->invokeMethod($command, 'fetchWorkflowsList', ['http://localhost/api/v1/workflows', []]);
    }

    public function test_fetchWorkflowsList_handles_http_exception(): void
    {
        $command = $this->createCommand();
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new ConnectException('Connection failed', $this->createMock(RequestInterface::class)));

        $this->expectException(ConnectException::class);

        $this->invokeMethod($command, 'fetchWorkflowsList', ['http://localhost/api/v1/workflows', []]);
    }

    // ==================== fetchWorkflowDetails() Tests ====================

    public function test_fetchWorkflowDetails_returns_decoded_json(): void
    {
        $command = $this->createCommand();
        $workflowData = ['id' => '1', 'name' => 'Test Workflow', 'nodes' => []];

        $response = $this->createMockResponseFromJson($workflowData, false);
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $result = $this->invokeMethod($command, 'fetchWorkflowDetails', ['http://localhost/api/v1/workflows/1', []]);

        $this->assertIsArray($result);
        $this->assertSame('1', $result['id']);
        $this->assertSame('Test Workflow', $result['name']);
    }

    public function test_fetchWorkflowDetails_handles_invalid_json(): void
    {
        $command = $this->createCommand();
        $response = $this->createMockResponse('invalid json', false);
        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->expectException(\JsonException::class);

        $this->invokeMethod($command, 'fetchWorkflowDetails', ['http://localhost/api/v1/workflows/1', []]);
    }

    // ==================== formatWorkflowJson() Tests ====================

    public function test_formatWorkflowJson_formats_with_pretty_print(): void
    {
        $command = $this->createCommand();
        $data = ['id' => '1', 'name' => 'Test', 'nodes' => []];

        $result = $this->invokeMethod($command, 'formatWorkflowJson', [$data]);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertSame($data, $decoded);
        $this->assertStringContainsString("\n", $result); // Pretty print should have newlines
    }

    public function test_formatWorkflowJson_preserves_slashes(): void
    {
        $command = $this->createCommand();
        $data = ['url' => 'http://example.com/path'];

        $result = $this->invokeMethod($command, 'formatWorkflowJson', [$data]);

        $this->assertStringContainsString('http://example.com/path', $result);
        $this->assertStringNotContainsString('http:\/\/example.com\/path', $result);
    }

    // ==================== saveWorkflow() Tests ====================

    public function test_saveWorkflow_writes_file(): void
    {
        $command = $this->createCommand();
        $destFile = $this->workflowsDir . '/test.json';
        $jsonContent = '{"test": "data"}';

        mkdir($this->workflowsDir, 0755, true);
        $this->invokeMethod($command, 'saveWorkflow', [$destFile, $jsonContent]);

        $this->assertFileExists($destFile);
        $fileContent = file_get_contents($destFile);
        $this->assertIsString($fileContent);
        $this->assertSame($jsonContent, $fileContent);
    }

    // ==================== shouldSkipFile() Tests ====================

    public function test_shouldSkipFile_returns_true_when_file_exists_and_not_forced(): void
    {
        $command = $this->createCommand();
        $destFile = $this->workflowsDir . '/test.json';

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($destFile, '{}');

        $result = $this->invokeMethod($command, 'shouldSkipFile', [$destFile, false]);

        $this->assertTrue($result);
    }

    public function test_shouldSkipFile_returns_false_when_file_exists_and_forced(): void
    {
        $command = $this->createCommand();
        $destFile = $this->workflowsDir . '/test.json';

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($destFile, '{}');

        $result = $this->invokeMethod($command, 'shouldSkipFile', [$destFile, true]);

        $this->assertFalse($result);
    }

    public function test_shouldSkipFile_returns_false_when_file_not_exists(): void
    {
        $command = $this->createCommand();
        $destFile = $this->workflowsDir . '/nonexistent.json';

        $result = $this->invokeMethod($command, 'shouldSkipFile', [$destFile, false]);

        $this->assertFalse($result);
    }

    // ==================== getEnvPath() Tests ====================

    public function test_getEnvPath_returns_current_directory_env_path(): void
    {
        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = $this->createCommand();
            $result = $this->invokeMethod($command, 'getEnvPath', []);

            $this->assertStringEndsWith('/.env', $result);
            $this->assertStringContainsString($this->testDir, $result);
        } finally {
            chdir($originalDir);
        }
    }

    // ==================== ensureDirectoryExists() Tests ====================

    public function test_ensureDirectoryExists_creates_directory_when_missing(): void
    {
        $command = $this->createCommand();
        $newDir = $this->testDir . '/new-dir';

        $this->assertDirectoryDoesNotExist($newDir);
        $this->invokeMethod($command, 'ensureDirectoryExists', [$newDir]);
        $this->assertDirectoryExists($newDir);
    }

    public function test_ensureDirectoryExists_does_nothing_when_directory_exists(): void
    {
        $command = $this->createCommand();
        mkdir($this->workflowsDir, 0755, true);

        $this->assertDirectoryExists($this->workflowsDir);
        $this->invokeMethod($command, 'ensureDirectoryExists', [$this->workflowsDir]);
        $this->assertDirectoryExists($this->workflowsDir);
    }

    // ==================== performExport() Integration Tests ====================

    public function test_performExport_successfully_exports_workflows(): void
    {
        $env = [
            'CORTEX_N8N_HOST' => 'http://localhost',
            'CORTEX_N8N_PORT' => '5678',
            'CORTEX_N8N_API_KEY' => 'test-key',
        ];

        $workflowsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '1', 'name' => 'Workflow 1'],
                ['id' => '2', 'name' => 'Workflow 2'],
            ]
        ]);

        $workflow1Response = $this->createMockResponseFromJson(['id' => '1', 'name' => 'Workflow 1', 'nodes' => []], false);
        $workflow2Response = $this->createMockResponseFromJson(['id' => '2', 'name' => 'Workflow 2', 'nodes' => []], false);

        $this->httpClient->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function ($method, $uri) use ($workflowsResponse, $workflow1Response, $workflow2Response) {
                if (preg_match('#/api/v1/workflows$#', $uri)) {
                    return $workflowsResponse;
                } elseif (str_contains($uri, '/api/v1/workflows/1')) {
                    return $workflow1Response;
                } elseif (str_contains($uri, '/api/v1/workflows/2')) {
                    return $workflow2Response;
                }
                throw new \RuntimeException('Unexpected URI: ' . $uri);
            });

        $formatter = $this->createMock(\Cortex\Output\OutputFormatter::class);
        $command = $this->createCommand();
        mkdir($this->workflowsDir, 0755, true);

        $skipped = $this->invokeMethod($command, 'performExport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertFalse($skipped);
        $this->assertFileExists($this->workflowsDir . '/Workflow 1.json');
        $this->assertFileExists($this->workflowsDir . '/Workflow 2.json');
    }

    public function test_performExport_skips_existing_files_without_force(): void
    {
        $env = [
            'CORTEX_N8N_HOST' => 'http://localhost',
            'CORTEX_N8N_PORT' => '5678',
            'CORTEX_N8N_API_KEY' => 'test-key',
        ];

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($this->workflowsDir . '/Test Workflow.json', '{"existing": "content"}');

        $workflowsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '1', 'name' => 'Test Workflow'],
            ]
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($workflowsResponse);

        $formatter = $this->createMock(\Cortex\Output\OutputFormatter::class);
        $formatter->expects($this->once())
            ->method('info')
            ->with($this->stringContains('already exists'));

        $command = $this->createCommand();
        $skipped = $this->invokeMethod($command, 'performExport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertTrue($skipped);
        $fileContent = file_get_contents($this->workflowsDir . '/Test Workflow.json');
        $this->assertIsString($fileContent);
        $this->assertSame('{"existing": "content"}', $fileContent);
    }

    public function test_performExport_overwrites_existing_files_with_force(): void
    {
        $env = [
            'CORTEX_N8N_HOST' => 'http://localhost',
            'CORTEX_N8N_PORT' => '5678',
            'CORTEX_N8N_API_KEY' => 'test-key',
        ];

        mkdir($this->workflowsDir, 0755, true);
        file_put_contents($this->workflowsDir . '/Test Workflow.json', '{"old": "content"}');

        $workflowsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '1', 'name' => 'Test Workflow'],
            ]
        ]);

        $workflowResponse = $this->createMockResponseFromJson(['id' => '1', 'name' => 'Test Workflow', 'nodes' => []], false);

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $uri) use ($workflowsResponse, $workflowResponse) {
                if (preg_match('#/api/v1/workflows$#', $uri)) {
                    return $workflowsResponse;
                } elseif (str_contains($uri, '/api/v1/workflows/1')) {
                    return $workflowResponse;
                }
                throw new \RuntimeException('Unexpected URI: ' . $uri);
            });

        $formatter = $this->createMock(\Cortex\Output\OutputFormatter::class);
        $formatter->expects($this->never())
            ->method('info');

        $command = $this->createCommand();
        $skipped = $this->invokeMethod($command, 'performExport', [$env, $this->workflowsDir, true, $formatter]);

        $this->assertFalse($skipped);
        $fileContent = file_get_contents($this->workflowsDir . '/Test Workflow.json');
        $this->assertIsString($fileContent);
        $content = json_decode($fileContent, true);
        $this->assertSame('1', $content['id']);
    }

    public function test_performExport_skips_invalid_workflow_entries(): void
    {
        $env = [
            'CORTEX_N8N_HOST' => 'http://localhost',
            'CORTEX_N8N_PORT' => '5678',
            'CORTEX_N8N_API_KEY' => 'test-key',
        ];

        $workflowsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '1', 'name' => 'Valid Workflow'],
                ['name' => 'Missing ID'], // Invalid: missing id
                ['id' => '2'], // Invalid: missing name
                ['id' => '3', 'name' => 'Valid Workflow 2'],
            ]
        ]);

        $workflow1Response = $this->createMockResponseFromJson(['id' => '1', 'name' => 'Valid Workflow', 'nodes' => []], false);
        $workflow3Response = $this->createMockResponseFromJson(['id' => '3', 'name' => 'Valid Workflow 2', 'nodes' => []], false);

        $this->httpClient->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function ($method, $uri) use ($workflowsResponse, $workflow1Response, $workflow3Response) {
                if (preg_match('#/api/v1/workflows$#', $uri)) {
                    return $workflowsResponse;
                } elseif (str_contains($uri, '/api/v1/workflows/1')) {
                    return $workflow1Response;
                } elseif (str_contains($uri, '/api/v1/workflows/3')) {
                    return $workflow3Response;
                }
                throw new \RuntimeException('Unexpected URI: ' . $uri);
            });

        $formatter = $this->createMock(\Cortex\Output\OutputFormatter::class);
        $command = $this->createCommand();
        mkdir($this->workflowsDir, 0755, true);

        $skipped = $this->invokeMethod($command, 'performExport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertFalse($skipped);
        $this->assertFileExists($this->workflowsDir . '/Valid Workflow.json');
        $this->assertFileExists($this->workflowsDir . '/Valid Workflow 2.json');
        $this->assertFileDoesNotExist($this->workflowsDir . '/Missing ID.json');
    }

    public function test_performExport_handles_empty_workflows_list(): void
    {
        $env = [
            'CORTEX_N8N_HOST' => 'http://localhost',
            'CORTEX_N8N_PORT' => '5678',
            'CORTEX_N8N_API_KEY' => 'test-key',
        ];

        $workflowsResponse = $this->createMockResponseFromJson(['data' => []]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($workflowsResponse);

        $formatter = $this->createMock(\Cortex\Output\OutputFormatter::class);
        $command = $this->createCommand();
        mkdir($this->workflowsDir, 0755, true);

        $skipped = $this->invokeMethod($command, 'performExport', [$env, $this->workflowsDir, false, $formatter]);

        $this->assertFalse($skipped);
        $this->assertEmpty(glob($this->workflowsDir . '/*.json'));
    }

    public function test_performExport_handles_http_exception(): void
    {
        $env = [
            'CORTEX_N8N_HOST' => 'http://localhost',
            'CORTEX_N8N_PORT' => '5678',
            'CORTEX_N8N_API_KEY' => 'test-key',
        ];

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new ConnectException('Connection failed', $this->createMock(RequestInterface::class)));

        $formatter = $this->createMock(\Cortex\Output\OutputFormatter::class);
        $command = $this->createCommand();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to export n8n workflow');

        $this->invokeMethod($command, 'performExport', [$env, $this->workflowsDir, false, $formatter]);
    }

    // ==================== execute() Integration Tests ====================

    public function test_execute_successfully_exports_workflows(): void
    {
        if (@file_put_contents($this->envPath, "CORTEX_N8N_HOST=http://localhost\nCORTEX_N8N_PORT=5678\nCORTEX_N8N_API_KEY=test-key") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        $config = $this->createMockConfig();
        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');
        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $workflowsResponse = $this->createMockResponseFromJson([
            'data' => [
                ['id' => '1', 'name' => 'Workflow 1'],
            ]
        ]);

        $workflowResponse = $this->createMockResponseFromJson(['id' => '1', 'name' => 'Workflow 1', 'nodes' => []], false);

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function ($method, $uri) use ($workflowsResponse, $workflowResponse) {
                if (preg_match('#/api/v1/workflows$#', $uri)) {
                    return $workflowsResponse;
                } elseif (str_contains($uri, '/api/v1/workflows/1')) {
                    return $workflowResponse;
                }
                throw new \RuntimeException('Unexpected URI: ' . $uri);
            });

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = new N8nExportCommand($this->configLoader, $this->httpClient);
            $tester = $this->createCommandTester($command);

            $exitCode = $tester->execute([], ['interactive' => false]);

            $this->assertSame(0, $exitCode);
            $this->assertDirectoryExists($this->workflowsDir);
            $this->assertFileExists($this->workflowsDir . '/Workflow 1.json');
            $this->assertStringContainsString('Workflow export complete', $tester->getDisplay());
        } finally {
            chdir($originalDir);
        }
    }

    public function test_execute_creates_workflows_directory_if_missing(): void
    {
        if (@file_put_contents($this->envPath, "CORTEX_N8N_HOST=http://localhost\nCORTEX_N8N_PORT=5678\nCORTEX_N8N_API_KEY=test-key") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        $config = $this->createMockConfig();
        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');
        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->assertDirectoryDoesNotExist($this->workflowsDir);

        $workflowsResponse = $this->createMockResponseFromJson(['data' => []]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($workflowsResponse);

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = new N8nExportCommand($this->configLoader, $this->httpClient);
            $tester = $this->createCommandTester($command);

            $exitCode = $tester->execute([], ['interactive' => false]);

            $this->assertSame(0, $exitCode);
            $this->assertDirectoryExists($this->workflowsDir);
        } finally {
            chdir($originalDir);
        }
    }

    public function test_execute_handles_config_exception(): void
    {
        if (@file_put_contents($this->envPath, "CORTEX_N8N_HOST=http://localhost\nCORTEX_N8N_PORT=5678\nCORTEX_N8N_API_KEY=test-key") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willThrowException(new ConfigException('Config not found'));

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = new N8nExportCommand($this->configLoader, $this->httpClient);
            $tester = $this->createCommandTester($command);

            $exitCode = $tester->execute([], ['interactive' => false]);

            $this->assertSame(1, $exitCode);
            $display = $tester->getDisplay();
            $this->assertTrue(
                str_contains($display, 'Config not found') || str_contains($display, 'CORTEX_N8N_HOST is required'),
                'Should show error: ' . $display
            );
        } finally {
            chdir($originalDir);
        }
    }

    public function test_execute_handles_http_exception(): void
    {
        if (@file_put_contents($this->envPath, "CORTEX_N8N_HOST=http://localhost\nCORTEX_N8N_PORT=5678\nCORTEX_N8N_API_KEY=test-key") === false) {
            $this->markTestSkipped('Cannot write .env file (sandbox restrictions)');
        }

        $config = $this->createMockConfig();
        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn('/path/to/cortex.yml');
        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new RequestException('Connection failed', $this->createMock(RequestInterface::class)));

        $cwd = getcwd();
        $originalDir = $cwd !== false ? $cwd : '/';
        chdir($this->testDir);

        try {
            $command = new N8nExportCommand($this->configLoader, $this->httpClient);
            $tester = $this->createCommandTester($command);

            $exitCode = $tester->execute([], ['interactive' => false]);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Failed to export n8n workflow', $tester->getDisplay());
        } finally {
            chdir($originalDir);
        }
    }

    // ==================== Helper Methods ====================

    private function createCommand(): N8nExportCommand
    {
        return new N8nExportCommand($this->configLoader, $this->httpClient);
    }

    private function createCommandWithHelperSet(): N8nExportCommand
    {
        $command = new N8nExportCommand($this->configLoader, $this->httpClient);
        $application = new Application();
        $application->add($command);
        $foundCommand = $application->find('n8n:export');
        $this->assertInstanceOf(N8nExportCommand::class, $foundCommand);
        /** @var N8nExportCommand $foundCommand */
        return $foundCommand;
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invokeMethod(object $object, string $methodName, array $args = []): mixed
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invoke($object, ...$args);
    }

    private function createCommandTester(N8nExportCommand $command): CommandTester
    {
        $application = new Application();
        $application->add($command);
        $command = $application->find('n8n:export');
        return new CommandTester($command);
    }

    private function createMockConfig(): CortexConfig
    {
        $dockerConfig = new DockerConfig(
            composeFile: 'docker-compose.yml',
            primaryService: 'app',
            appUrl: 'http://localhost:80',
            waitFor: []
        );

        $setupConfig = new SetupConfig(
            preStart: [],
            initialize: []
        );

        $n8nConfig = new N8nConfig(
            workflowsDir: $this->workflowsDir
        );

        return new CortexConfig(
            version: '1.0',
            docker: $dockerConfig,
            setup: $setupConfig,
            n8n: $n8nConfig,
            commands: []
        );
    }

    /**
     * @param string $content
     */
    private function createMockResponse(string $content, bool $useGetContents = true): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $body = $this->createMock(StreamInterface::class);

        if ($useGetContents) {
            $body->expects($this->once())
                ->method('getContents')
                ->willReturn($content);
        } else {
            $body->expects($this->once())
                ->method('__toString')
                ->willReturn($content);
        }

        $response->expects($this->atLeastOnce())
            ->method('getBody')
            ->willReturn($body);

        return $response;
    }

    /**
     * Helper to safely encode JSON and create mock response
     * @param array<string, mixed> $data
     */
    private function createMockResponseFromJson(array $data, bool $useGetContents = true): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        return $this->createMockResponse($json, $useGetContents);
    }

    private function recursiveRemoveDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path)) {
                $this->recursiveRemoveDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
