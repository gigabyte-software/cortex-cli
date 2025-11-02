# Port Mapping Strategy for Cortex CLI

## 🎯 Problem Statement

When multiple Cortex instances run concurrently on the same host (e.g., in a DigitalOcean coding agent setup), Docker containers will conflict on exposed ports.

**Example conflict:**
- Instance 1: `app` container wants port 80
- Instance 2: `app` container wants port 80
- Result: ❌ Second instance fails

## ✅ Recommended Solution: Dynamic Override File Generation

Use Docker Compose's native `docker-compose.override.yml` pattern to dynamically remap ports without requiring users to modify their `docker-compose.yml`.

### Key Principle
**Zero user changes required.** Cortex handles everything automatically.

---

## 🏗️ Architecture

### Flow Diagram
```
┌─────────────────────────────────────────────────────────────┐
│  User runs: cortex up                                       │
└─────────────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────────────┐
│  1. Parse docker-compose.yml                                │
│     Extract port mappings:                                  │
│     - app: 80:80                                           │
│     - db: 5432:5432                                        │
│     - redis: 6379:6379                                     │
└─────────────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────────────┐
│  2. Detect which ports need host exposure                   │
│     Smart detection:                                        │
│     - app:80 → YES (web service)                           │
│     - db:5432 → NO (internal only, unless configured)      │
│     - redis:6379 → NO (internal only)                      │
└─────────────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────────────┐
│  3. Allocate available ports on host                        │
│     Port scanner:                                           │
│     - Scan range: 8000-9000 (configurable)                 │
│     - Check availability with socket test                  │
│     - app:80 → 8081 (available) ✓                          │
└─────────────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────────────┐
│  4. Generate docker-compose.override.yml                    │
│     services:                                               │
│       app:                                                  │
│         ports:                                              │
│           - "8081:80"  # Remapped                          │
└─────────────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────────────┐
│  5. Run: docker-compose up                                  │
│     Docker Compose automatically merges:                    │
│     - docker-compose.yml (original)                        │
│     - docker-compose.override.yml (generated)              │
└─────────────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────────────┐
│  6. Store allocation in .cortex.lock                        │
│     {                                                       │
│       "ports": {"http": 8081},                             │
│       "project_id": "abc123",                              │
│       "started_at": "2025-11-02T..."                       │
│     }                                                       │
└─────────────────────────────────────────────────────────────┘
                         ↓
┌─────────────────────────────────────────────────────────────┐
│  7. Display to user                                         │
│     ✅ Environment ready!                                   │
│     🌐 HTTP: http://localhost:8081                         │
└─────────────────────────────────────────────────────────────┘
```

---

## 📁 New Components

### 1. `src/Docker/ComposeFileParser.php`

**Responsibility:** Parse `docker-compose.yml` and extract port information

```php
namespace CortexCli\Docker;

class ComposeFileParser {
    public function __construct(
        private readonly SymfonyYamlParser $yamlParser
    ) {}
    
    /**
     * Parse compose file and extract all port mappings
     * 
     * @return array<string, array<PortMapping>>
     * Example: [
     *   'app' => [PortMapping(host: 80, container: 80, protocol: 'tcp')],
     *   'db' => [PortMapping(host: 5432, container: 5432, protocol: 'tcp')]
     * ]
     */
    public function parsePortMappings(string $composeFilePath): array;
    
    /**
     * Extract all services from compose file
     */
    public function getServices(string $composeFilePath): array;
    
    /**
     * Handle various port syntax formats:
     * - "80:80"
     * - "8080:80/tcp"
     * - "127.0.0.1:8080:80"
     * - Long form (target/published)
     */
    private function normalizePortSyntax(mixed $portDefinition): PortMapping;
}
```

### 2. `src/Docker/PortDetector.php`

**Responsibility:** Determine which services need host port exposure

```php
namespace CortexCli\Docker;

class PortDetector {
    /**
     * Well-known ports that typically need host exposure
     */
    private const WEB_PORTS = [80, 443, 3000, 3001, 4200, 5173, 8000, 8080, 8888];
    
    /**
     * Database/cache ports (typically internal only)
     */
    private const INTERNAL_PORTS = [3306, 5432, 6379, 27017, 9200];
    
    /**
     * Detect which services should be exposed to host
     * 
     * @param array $servicePorts Array of services with their ports
     * @param CortexConfig $config User configuration overrides
     * @return array Services that need host exposure
     */
    public function detectRequiredExposure(
        array $servicePorts,
        CortexConfig $config
    ): array;
    
    /**
     * Check if a port is typically a web service port
     */
    private function isWebServicePort(int $port): bool;
    
    /**
     * Apply user-defined overrides from cortex.yml
     */
    private function applyConfigOverrides(array $detected, CortexConfig $config): array;
}
```

