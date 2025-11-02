# Cortex CLI

A PHP-based CLI tool that orchestrates Docker-based development environments using a simple YAML configuration file.

## Features

- 🚀 Automated setup of Docker-based development environments
- 📝 Simple YAML configuration
- ⚡ Fast and reliable Docker Compose orchestration
- 🎨 Beautiful, colorful console output
- 🔧 Extensible command system

## Requirements

- PHP 8.2 or higher
- Docker and Docker Compose
- Composer (for development)

## Installation

### From Source

```bash
git clone <repository-url>
cd cortex-cli
composer install --no-dev
chmod +x bin/cortex

# Optionally, link to your PATH
sudo ln -s $(pwd)/bin/cortex /usr/local/bin/cortex
```

## Quick Start

Create a `cortex.yml` file in your project root:

```yaml
version: "1.0"

docker:
  compose_file: "docker-compose.yml"
  primary_service: "app"
  wait_for:
    - service: "db"
      timeout: 60

setup:
  pre_start:
    - command: "cp .env.example .env"
      description: "Create environment file"
      ignore_failure: true
      
  initialize:
    - command: "composer install"
      description: "Install PHP dependencies"
      timeout: 300

commands:
  test:
    command: "php artisan test"
    description: "Run test suite"
```

Then run:

```bash
cortex up
```

## Commands

### `cortex up`

Start your development environment:

```bash
cortex up
```

This will:
1. Run pre-start commands on host
2. Start Docker Compose services
3. Wait for services to be healthy
4. Run initialize commands in container

Options:
- `--no-wait` - Skip health checks
- `--skip-init` - Skip initialize commands

### `cortex down`

Stop your development environment:

```bash
cortex down           # Stop services, keep volumes
cortex down --volumes # Stop services and remove volumes
```

### `cortex status`

Check the health status of services:

```bash
cortex status
```

Shows a table with:
- Service names
- Running status (running/exited)
- Health status (healthy/unhealthy/starting)

### `cortex <custom>`

Run custom commands defined in your `cortex.yml`:

```bash
cortex test
```

(Coming in Phase 4)

## Configuration

### Basic Structure

```yaml
version: "1.0"  # Required

docker:
  compose_file: "docker-compose.yml"  # Required: Path to docker-compose file
  primary_service: "app"              # Required: Main service to run commands in
  wait_for:                           # Optional: Services to wait for
    - service: "db"
      timeout: 60

setup:
  pre_start:    # Optional: Commands to run on host before docker-compose up
    - command: "cp .env.example .env"
      description: "Create environment file"
      ignore_failure: true
      
  initialize:   # Optional: Commands to run in container after services start
    - command: "composer install"
      description: "Install dependencies"
      timeout: 300
      retry: 2

commands:       # Optional: Custom commands
  test:
    command: "php artisan test"
    description: "Run test suite"
```

### Command Properties

- `command` (required): The command to execute
- `description` (required): Human-readable description
- `timeout` (optional, default: 60): Timeout in seconds
- `retry` (optional, default: 0): Number of retry attempts
- `ignore_failure` (optional, default: false): Continue even if command fails

## Development Status

### Phase 1: Foundation ✅

- [x] Project structure
- [x] Config layer (YAML loading and validation)
- [x] Basic Docker layer (docker-compose wrapper)
- [x] Output formatter (colorful output)
- [x] Basic `up` command skeleton
- [x] Unit tests for Config layer

### Phase 2: Core Execution ✅

- [x] Executor layer (Host, Container executors)
- [x] Health checker
- [x] Container executor
- [x] Full `up` command implementation
- [x] Command execution in containers
- [x] Real-time command output

### Phase 3: Orchestration (In Progress)

- [x] `down` command
- [x] `status` command
- [ ] Real-time output streaming (next)
- [ ] Setup orchestrator (optional)

### Phase 4: Custom Commands (Next)

- [ ] Command orchestrator
- [ ] `run` command
- [ ] Command listing

### Phase 5: Polish & PHAR

- [ ] Progress indicators
- [ ] PHAR build
- [ ] Final documentation

## Testing

Run the test suite:

```bash
composer test
```

Run static analysis:

```bash
composer phpstan
```

Fix code style:

```bash
composer cs-fix
```

## License

See [LICENSE](LICENSE) file for details.

## Contributing

This project follows PSR-12 coding standards and uses PHP 8.2+ features including:
- Readonly classes
- Constructor property promotion
- Typed properties
- Named arguments

Please ensure all contributions include tests and pass static analysis.

