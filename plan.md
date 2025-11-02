# Cortex CLI - Development Plan

## 📋 Project Overview

A PHP-based CLI tool that reads a `cortex.yml` configuration file and orchestrates Docker-based development environments. The tool will be compiled into a PHAR for easy distribution.

**Architecture:** Layered Architecture (Approach 1)  
**PHP Version:** 8.2+  
**Main Dependencies:** Symfony Console, Symfony Process, Symfony YAML

---

## 🎯 Core Commands

### Primary Commands
- `cortex up` - Main setup command (pre-start → docker up → wait for health → initialize)
- `cortex down` - Tear down the Docker environment
- `cortex status` - Check health status of services
- `cortex <custom>` - Run custom commands defined in `cortex.yml` (e.g., `cortex test`, `cortex fresh_db`)

---

## 📁 Directory Structure

```
cortex-cli/
├── bin/
│   └── cortex                          # Entry point executable
├── src/
│   ├── Command/                        # Symfony Console commands
│   │   ├── UpCommand.php              # cortex up
│   │   ├── DownCommand.php            # cortex down
│   │   ├── StatusCommand.php          # cortex status
│   │   └── RunCommand.php             # cortex <custom>
│   │
│   ├── Config/                         # Configuration layer
│   │   ├── ConfigLoader.php           # Loads and validates cortex.yml
│   │   ├── Schema/                    # Value objects for config
│   │   │   ├── CortexConfig.php      # Root config object
│   │   │   ├── DockerConfig.php      # docker: section
│   │   │   ├── SetupConfig.php       # setup: section
│   │   │   ├── CommandDefinition.php  # Individual command definition
│   │   │   └── ServiceWaitConfig.php  # Service wait configuration
│   │   └── Validator/
│   │       └── ConfigValidator.php    # Validates config structure
│   │
│   ├── Docker/                         # Docker interaction layer
│   │   ├── DockerCompose.php          # docker-compose wrapper
│   │   ├── ContainerExecutor.php      # docker exec wrapper
│   │   ├── HealthChecker.php          # Service health checking
│   │   └── Exception/
│   │       ├── ServiceNotHealthyException.php
│   │       └── ContainerNotFoundException.php
│   │
│   ├── Executor/                       # Command execution layer
│   │   ├── CommandExecutor.php        # Base executor interface/abstract
│   │   ├── HostCommandExecutor.php    # Runs commands on host
│   │   ├── ContainerCommandExecutor.php # Runs commands in container
│   │   ├── Strategy/
│   │   │   └── RetryStrategy.php      # Retry logic with configurable attempts
│   │   └── Result/
│   │       └── ExecutionResult.php    # Command execution result object
│   │
│   ├── Orchestrator/                   # High-level orchestration
│   │   ├── SetupOrchestrator.php      # Orchestrates full setup flow
│   │   └── CommandOrchestrator.php    # Orchestrates custom commands
│   │
│   ├── Output/                         # Output formatting
│   │   ├── OutputFormatter.php        # Colorized, formatted console output
│   │   └── ProgressIndicator.php      # Progress bars/spinners
│   │
│   └── Application.php                 # Main Symfony Console application
│
├── config/
│   └── services.yaml                   # Dependency injection configuration
│
├── tests/
│   ├── Unit/
│   │   ├── Config/
│   │   ├── Docker/
│   │   ├── Executor/
│   │   └── Orchestrator/
│   ├── Integration/
│   │   └── Command/
│   └── fixtures/
│       ├── cortex.yml                 # Test config file
│       └── docker-compose.test.yml    # Test docker compose
│
├── box.json                            # PHAR build configuration
├── composer.json
├── phpstan.neon                        # Static analysis config
├── .php-cs-fixer.php                  # Code style config
├── phpunit.xml                         # PHPUnit configuration
├── README.md
└── LICENSE
```

---

## 🔧 Component Details

### 1. Configuration Layer (`src/Config/`)