### 3. `src/Docker/PortAllocator.php`

**Responsibility:** Find available ports on the host

```php
namespace CortexCli\Docker;

class PortAllocator {
    public function __construct(
        private readonly int $rangeStart = 8000,
        private readonly int $rangeEnd = 9000
    ) {}
    
    /**
     * Find an available port on the host
     * 
     * @param int $preferredPort Try this port first
     * @return int Available port number
     * @throws NoAvailablePortException
     */
    public function findAvailablePort(int $preferredPort): int;
    
    /**
     * Allocate ports for multiple services
     * 
     * @param array<string, PortMapping> $requiredPorts
     * @return array<string, int> Allocated ports ['http' => 8081, ...]
     */
    public function allocatePorts(array $requiredPorts): array;
    
    /**
     * Check if a specific port is available
     */
    public function isPortAvailable(int $port): bool;
    
    /**
     * Scan a range and return first available port
     */
    private function scanRange(int $start, int $end): ?int;
    
    /**
     * Test port availability using socket
     */
    private function testPort(int $port): bool;
}
```

### 4. `src/Docker/ComposeOverrideGenerator.php`

**Responsibility:** Generate `docker-compose.override.yml` with remapped ports

```php
namespace CortexCli\Docker;

use Symfony\Component\Yaml\Yaml;

class ComposeOverrideGenerator {
    private const OVERRIDE_HEADER = <<<YAML
# Generated by Cortex CLI - DO NOT EDIT MANUALLY
# This file is automatically created to prevent port conflicts
# Run 'cortex down' to remove this file
YAML;
    
    /**
     * Generate override file with new port mappings
     * 
     * @param array $originalServices Original service definitions
     * @param array $portMappings New port mappings ['app' => ['80' => 8081]]
     * @param string $outputPath Output file path
     */
    public function generate(
        array $originalServices,
        array $portMappings,
        string $outputPath = 'docker-compose.override.yml'
    ): void;
    
    /**
     * Build override structure for a single service
     */
    private function buildServiceOverride(
        string $serviceName,
        array $portMapping
    ): array;
    
    /**
     * Convert port mapping to Docker Compose format
     * Input: ['80' => 8081]
     * Output: ['8081:80']
     */
    private function formatPortMapping(int $containerPort, int $hostPort): string;
    
    /**
     * Handle existing override file
     * If user has custom overrides, merge them
     */
    private function handleExistingOverride(string $path): ?array;
    
    /**
     * Clean up generated override file
     */
    public function cleanup(string $path = 'docker-compose.override.yml'): void;
}
```

### 5. `src/Docker/PortMapping.php` (Value Object)

**Responsibility:** Represent a port mapping

```php
namespace CortexCli\Docker;

readonly class PortMapping {
    public function __construct(
        public ?int $hostPort,        // null if not exposed to host
        public int $containerPort,
        public string $protocol = 'tcp',
        public ?string $hostInterface = null, // e.g., '127.0.0.1' or null for all
        public string $serviceName = '',      // Which service this belongs to
    ) {}
    
    public function needsHostExposure(): bool {
        return $this->hostPort !== null;
    }
    
    public function toDockerComposeFormat(): string {
        // Convert to "8081:80/tcp" format
    }
}
```

### 6. `src/Config/ProjectLockFile.php`

**Responsibility:** Manage `.cortex.lock` file with port allocations

```php
namespace CortexCli\Config;

readonly class ProjectLockFile {
    public function __construct(
        private string $lockFilePath = '.cortex.lock'
    ) {}
    
    /**
     * Write lock file with allocated ports and metadata
     */
    public function write(LockFileData $data): void;
    
    /**
     * Read existing lock file
     */
    public function read(): ?LockFileData;
    
    /**
     * Check if environment is already running
     */
    public function exists(): bool;
    
    /**
     * Delete lock file
     */
    public function delete(): void;
    
    /**
     * Check if lock file is stale (containers not actually running)
     */
    public function isStale(): bool;
}

// Value object for lock file data
readonly class LockFileData {
    public function __construct(
        public array $ports,           // ['http' => 8081, 'db' => 54321]
        public string $projectId,      // Unique identifier
        public string $startedAt,      // ISO 8601 timestamp
        public string $composeFile,    // Path to compose file
    ) {}
}
```

---

## 📝 Updated cortex.yml Schema

