# Cortex CLI

[![Tests](https://github.com/gigabyte-software/cortex-cli/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/gigabyte-software/cortex-cli/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/gigabyte-software/cortex-cli/branch/main/graph/badge.svg)](https://codecov.io/gh/gigabyte-software/cortex-cli)
[![License](https://img.shields.io/badge/License-Apache_2.0-blue.svg)](https://opensource.org/licenses/Apache-2.0)

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

### Quick Install (Recommended)

```bash
# Download and install with one command
curl -fsSL https://github.com/gigabyte-software/cortex-cli/releases/latest/download/install.sh | bash
```

### Manual Install

```bash
# Download the PHAR
curl -L https://github.com/gigabyte-software/cortex-cli/releases/latest/download/cortex.phar -o cortex.phar

# Download and run installer
curl -L https://github.com/gigabyte-software/cortex-cli/releases/latest/download/install.sh -o install.sh
chmod +x install.sh
./install.sh
```

This will:
- Install cortex to `/usr/local/bin/cortex`
- Set up shell completion (bash/zsh)
- Make it available system-wide

### From Source

```bash
git clone https://github.com/gigabyte-software/cortex-cli.git
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

### `cortex update`

Update Cortex CLI to the latest version:

```bash
cortex update           # Update to latest version
cortex update --check   # Check for updates without installing
cortex update --force   # Force update even if already latest
```

This command:
1. Checks GitHub for the latest release
2. Compares with your current version
3. Downloads and installs the update if available
4. Creates a backup before updating

**Note**: Only works when running as PHAR. When running from source, use `git pull` instead.

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

### Custom Commands

Run custom commands directly by name:

```bash
# Run a custom command
cortex test

# List all available commands
cortex list
```

Define custom commands in your `cortex.yml`:

```yaml
commands:
  test:
    command: "php artisan test"
    description: "Run test suite"
  
  migrate:
    command: "php artisan migrate"
    description: "Run database migrations"
```

Your custom commands will appear alongside built-in commands and support tab completion!

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

## Tab Completion

Tab completion is automatically installed by the install script. To set it up manually, see [COMPLETION.md](COMPLETION.md).

## Development

### Docker Development Environment (Recommended)

Cortex CLI uses itself for development! Simply run:

```bash
# Start the development environment
./bin/cortex up

# Run tests
./bin/cortex test

# Run static analysis
./bin/cortex phpstan

# Fix code style
./bin/cortex cs-fix

# Build the PHAR
./bin/cortex build

# Run all validation checks
./bin/cortex validate

# Open a shell in the container
./bin/cortex shell
```

All PHP dependencies, extensions (including Xdebug for coverage), and tools are pre-configured in the Docker container.

### Local Development (Without Docker)

If you prefer to develop without Docker:

**Requirements:**
- PHP 8.2 or 8.3
- Composer
- PHP extensions: mbstring, xml, curl
- Xdebug (optional, for code coverage)

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run static analysis
composer phpstan

# Fix code style
composer cs-fix
```

### Building from Source

See [BUILD.md](BUILD.md) for detailed instructions on building the PHAR.

## Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details on our development workflow.

**Quick start for contributors:**
```bash
./bin/cortex up      # Start dev environment
./bin/cortex test    # Run tests
./bin/cortex validate # Run all checks
```

This project follows PSR-12 coding standards and uses PHP 8.2+ features. See [dev-docs](dev-docs/) for additional documentation.

## License

See [LICENSE](LICENSE) file for details.