#### `ConfigLoader.php`
- **Responsibility:** Load and parse `cortex.yml` from project root
- **Methods:**
  - `load(string $path = 'cortex.yml'): CortexConfig`
  - `findConfigFile(): string` - Search for cortex.yml in current/parent dirs
- **Dependencies:** symfony/yaml

#### `ConfigValidator.php`
- **Responsibility:** Validate configuration structure and required fields
- **Methods:**
  - `validate(array $config): void` - Throws exception if invalid
  - `validateDockerSection(array $docker): void`
  - `validateSetupSection(array $setup): void`
- **Validation Rules:**
  - Required: `version`, `docker.compose_file`, `docker.primary_service`
  - Optional but typed: all other sections
  - Command timeouts must be positive integers
  - Retry counts must be non-negative

#### Schema Value Objects
All immutable DTOs with typed properties (PHP 8.2+ features):

**`CortexConfig.php`**
```php
readonly class CortexConfig {
    public function __construct(
        public string $version,
        public DockerConfig $docker,
        public SetupConfig $setup,
        public array $commands, // CommandDefinition[]
    ) {}
}
```

**`DockerConfig.php`**
```php
readonly class DockerConfig {
    public function __construct(
        public string $composeFile,
        public string $primaryService,
        public array $waitFor, // ServiceWaitConfig[]
    ) {}
}
```

**`SetupConfig.php`**
```php
readonly class SetupConfig {
    public function __construct(
        public array $preStart,    // CommandDefinition[]
        public array $initialize,  // CommandDefinition[]
    ) {}
}
```

**`CommandDefinition.php`**
```php
readonly class CommandDefinition {
    public function __construct(
        public string $command,
        public string $description,
        public int $timeout = 60,
        public int $retry = 0,
        public bool $ignoreFailure = false, // New property
    ) {}
}
```

**`ServiceWaitConfig.php`**
```php
readonly class ServiceWaitConfig {
    public function __construct(
        public string $service,
        public int $timeout,
    ) {}
}
```

---

### 2. Docker Layer (`src/Docker/`)

#### `DockerCompose.php`
- **Responsibility:** Wrapper for docker-compose commands
- **Methods:**
  - `up(string $composeFile): void` - Start services
  - `down(string $composeFile, bool $volumes = false): void` - Stop services
  - `ps(string $composeFile): array` - List running services
  - `isRunning(string $composeFile): bool` - Check if any services running
- **Implementation:** Uses Symfony Process component

#### `ContainerExecutor.php`
- **Responsibility:** Execute commands inside Docker containers
- **Methods:**
  - `exec(string $service, string $command, int $timeout = 60): ExecutionResult`
  - `execInteractive(string $service, string $command): void` - For shell access
- **Features:**
  - Real-time output streaming to console
  - Timeout handling
  - Proper TTY handling for interactive vs non-interactive

#### `HealthChecker.php`
- **Responsibility:** Monitor Docker service health
- **Methods:**
  - `isHealthy(string $service): bool` - Check single service
  - `waitForHealth(string $service, int $timeout): void` - Wait with polling
  - `getHealthStatus(string $service): string` - Get detailed status
- **Implementation:**
  - Uses `docker inspect` to check health status
  - Polls every 2 seconds during wait
  - Throws `ServiceNotHealthyException` on timeout

---

### 3. Executor Layer (`src/Executor/`)

#### `CommandExecutor.php` (Abstract)
- **Responsibility:** Base class/interface for command execution
- **Methods:**
  - `execute(CommandDefinition $cmd): ExecutionResult` (abstract)
  - `executeWithRetry(CommandDefinition $cmd): ExecutionResult` - Implements retry logic
  - `shouldIgnoreFailure(CommandDefinition $cmd): bool`

#### `HostCommandExecutor.php`
- **Responsibility:** Execute commands on host machine
- **Methods:**
  - `execute(CommandDefinition $cmd): ExecutionResult`