### Minimal (Auto-detection)
```yaml
version: "1.0"

docker:
  compose_file: "docker-compose.yml"
  primary_service: "app"
  
  wait_for:
    - service: "db"
      timeout: 60
```

Cortex will automatically:
- Parse `docker-compose.yml`
- Detect web service ports (80, 443, 3000, etc.)
- Expose only web services, keep databases internal
- Allocate ports in range 8000-9000

### Advanced (Explicit Configuration)
```yaml
version: "1.0"

docker:
  compose_file: "docker-compose.yml"
  primary_service: "app"
  
  # Port exposure configuration
  ports:
    # Explicit list of services to expose
    expose_to_host:
      - service: "app"
        container_port: 80
        name: "http"              # User-friendly name
        preferred_port: 80        # Try this first
        
      - service: "frontend"
        container_port: 3000
        name: "frontend"
        preferred_port: 3000
        
      - service: "api"
        container_port: 8000
        name: "api"
        preferred_port: 8080
        
      # Explicitly expose database (usually not needed)
      - service: "db"
        container_port: 5432
        name: "postgres"
        preferred_port: 5432
    
    # Port allocation settings
    allocation:
      range_start: 8000
      range_end: 9000
      strategy: "random"  # or "sequential"
  
  wait_for:
    - service: "db"
      timeout: 60
```

---

## 🔄 Updated Orchestration Flow

### `SetupOrchestrator.php` Updates

```php
namespace CortexCli\Orchestrator;

class SetupOrchestrator {
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly ProjectLockFile $lockFile,
        private readonly ComposeFileParser $composeParser,
        private readonly PortDetector $portDetector,
        private readonly PortAllocator $portAllocator,
        private readonly ComposeOverrideGenerator $overrideGenerator,
        private readonly DockerCompose $dockerCompose,
        private readonly HealthChecker $healthChecker,
        private readonly HostCommandExecutor $hostExecutor,
        private readonly ContainerCommandExecutor $containerExecutor,
        private readonly OutputFormatter $output
    ) {}
    
    public function setup(CortexConfig $config): void {
        // 1. Check if already running
        if ($this->lockFile->exists() && !$this->lockFile->isStale()) {
            $lockData = $this->lockFile->read();
            throw new AlreadyRunningException(
                "Environment already running. Exposed on ports: " . 
                json_encode($lockData->ports)
            );
        }
        
        $this->output->section('🔍 Analyzing Docker configuration');
        
        // 2. Parse docker-compose.yml
        $servicePorts = $this->composeParser->parsePortMappings(
            $config->docker->composeFile
        );
        $this->output->info("Found " . count($servicePorts) . " services");
        
        // 3. Detect which services need host exposure
        $requiredExposure = $this->portDetector->detectRequiredExposure(
            $servicePorts,
            $config
        );
        $this->output->info("Exposing " . count($requiredExposure) . " services to host");
        
        // 4. Allocate available ports
        $this->output->section('🎲 Allocating ports');
        $allocatedPorts = $this->portAllocator->allocatePorts($requiredExposure);
        
        foreach ($allocatedPorts as $name => $port) {
            $this->output->success("  {$name} → localhost:{$port}");
        }
        
        // 5. Generate docker-compose.override.yml
        $this->overrideGenerator->generate(
            $servicePorts,
            $allocatedPorts,
            'docker-compose.override.yml'
        );
        
        // 6. Run pre-start commands
        $this->output->section('📦 Pre-start commands');
        $this->runPreStartCommands($config->setup->preStart);
        
        // 7. Start Docker services
        $this->output->section('🐳 Starting Docker services');
        $this->dockerCompose->up($config->docker->composeFile);
        
        // 8. Wait for health
        $this->output->section('⏳ Waiting for services');
        $this->waitForServices($config->docker->waitFor);
        
        // 9. Run initialize commands
        $this->output->section('🔧 Initialize commands');
        $this->runInitializeCommands(
            $config->setup->initialize,
            $config->docker->primaryService
        );
        
        // 10. Write lock file
        $lockData = new LockFileData(
            ports: $allocatedPorts,
            projectId: $this->generateProjectId(),
            startedAt: (new \DateTime())->format(\DateTime::ISO8601),
            composeFile: $config->docker->composeFile
        );
        $this->lockFile->write($lockData);
        
        // 11. Display success message
        $this->output->section('✅ Environment ready!');
        foreach ($allocatedPorts as $name => $port) {
            $this->output->success("🌐 {$name}: http://localhost:{$port}");
        }
    }
    
    private function generateProjectId(): string {
        return substr(
            hash('sha256', getcwd() . getmypid() . microtime(true)),
            0,
            12
        );
    }
}
```

