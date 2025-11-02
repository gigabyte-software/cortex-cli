# Contributing to Cortex CLI

Thank you for your interest in contributing to Cortex CLI! 🎉

## Getting Started

### Prerequisites

You only need:
- Docker and Docker Compose
- Git

That's it! All PHP dependencies and tools are containerized.

### Setup Development Environment

1. **Clone the repository:**
   ```bash
   git clone https://github.com/gigabyte-software/cortex-cli.git
   cd cortex-cli
   ```

2. **Start the development environment:**
   ```bash
   ./bin/cortex up
   ```
   
   This will:
   - Build the Docker container with PHP 8.3 and all extensions
   - Install Composer dependencies
   - Set up Xdebug for code coverage

3. **Verify everything works:**
   ```bash
   ./bin/cortex validate
   ```

## Development Workflow

### Running Tests

```bash
# Run all tests
./bin/cortex test

# Run tests with HTML coverage report
./bin/cortex test-coverage
# Then open coverage-html/index.html in your browser
```

### Static Analysis

```bash
# Run PHPStan (level 8)
./bin/cortex phpstan
```

### Code Style

```bash
# Check code style
./bin/cortex cs-check

# Automatically fix code style
./bin/cortex cs-fix
```

### Building the PHAR

```bash
./bin/cortex build
```

### Working in the Container

```bash
# Open an interactive shell
./bin/cortex shell

# Run custom commands
./bin/cortex composer require vendor/package
./bin/cortex php bin/cortex list
```

### Stopping the Environment

```bash
./bin/cortex down
```

## Code Standards

This project follows:
- **PSR-12** coding standards
- **PHPStan Level 8** static analysis
- **PHP 8.2+** modern features (readonly classes, constructor promotion, typed properties)

## Before Submitting a PR

1. **Ensure all tests pass:**
   ```bash
   ./bin/cortex test
   ```

2. **Run static analysis:**
   ```bash
   ./bin/cortex phpstan
   ```

3. **Format your code:**
   ```bash
   ./bin/cortex cs-fix
   ```

4. **Or run everything at once:**
   ```bash
   ./bin/cortex validate
   ```

5. **Write tests** for new features

6. **Update documentation** if needed

## Project Structure

```
cortex-cli/
├── bin/cortex          # CLI entry point
├── src/                # Source code
│   ├── Command/        # Symfony Console commands
│   ├── Config/         # Configuration handling
│   ├── Docker/         # Docker operations
│   ├── Executor/       # Command execution
│   ├── Orchestrator/   # High-level orchestration
│   └── Output/         # Output formatting
├── tests/              # PHPUnit tests
│   ├── Unit/           # Unit tests
│   └── fixtures/       # Test fixtures
├── docker/             # Docker development environment
├── cortex.yml          # Cortex config (for self-development!)
└── docker-compose.yml  # Docker Compose setup
```

## Available Commands

Run `./bin/cortex list` to see all available development commands:
- `test` - Run PHPUnit tests
- `test-coverage` - Run tests with HTML coverage report
- `phpstan` - Run PHPStan static analysis
- `cs-fix` - Fix code style with PHP CS Fixer
- `cs-check` - Check code style without fixing
- `build` - Build cortex.phar with Box
- `shell` - Open interactive bash shell
- `composer` - Run composer commands
- `php` - Run PHP commands
- `validate` - Run all validation checks

## Continuous Integration

GitHub Actions automatically runs:
- Tests on PHP 8.2 and 8.3
- PHPStan static analysis
- Code coverage (uploaded to Codecov)

All checks must pass before a PR can be merged.

## Questions or Issues?

- Open an issue on GitHub
- Check the [dev-docs](dev-docs/) directory for additional documentation

## License

By contributing to Cortex CLI, you agree that your contributions will be licensed under the Apache License 2.0.