- **Use Cases:** 
  - Pre-start commands (e.g., copying .env file)
  - Any host-level operations

#### `ContainerCommandExecutor.php`
- **Responsibility:** Execute commands inside primary Docker container
- **Methods:**
  - `execute(CommandDefinition $cmd): ExecutionResult`
- **Dependencies:** `ContainerExecutor` (Docker layer)
- **Use Cases:**
  - Initialize commands (composer install, migrations, etc.)
  - Custom commands from cortex.yml

#### `RetryStrategy.php`
- **Responsibility:** Implement retry logic with exponential backoff
- **Methods:**
  - `execute(callable $operation, int $maxRetries): mixed`
  - `calculateDelay(int $attempt): int` - Exponential backoff
- **Configuration:**
  - Initial delay: 2 seconds
  - Max delay: 30 seconds
  - Exponential factor: 2

#### `ExecutionResult.php`
- **Responsibility:** Value object for command results
```php
readonly class ExecutionResult {
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public bool $successful,
        public float $executionTime,
    ) {}
    
    public static function fromProcess(Process $process): self;
    public function isSuccessful(): bool;
}
```

---

### 4. Orchestrator Layer (`src/Orchestrator/`)

#### `SetupOrchestrator.php`
- **Responsibility:** Coordinate the entire `cortex up` flow
- **Methods:**
  - `setup(CortexConfig $config): void` - Main orchestration method
  - `runPreStartCommands(array $commands): void`
  - `startDockerServices(DockerConfig $docker): void`
  - `waitForServices(array $waitFor): void`
  - `runInitializeCommands(array $commands, string $primaryService): void`
- **Flow:**
  1. Display welcome message
  2. Execute pre-start commands on host
  3. Start Docker Compose
  4. Wait for service health checks
  5. Execute initialize commands in container
  6. Display success summary
- **Error Handling:**
  - Catch exceptions at each stage
  - Display clear error messages
  - Respect `ignoreFailure` flag per command
  - Exit with appropriate code

#### `CommandOrchestrator.php`
- **Responsibility:** Execute custom commands from `cortex.yml`
- **Methods:**
  - `run(string $commandName, CortexConfig $config): void`
  - `listAvailableCommands(CortexConfig $config): array`
- **Features:**
  - Look up command by name
  - Execute in primary container
  - Display command description
  - Show execution time

---

### 5. Output Layer (`src/Output/`)

#### `OutputFormatter.php`
- **Responsibility:** Provide colorful, readable console output
- **Methods:**
  - `section(string $title): void` - Display section header
  - `command(CommandDefinition $cmd): void` - Format command being executed
  - `success(string $message): void` - Green success message
  - `error(string $message): void` - Red error message
  - `warning(string $message): void` - Yellow warning
  - `info(string $message): void` - Blue info
  - `commandOutput(string $output): void` - Display command output (real-time)
- **Colors:** Use Symfony Console OutputInterface styling
- **Format Example:**
```
┌─────────────────────────────────────┐
│  🚀 Starting Development Environment │
└─────────────────────────────────────┘

📦 Pre-start commands
  ✓ Create environment file if it doesn't exist

🐳 Starting Docker services
  ⠋ Starting containers...
  ✓ Services started

⏳ Waiting for services
  ✓ db (healthy after 5s)
  ✓ redis (healthy after 2s)

🔧 Initialize commands
  ► Installing PHP dependencies...
    [composer output streams here]
  ✓ Completed in 45s
```

#### `ProgressIndicator.php`
- **Responsibility:** Animated spinners and progress bars
- **Methods:**
  - `start(string $message): void` - Start spinner
  - `stop(): void` - Stop spinner
  - `advance(): void` - Next frame
- **Implementation:** Use Symfony Console ProgressBar/Spinner

---

### 6. Commands (`src/Command/`)

All extend `Symfony\Component\Console\Command\Command`