---

## 🎨 User Experience Examples

### Scenario 1: First Instance (Port 80 Available)
```bash
$ cortex up

┌─────────────────────────────────────┐
│  🚀 Starting Development Environment │
└─────────────────────────────────────┘

🔍 Analyzing Docker configuration
  Found 3 services (app, db, redis)
  Exposing 1 service to host

🎲 Allocating ports
  ✓ http → localhost:80

📦 Pre-start commands
  ✓ Create environment file

🐳 Starting Docker services
  ✓ Services started

⏳ Waiting for services
  ✓ db (healthy after 5s)
  ✓ redis (healthy after 2s)

🔧 Initialize commands
  ► Installing PHP dependencies...
  ✓ Completed in 45s

✅ Environment ready!
🌐 http: http://localhost:80
```

### Scenario 2: Second Instance (Port 80 Taken)
```bash
$ cortex up

┌─────────────────────────────────────┐
│  🚀 Starting Development Environment │
└─────────────────────────────────────┘

🔍 Analyzing Docker configuration
  Found 3 services (app, db, redis)
  Exposing 1 service to host

🎲 Allocating ports
  ⚠️  Port 80 unavailable, finding alternative...
  ✓ http → localhost:8081

📦 Pre-start commands
  ✓ Create environment file

🐳 Starting Docker services
  ✓ Services started

⏳ Waiting for services
  ✓ db (healthy after 5s)
  ✓ redis (healthy after 2s)

🔧 Initialize commands
  ► Installing PHP dependencies...
  ✓ Completed in 45s

✅ Environment ready!
🌐 http: http://localhost:8081
```

### Scenario 3: Environment Already Running
```bash
$ cortex up

❌ Error: Environment already running
   Exposed on: {"http": 8081}
   
   Use 'cortex down' to stop the environment first.
   Or use 'cortex status' to check current state.
```

---

## 📊 Status Command Enhancement

```bash
$ cortex status

📊 Environment Status
Project ID: abc123f4e5d6
Started: 2 hours ago
Compose file: docker-compose.yml

🌐 Exposed Ports:
┌──────────┬──────────────────────────┐
│ Name     │ URL                      │
├──────────┼──────────────────────────┤
│ http     │ http://localhost:8081    │
└──────────┴──────────────────────────┘

📦 Services:
┌─────────┬──────────┬─────────┬──────────┐
│ Service │ Status   │ Health  │ Ports    │
├─────────┼──────────┼─────────┼──────────┤
│ app     │ running  │ healthy │ 8081:80  │
│ db      │ running  │ healthy │ internal │
│ redis   │ running  │ healthy │ internal │
└─────────┴──────────┴─────────┴──────────┘
```

---

## 🧹 Cleanup Process

### On `cortex down`:

```php
public function teardown(CortexConfig $config): void {
    $this->output->section('🛑 Stopping environment');
    
    // 1. Stop Docker services
    $this->dockerCompose->down($config->docker->composeFile);
    
    // 2. Remove override file
    $this->overrideGenerator->cleanup();
    
    // 3. Remove lock file
    $this->lockFile->delete();
    
    $this->output->success('✅ Environment stopped');
}
```

### Files to .gitignore:
```gitignore
# Cortex CLI generated files
.cortex.lock
docker-compose.override.yml
.cortex-compose.override.yml
```

---

## 🔧 Edge Cases & Solutions

### 1. User has existing docker-compose.override.yml

**Solution:** 
- Option A: Merge with user's overrides (complex)
- Option B: Use alternative name: `.cortex-compose.override.yml` and specify with `-f` flag:
  ```bash
  docker-compose -f docker-compose.yml -f .cortex-compose.override.yml up
  ```

**Recommended:** Option B (cleaner separation)

### 2. Complex port syntax

```yaml
# Various formats to handle
ports:
  - "80:80"                    # Simple
  - "127.0.0.1:80:80"         # Bind to specific interface
  - "8080-8090:80-90"         # Port range (rare)
  - target: 80                # Long syntax
    published: 8080
    protocol: tcp
    mode: host
```

**Solution:** Parse all formats, preserve original structure, only modify host port.

### 3. No ports available in range

```php
if (!$availablePort) {
    throw new NoAvailablePortException(
        "No available ports in range {$rangeStart}-{$rangeEnd}. " .
        "Increase range in cortex.yml or stop other services."
    );
}
```

### 4. Stale lock file

If `.cortex.lock` exists but containers aren't actually running:

