<?php

declare(strict_types=1);

namespace Cortex\Tests\Unit\Docker;

use Cortex\Config\Schema\ServiceWaitConfig;
use Cortex\Docker\DockerCompose;
use Cortex\Docker\Exception\ServiceNotHealthyException;
use Cortex\Docker\HealthChecker;
use Cortex\Docker\ServiceReadinessWaiter;
use Cortex\Output\OutputFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class ServiceReadinessWaiterTest extends TestCase
{
    /** @var DockerCompose&MockObject */
    private DockerCompose $dockerCompose;

    /** @var HealthChecker&MockObject */
    private HealthChecker $healthChecker;

    private OutputFormatter $formatter;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->healthChecker = $this->createMock(HealthChecker::class);
        $this->output = new BufferedOutput();
        $this->formatter = new OutputFormatter($this->output);
    }

    public function test_returns_immediately_when_wait_list_is_empty(): void
    {
        $waiter = new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
        );

        $this->healthChecker->expects($this->never())->method('getHealthStatus');

        $waiter->waitForAll('docker-compose.yml', []);

        $this->assertTrue(true);
    }

    public function test_waits_until_service_becomes_healthy(): void
    {
        $this->healthChecker->method('getHealthStatus')->willReturn('healthy');
        $this->dockerCompose->method('getLatestLogLines')->willReturn([]);

        $waiter = new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
        );

        $waiter->waitForAll('docker-compose.yml', [
            new ServiceWaitConfig(service: 'db', timeout: 5),
        ]);

        $this->assertTrue(true);
    }

    public function test_throws_when_service_never_becomes_healthy(): void
    {
        $this->healthChecker->method('getHealthStatus')->willReturn('starting');
        $this->dockerCompose->method('getLatestLogLines')->willReturn([
            'Line one',
            'Line two',
            'Line three',
        ]);

        $waiter = new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
        );

        $this->expectException(ServiceNotHealthyException::class);
        $this->expectExceptionMessageMatches('/did not become healthy/');

        $waiter->waitForAll('docker-compose.yml', [
            new ServiceWaitConfig(service: 'db', timeout: 1),
        ]);
    }

    public function test_fetches_multi_line_logs_from_docker_compose(): void
    {
        $this->healthChecker->method('getHealthStatus')
            ->willReturnOnConsecutiveCalls('starting', 'healthy');

        $this->dockerCompose->expects($this->atLeastOnce())
            ->method('getLatestLogLines')
            ->with('docker-compose.yml', 'db', 3, null)
            ->willReturn(['log-1', 'log-2', 'log-3']);

        $waiter = new ServiceReadinessWaiter(
            $this->dockerCompose,
            $this->healthChecker,
            $this->formatter,
        );

        $waiter->waitForAll('docker-compose.yml', [
            new ServiceWaitConfig(service: 'db', timeout: 10),
        ]);

        $this->assertTrue(true);
    }
}