#### `UpCommand.php`
- **Command:** `cortex up`
- **Description:** "Set up the development environment"
- **Options:**
  - `--no-wait` - Skip health checks
  - `--skip-init` - Skip initialize commands
- **Execution:**
  1. Find cortex.yml
  2. Load and validate config
  3. Call SetupOrchestrator
  4. Handle errors gracefully

#### `DownCommand.php`
- **Command:** `cortex down`
- **Description:** "Tear down the development environment"
- **Options:**
  - `--volumes` - Remove volumes too
- **Execution:**
  1. Find cortex.yml (for compose file path)
  2. Call DockerCompose::down()
  3. Confirm success

#### `StatusCommand.php`
- **Command:** `cortex status`
- **Description:** "Check the health status of services"
- **Execution:**
  1. Load config
  2. Check docker-compose ps
  3. Check health of each service in wait_for
  4. Display formatted status table

#### `RunCommand.php`
- **Command:** `cortex <custom>`
- **Description:** "Run a custom command defined in cortex.yml"
- **Arguments:**
  - `command-name` - Name of the command (e.g., "test", "fresh_db")
- **Options:**
  - `--list` - List all available commands
- **Execution:**
  1. Load config
  2. Lookup command in config.commands
  3. Execute via CommandOrchestrator
  4. Display output and result

---

## 📦 Dependencies (composer.json)

### Required Packages
```json
{
  "require": {
    "php": "^8.2",
    "symfony/console": "^7.0",
    "symfony/process": "^7.0",
    "symfony/yaml": "^7.0",
    "symfony/dependency-injection": "^7.0",
    "symfony/config": "^7.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.0",
    "phpstan/phpstan": "^1.10",
    "friendsofphp/php-cs-fixer": "^3.40",
    "mockery/mockery": "^1.6"
  }
}
```

---

## 🔨 PHAR Build Configuration

### `box.json`
```json
{
  "main": "bin/cortex",
  "output": "cortex.phar",
  "directories": ["src"],
  "files": [
    "composer.json"
  ],
  "finder": [
    {
      "name": "*.php",
      "exclude": ["tests"],
      "in": "vendor"
    }
  ],
  "compression": "GZ",
  "compactors": [
    "KevinGH\\Box\\Compactor\\Php"
  ],
  "stub": true
}
```

### Build Process
1. Install dependencies: `composer install --no-dev --optimize-autoloader`
2. Build PHAR: `box compile`
3. Make executable: `chmod +x cortex.phar`
4. Test: `./cortex.phar up`

---

## 🎨 Output Design Philosophy

### Color Scheme
- **Blue** - Informational messages, section headers
- **Green** - Success messages, completed steps
- **Yellow** - Warnings, skipped steps
- **Red** - Errors, failures
- **Gray** - Command output (streamed through)

### Real-time Output
- Stream command output directly to console as it happens
- Prefix each line with subtle indent/marker
- Preserve colors from underlying commands
- Show execution time after completion

### Progress Indicators
- Use spinners for operations without progress (waiting for health)
- Use progress bars for operations with progress (if detectable)
- Always show what's happening: "Installing dependencies..." not just spinner

---

## 🧪 Testing Strategy

### Unit Tests
- **Config Layer:** Test YAML parsing, validation, edge cases
- **Docker Layer:** Mock docker commands, test command building
- **Executor Layer:** Test retry logic, timeout handling
- **Orchestrator Layer:** Test flow coordination with mocks

### Integration Tests
- Use test fixtures with minimal Docker setup
- Test actual command execution in container
- Test health checking with real services
- Test full `cortex up` flow end-to-end

### Test Fixtures
- Minimal `docker-compose.test.yml` with simple services
- Various `cortex.yml` variants for different scenarios
- Mock commands that succeed/fail for testing error handling

---

## 🚀 Implementation Phases