```php
public function isStale(): bool {
    if (!$this->exists()) {
        return false;
    }
    
    $data = $this->read();
    
    // Check if Docker services are actually running
    return !$this->dockerCompose->isRunning($data->composeFile);
}
```

Auto-cleanup stale locks on next `cortex up`.

### 5. Named/multiple compose files

```yaml
# cortex.yml
docker:
  compose_file: "docker/compose.dev.yml"
```

Generate override in same directory: `docker/compose.dev.override.yml`

---

## 🧪 Testing Strategy

### Unit Tests

```php
// tests/Unit/Docker/ComposeFileParserTest.php
test_it_parses_simple_port_syntax()
test_it_parses_long_form_port_syntax()
test_it_parses_interface_specific_ports()
test_it_handles_port_ranges()

// tests/Unit/Docker/PortDetectorTest.php
test_it_detects_web_service_ports()
test_it_marks_database_ports_as_internal()
test_it_applies_config_overrides()

// tests/Unit/Docker/PortAllocatorTest.php
test_it_finds_available_port()
test_it_falls_back_to_range_when_preferred_taken()
test_it_throws_exception_when_no_ports_available()

// tests/Unit/Docker/ComposeOverrideGeneratorTest.php
test_it_generates_valid_override_file()
test_it_preserves_protocol_in_port_mapping()
test_it_handles_multiple_services()
```

### Integration Tests

```php
// tests/Integration/PortMappingIntegrationTest.php
test_it_allocates_unique_ports_for_concurrent_instances()
test_it_cleans_up_override_file_on_down()
test_it_detects_and_handles_stale_lock_files()
test_it_prevents_duplicate_instances()
```

---

## 📋 Implementation Checklist

### Phase 1: Port Detection & Allocation
- [ ] Implement `ComposeFileParser`
- [ ] Implement `PortMapping` value object
- [ ] Implement `PortDetector`
- [ ] Implement `PortAllocator`
- [ ] Unit tests for all components

### Phase 2: Override Generation
- [ ] Implement `ComposeOverrideGenerator`
- [ ] Handle various port syntax formats
- [ ] Test YAML generation
- [ ] Handle existing override files

### Phase 3: Lock File Management
- [ ] Implement `ProjectLockFile`
- [ ] Implement `LockFileData` value object
- [ ] Stale lock detection
- [ ] Lock file cleanup

### Phase 4: Integration
- [ ] Update `SetupOrchestrator` with port logic
- [ ] Update `UpCommand` to display allocated ports
- [ ] Update `DownCommand` to cleanup files
- [ ] Update `StatusCommand` to show port mappings

### Phase 5: Configuration
- [ ] Extend `cortex.yml` schema for port config
- [ ] Add port configuration validation
- [ ] Add config examples to docs

### Phase 6: Testing & Polish
- [ ] Integration tests with real Docker
- [ ] Test concurrent instances
- [ ] Error message improvements
- [ ] Documentation

---

## 🚀 Benefits

✅ **Zero friction** - No user changes to docker-compose.yml  
✅ **Concurrent instances** - Multiple projects can run simultaneously  
✅ **Standard Docker** - Uses native Docker Compose override pattern  
✅ **Transparent** - Users can inspect generated override file  
✅ **Clean** - Auto-cleanup on teardown  
✅ **Flexible** - Optional cortex.yml overrides for edge cases  
✅ **Smart** - Auto-detects which ports need exposure  
✅ **Reliable** - Lock file prevents duplicate instances  

---

## 🎯 Success Criteria

The port mapping implementation is successful when:

1. ✅ Multiple Cortex instances run concurrently without conflicts
2. ✅ Users don't need to modify their docker-compose.yml
3. ✅ Web service ports are automatically exposed
4. ✅ Database ports stay internal (unless configured otherwise)
5. ✅ Clear output shows which ports are allocated
6. ✅ Playwright can connect to dynamically allocated HTTP port
7. ✅ Clean teardown removes all generated files
8. ✅ Stale lock files are detected and handled
9. ✅ Works with various docker-compose.yml formats
10. ✅ 90%+ test coverage for port-related components

---

## 🔮 Future Enhancements

### Port Reservation System
Persist allocated ports in a global registry to prevent race conditions when multiple instances start simultaneously.

### Port Range Profiles
```yaml
ports:
  allocation:
    profile: "high-range"  # Uses 9000-9999
```

### Intelligent Port Assignment
Remember previous port assignments and try to reuse them for consistency.

### HTTP/HTTPS Detection
Automatically detect SSL certificates and expose 443 when needed.

---

**This port mapping strategy ensures Cortex CLI works seamlessly in multi-tenant coding agent environments while maintaining simplicity for end users.** 🎉

