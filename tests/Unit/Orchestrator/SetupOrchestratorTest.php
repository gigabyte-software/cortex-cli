<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit\Orchestrator;

use Cortex\Config\Schema\CortexConfig;
use Cortex\Config\Schema\DockerConfig;
use Cortex\Config\Schema\N8nConfig;
use Cortex\Config\Schema\ServiceWaitConfig;
use Cortex\Config\Schema\SetupConfig;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\HealthChecker;
use Cortex\Executor\HostCommandExecutor;
use Cortex\Orchestrator\SetupOrchestrator;
use Cortex\Output\OutputFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class SetupOrchestratorTest extends TestCase
{
    /** @var DockerCompose&MockObject */
    private DockerCompose $dockerCompose;

    /** @var HostCommandExecutor&MockObject */
    private HostCommandExecutor $hostExecutor;

    /** @var HealthChecker&MockObject */
    private HealthChecker $healthChecker;
    private OutputFormatter $formatter;
    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->hostExecutor = $this->createMock(HostCommandExecutor::class);
        $this->healthChecker = $this->createMock(HealthChecker::class);
        $this->output = new BufferedOutput();
        $this->formatter = new OutputFormatter($this->output);
    }

    public function test_setup_detects_first_run_and_shows_message(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(false);
        $this->dockerCompose->expects($this->once())->method('up');

        $orchestrator = $this->createOrchestrator();
        $result = $orchestrator->setup($config, skipWait: true);

        $display = $this->output->fetch();
        $this->assertStringContainsString('First run detected', $display);
        $this->assertStringContainsString('Building containers may take a few minutes', $display);
        $this->assertGreaterThanOrEqual(0.0, $result['time']);
    }

    public function test_setup_does_not_show_first_run_message_when_images_exist(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');

        $orchestrator = $this->createOrchestrator();
        $orchestrator->setup($config, skipWait: true);

        $display = $this->output->fetch();
        $this->assertStringNotContainsString('First run detected', $display);
    }

    public function test_setup_skips_wait_when_no_wait_flag_is_set(): void
    {
        $config = $this->createConfig(waitFor: [
            new ServiceWaitConfig(service: 'db', timeout: 30),
        ]);

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');

        $this->healthChecker->expects($this->never())->method('getHealthStatus');

        $orchestrator = $this->createOrchestrator();
        $orchestrator->setup($config, skipWait: true);

        $this->assertTrue(true);
    }

    public function test_setup_waits_for_services_when_configured(): void
    {
        $config = $this->createConfig(waitFor: [
            new ServiceWaitConfig(service: 'db', timeout: 30),
        ]);

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');
        $this->dockerCompose->method('getLatestLogLine')->willReturn(null);

        // Return healthy immediately
        $this->healthChecker->method('getHealthStatus')->willReturn('healthy');

        $orchestrator = $this->createOrchestrator();
        $result = $orchestrator->setup($config);

        $display = $this->output->fetch();
        $this->assertStringContainsString('Waiting for services', $display);
        $this->assertGreaterThanOrEqual(0.0, $result['time']);
    }

    public function test_setup_returns_correct_result_structure(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');

        $orchestrator = $this->createOrchestrator();
        $result = $orchestrator->setup($config, skipWait: true, namespace: 'test-ns', portOffset: 100);

        $this->assertArrayHasKey('time', $result);
        $this->assertArrayHasKey('namespace', $result);
        $this->assertArrayHasKey('port_offset', $result);
        $this->assertSame('test-ns', $result['namespace']);
        $this->assertSame(100, $result['port_offset']);
    }

    public function test_wait_for_services_polls_all_services(): void
    {
        $config = $this->createConfig(waitFor: [
            new ServiceWaitConfig(service: 'db', timeout: 30),
            new ServiceWaitConfig(service: 'redis', timeout: 30),
        ]);

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');
        $this->dockerCompose->method('getLatestLogLine')->willReturn(null);

        // Both services healthy immediately
        $this->healthChecker->method('getHealthStatus')
            ->willReturnMap([
                ['docker-compose.yml', 'db', null, 'healthy'],
                ['docker-compose.yml', 'redis', null, 'healthy'],
            ]);

        $orchestrator = $this->createOrchestrator();
        $orchestrator->setup($config);

        $display = $this->output->fetch();
        $this->assertStringContainsString('Waiting for services', $display);
    }

    public function test_wait_for_services_throws_on_timeout(): void
    {
        $config = $this->createConfig(waitFor: [
            new ServiceWaitConfig(service: 'db', timeout: 1),
        ]);

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');
        $this->dockerCompose->method('getLatestLogLine')->willReturn('Still starting...');

        // Service never becomes healthy
        $this->healthChecker->method('getHealthStatus')->willReturn('starting');

        $this->expectException(\Cortex\Docker\Exception\ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/did not become healthy/');

        $orchestrator = $this->createOrchestrator();
        $orchestrator->setup($config);
    }

    public function test_setup_fails_fast_when_monitored_service_is_crash_looping(): void
    {
        $config = $this->createConfig(waitFor: [
            new ServiceWaitConfig(service: 'db', timeout: 30),
        ]);

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');
        $this->dockerCompose->method('getLatestLogLine')->willReturn(null);
        $this->dockerCompose->method('listServices')->willReturn(['db', 'app', 'nginx']);

        // db would be healthy immediately, but nginx is crash-looping because
        // the app container never came up. The waiter must surface this rather
        // than declaring the environment ready.
        $this->healthChecker->method('getHealthStatus')
            ->willReturnCallback(function (string $composeFile, string $service): string {
                return match ($service) {
                    'db' => 'healthy',
                    'app' => 'running',
                    'nginx' => 'restarting',
                    default => 'unknown',
                };
            });

        $this->expectException(\Cortex\Docker\Exception\ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/nginx \(restarting\)/');

        $orchestrator = $this->createOrchestrator();
        $orchestrator->setup($config);
    }

    public function test_setup_still_verifies_services_when_wait_for_is_empty(): void
    {
        $config = $this->createConfig();

        $this->dockerCompose->method('hasExistingImages')->willReturn(true);
        $this->dockerCompose->expects($this->once())->method('up');
        $this->dockerCompose->method('listServices')->willReturn(['app', 'nginx']);

        // No wait_for configured, but app is crash-looping — cortex must still
        // catch this instead of blindly declaring success.
        $this->healthChecker->method('getHealthStatus')
            ->willReturnCallback(function (string $composeFile, string $service): string {
                return $service === 'app' ? 'restarting' : 'running';
            });

        $this->expectException(\Cortex\Docker\Exception\ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/app \(restarting\)/');

        $orchestrator = $this->createOrchestrator();
        $orchestrator->setup($config);
    }

    public function test_first_run_multiplies_timeout_by_10(): void
    {
        $config = $this->createConfig(waitFor: [
            new ServiceWaitConfig(service: 'db', timeout: 1),
        ]);

        $this->dockerCompose->method('hasExistingImages')->willReturn(false);
        $this->dockerCompose->expects($this->once())->method('up');
        $this->dockerCompose->method('getLatestLogLine')->willReturn(null);

        $callCount = 0;
        $this->healthChecker->method('getHealthStatus')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                // Return healthy on 2nd call (after 2s sleep which is within the 10x=10s timeout)
                return $callCount >= 2 ? 'healthy' : 'starting';
            });

        $orchestrator = $this->createOrchestrator();
        $result = $orchestrator->setup($config);

        // If timeout weren't extended, this would throw ServiceNotHealthyException
        // since the service takes >1s but timeout*10=10s allows it
        $this->assertGreaterThanOrEqual(0.0, $result['time']);
    }

    private function createOrchestrator(): SetupOrchestrator
    {
        return new SetupOrchestrator(
            $this->dockerCompose,
            $this->hostExecutor,
            $this->healthChecker,
            $this->formatter,
        );
    }

    /**
     * @param ServiceWaitConfig[] $waitFor
     */
    private function createConfig(array $waitFor = []): CortexConfig
    {
        return new CortexConfig(
            version: '1.0',
            docker: new DockerConfig(
                composeFile: 'docker-compose.yml',
                primaryService: 'app',
                appUrl: 'http://localhost:80',
                waitFor: $waitFor,
            ),
            setup: new SetupConfig(preStart: [], initialize: []),
            n8n: new N8nConfig(workflowsDir: './.n8n'),
            commands: [],
        );
    }
}