### Phase 1: Foundation (First PR)
1. Setup project structure
2. Implement Config layer (ConfigLoader, validation, schema objects)
3. Implement basic Docker layer (DockerCompose wrapper)
4. Implement OutputFormatter (colorful output)
5. Create basic `UpCommand` skeleton
6. Add tests for Config layer

### Phase 2: Core Execution (Second PR)
1. Implement Executor layer (Host, Container, Retry)
2. Implement HealthChecker
3. Complete ContainerExecutor
4. Add ExecutionResult
5. Tests for Executor and Docker layers

### Phase 3: Orchestration (Third PR)
1. Implement SetupOrchestrator
2. Complete UpCommand integration
3. Implement DownCommand
4. Implement StatusCommand
5. Real-time output streaming
6. Integration tests

### Phase 4: Custom Commands (Fourth PR)
1. Implement CommandOrchestrator
2. Implement RunCommand
3. Add command listing functionality
4. Tests for custom commands

### Phase 5: Polish & PHAR (Fifth PR)
1. Add ProgressIndicator animations
2. Improve error messages
3. Setup box.json
4. Build and test PHAR
5. Documentation (README)
6. Release preparation

---

## 🔐 Error Handling Strategy

### Per-Command Error Handling
- Each `CommandDefinition` has `ignoreFailure` property
- If `ignoreFailure = true`: Display warning, continue execution
- If `ignoreFailure = false`: Stop execution, display error, exit

### Retry Logic
- Retry only on commands with `retry > 0`
- Exponential backoff between retries
- Display retry attempts: "Retrying (attempt 2/3)..."
- If all retries exhausted and still fails: treat as error

### Exception Hierarchy
```
CortexException (base)
├── ConfigException (invalid config)
├── DockerException (docker errors)
│   ├── ServiceNotHealthyException
│   └── ContainerNotFoundException
└── ExecutionException (command failures)
    ├── TimeoutException
    └── RetryExhaustedException
```

### User-Friendly Messages
- Never show raw stack traces to users (unless -vvv debug mode later)
- Show clear, actionable error messages
- Suggest fixes where possible:
  - "Service 'db' not healthy. Check `docker-compose logs db`"
  - "Command timed out. Try increasing timeout in cortex.yml"

---

## 🎯 User Experience Goals

### Speed
- Start containers in parallel where possible
- Don't wait unnecessarily
- Show progress immediately

### Clarity
- Always show what's happening
- Make errors obvious and actionable
- Celebrate successes

### Reliability
- Respect timeouts
- Handle failures gracefully
- Retry flaky operations

### Beauty
- Colorful, modern output
- Clean visual hierarchy
- Satisfying progress indicators

---

## 📝 Configuration Examples

### Minimal cortex.yml
```yaml
version: "1.0"
docker:
  compose_file: "docker-compose.yml"
  primary_service: "app"
setup:
  initialize:
    - command: "composer install"
      description: "Install dependencies"
```

### Full-Featured cortex.yml
```yaml
version: "1.0"

docker:
  compose_file: "docker-compose.yml"
  primary_service: "app"
  wait_for:
    - service: "db"
      timeout: 60
    - service: "redis"
      timeout: 30

setup:
  pre_start:
    - command: "[ ! -f .env ] && cp .env.example .env || true"
      description: "Create environment file"
      ignore_failure: true
      
  initialize:
    - command: "composer install --no-interaction"
      description: "Install PHP dependencies"
      retry: 2
      timeout: 300
      
    - command: "php artisan migrate:fresh --seed --force"
      description: "Setup database"
      ignore_failure: false

commands:
  test:
    command: "php artisan test"
    description: "Run test suite"
    
  shell:
    command: "bash"
    description: "Open shell in container"
```

---

## 🎬 Example Usage

```bash
# Initial setup
$ cortex up
┌─────────────────────────────────────┐
│  🚀 Starting Development Environment │
└─────────────────────────────────────┘
📦 Pre-start commands...
🐳 Starting Docker services...
⏳ Waiting for services...
🔧 Initialize commands...
✅ Environment ready!

# Run tests
$ cortex test
🧪 Running test suite...
[test output]
✅ Tests passed!

# Check status
$ cortex status
📊 Service Status
┌─────────┬──────────┬────────┐
│ Service │ Status   │ Health │
├─────────┼──────────┼────────┤
│ app     │ running  │ healthy│
│ db      │ running  │ healthy│
│ redis   │ running  │ healthy│
└─────────┴──────────┴────────┘

# Tear down
$ cortex down
🛑 Stopping services...
✅ Environment stopped
```

---

## 🔮 Future Enhancements (Post-MVP)

### Phase 6+
1. **Hooks System**
   - `before_up`, `after_up`, `before_down`, `after_down`
   - Project-specific shell scripts

2. **Environment Variable Interpolation**
   - Support `${VAR}` syntax in commands
   - Load from .env files

3. **Verbosity Levels**
   - `-q` (quiet): minimal output
   - Default: current behavior
   - `-v` (verbose): show docker logs
   - `-vv`: debug mode with full traces

4. **Service Groups**
   - Start subset of services: `cortex up --only=api`

5. **Config Profiles**
   - Multiple environments: `cortex up --profile=minimal`

6. **Watch Mode**
   - `cortex watch` - Re-run commands on file changes

7. **Plugins**
   - Allow extending with custom PHP classes
   - Plugin discovery mechanism

---

## ✅ Definition of Done

A feature is complete when:
1. ✅ Code implemented with proper types (PHP 8.2+ features)
2. ✅ Unit tests written and passing
3. ✅ Integration tests written (where applicable)
4. ✅ PHPStan level 8 passes
5. ✅ Code formatted with PHP-CS-Fixer
6. ✅ Error handling implemented
7. ✅ Output is colorful and user-friendly
8. ✅ Manual testing completed
9. ✅ Works in compiled PHAR

---

## 🎓 Development Guidelines

### Code Style
- PSR-12 coding standard
- PHP 8.2+ features: readonly classes, typed properties
- Strict types: `declare(strict_types=1);`
- Constructor property promotion
- Named arguments where it improves clarity

### Dependency Injection
- All services injected via constructor
- Use Symfony DI container
- Configure in `config/services.yaml`
- Autowiring where possible

### Error Handling
- Throw specific exceptions
- Catch at appropriate layers
- Always provide context in exceptions
- Log errors appropriately

### Testing
- Test behavior, not implementation
- Mock external dependencies (Docker, filesystem)
- Use descriptive test names: `test_it_retries_command_when_configured`
- Arrange-Act-Assert pattern

---

## 📚 Documentation Requirements

### README.md
- Installation instructions
- Quick start guide
- cortex.yml specification
- Command reference
- Examples
- Building PHAR instructions

### Inline Documentation
- PHPDoc for all public methods
- Class-level documentation explaining purpose
- Complex logic gets explanatory comments

---

## 🏁 Success Criteria

The MVP is successful when:
1. ✅ Can parse complex cortex.yml files
2. ✅ Executes pre-start commands on host
3. ✅ Starts Docker Compose services
4. ✅ Waits for service health properly
5. ✅ Executes initialize commands in container
6. ✅ Runs custom commands from config
7. ✅ Tears down environment cleanly
8. ✅ Shows beautiful, colorful output
9. ✅ Handles errors gracefully
10. ✅ Compiles to working PHAR
11. ✅ Has 80%+ test coverage

---

## 🎉 Ready to Build!

This plan provides a clear roadmap for building Cortex CLI with:
- **Modular architecture** that scales
- **Clear responsibilities** for each component
- **User-friendly output** that's a joy to use
- **Robust error handling** for reliability
- **Comprehensive testing** for confidence
- **Modern PHP practices** for maintainability

Let's build something awesome! 🚀

