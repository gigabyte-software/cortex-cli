# Cortex CLI

[![Tests](https://github.com/gigabyte-software/cortex-cli/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/gigabyte-software/cortex-cli/actions/workflows/tests.yml)
[![Release](https://github.com/gigabyte-software/cortex-cli/actions/workflows/release.yml/badge.svg)](https://github.com/gigabyte-software/cortex-cli/actions/workflows/release.yml)
[![codecov](https://codecov.io/gh/gigabyte-software/cortex-cli/branch/main/graph/badge.svg)](https://codecov.io/gh/gigabyte-software/cortex-cli)
[![semantic-release: angular](https://img.shields.io/badge/semantic--release-angular-e10079?logo=semantic-release)](https://github.com/semantic-release/semantic-release)
[![Release](https://img.shields.io/github/v/release/gigabyte-software/cortex-cli)](https://github.com/gigabyte-software/cortex-cli/releases)
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

### `cortex init`

Initialize Cortex configuration and directory structure:

```bash
cortex init                # Full initialization
cortex init --skip-yaml    # Skip creating cortex.yml
cortex init --skip-claude  # Skip creating ~/.claude files
cortex init --force        # Overwrite existing files
```

This command creates:

**Project-level files:**
- `.cortex/` directory structure (tickets, specs, meetings)
- `.cortex/README.md` with documentation
- `cortex.yml` configuration file (unless `--skip-yaml`)

**User-level files (in `~/.claude/`):**
- `CLAUDE.md` - Instructions for Claude Code
- `rules/cortex.md` - Cortex workflow rules

The user-level files are automatically updated if templates change when re-running `cortex init`. Use `--skip-claude` if you maintain your own `~/.claude` files.

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
- `--avoid-conflicts` - Automatically avoid container name and port conflicts by generating a unique namespace and port offset
- `--no-host-mapping` - Do not expose container ports to the host (useful for running multiple instances)
- `--namespace <name>` - Use a custom container namespace prefix
- `--port-offset <n>` - Add offset to all exposed ports (e.g., `--port-offset 100` maps port 80 to 180)

**Running Multiple Instances:**

To run the same project multiple times (e.g., for different branches):

```bash
# Option 1: Auto-avoid conflicts (recommended)
cortex up --avoid-conflicts

# Option 2: Manual namespace and port offset
cortex up --namespace feature-x --port-offset 100

# Option 3: No host ports (access via Docker network only)
cortex up --no-host-mapping
```

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

### `cortex shell`

Open an interactive bash shell inside the primary service container:

```bash
cortex shell
```

This command:
1. Reads the `primary_service` from your `cortex.yml` configuration
2. Opens an interactive bash shell in that container
3. Allows you to run commands, debug, or explore the container environment

Perfect for:
- Debugging issues in the container
- Running ad-hoc commands
- Exploring the container's filesystem
- Interactive development

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

## n8n Workflow Management

Cortex CLI provides commands to manage n8n workflows, including exporting, importing, and normalizing credentials across different n8n instances.

### Prerequisites

All n8n commands require configuration in your `.env` file:

```env
CORTEX_N8N_HOST=http://localhost
CORTEX_N8N_PORT=5678
CORTEX_N8N_API_KEY=your-api-key-here
```

The commands will prompt you for any missing values on first run.

### `cortex n8n:export`

Export workflows from a running n8n instance to JSON files:

```bash
cortex n8n:export              # Export all workflows
cortex n8n:export --force      # Overwrite existing files
```

**What it does:**
1. Connects to the n8n instance specified in `.env`
2. Fetches all workflows via the n8n API
3. Saves each workflow as a JSON file in the directory specified by `n8n.workflows_dir` in `cortex.yml`

**Configuration:**

Add to your `cortex.yml`:

```yaml
n8n:
  workflows_dir: "./.n8n"  # Directory to save exported workflows
```

**Output:**
- Each workflow is saved as `{workflow-name}.json` in the workflows directory
- Files are skipped if they already exist (unless `--force` is used)

### `cortex n8n:import`

Import workflows from JSON files into a running n8n instance:

```bash
cortex n8n:import              # Import all workflows from workflows directory
cortex n8n:import --force     # Overwrite existing workflows with same name
```

**What it does:**
1. Reads all `.json` files from the workflows directory
2. For each workflow:
   - If a workflow with the same name exists: updates it (unless `--force` is needed)
   - If no workflow exists: creates a new one
3. Cleans workflow data to remove read-only fields before sending to API

**Configuration:**

Uses the same `n8n.workflows_dir` configuration as export.

**Output:**
- Shows progress for each workflow being imported
- Reports any errors encountered during import

### `cortex n8n:normalise`

Normalize workflow credentials by mapping them to credentials in a target n8n instance. This is essential when moving workflows between environments where credential names or IDs differ.

```bash
# Basic usage - validate and patch credentials
cortex n8n:normalise workflow.json

# Output to file
cortex n8n:normalise workflow.json --output patched-workflow.json

# Use credential mapping file
cortex n8n:normalise workflow.json --map credentials.map.json

# Dry run - see what would change without modifying
cortex n8n:normalise workflow.json --dry-run

# Validation only - don't patch, just check
cortex n8n:normalise workflow.json --no-patch

# JSON report for CI/CD
cortex n8n:normalise workflow.json --report json

# Non-strict mode - don't exit on errors
cortex n8n:normalise workflow.json --no-strict
```

**What it does:**

1. **Extracts credentials** from the workflow JSON file
   - Identifies all credential references in workflow nodes
   - Builds a set keyed by `type:name` (e.g., `postgres:prod-db`)
   - Tracks which nodes use each credential (for helpful error messages)

2. **Fetches credentials** from the target n8n instance
   - Lists all credentials from the target n8n via API
   - Builds a lookup map: `(type, name) → [credentials]`

3. **Validates** credentials
   - Checks if all required credentials exist in target
   - Detects duplicate credentials (same type:name on target)
   - Reports missing credentials with node context
   - Exits with error code if strict mode is enabled (default)

4. **Patches credential IDs** into the workflow JSON
   - Updates credential IDs in workflow nodes to match target instance
   - Preserves credential names (useful for diffing and human readability)
   - Uses mapped credential names if `--map` is provided

5. **Outputs** the patched workflow
   - Writes to file (if `--output` specified) or stdout
   - Provides human-friendly or JSON reports

**Output Behavior:**

- **When using `--output <file>`**: Report is displayed in the console, and the patched workflow JSON is written to the specified file
- **When outputting to stdout** (no `--output`): Report is suppressed, and only the clean patched workflow JSON is written to stdout (perfect for piping/redirection)
- **When using `--no-patch`**: Only the validation report is shown (no workflow JSON output)
- **When using `--dry-run`**: Report is shown, but no workflow JSON is written

**Examples:**

```bash
# Output clean JSON to stdout (report suppressed) - good for piping
cortex n8n:normalise workflow.json > patched.json

# Output to file with report in console
cortex n8n:normalise workflow.json --output patched.json

# Validation only - just see the report
cortex n8n:normalise workflow.json --no-patch
```

**Options:**

- `workflow` (required): Path to workflow JSON file
- `--map, -m <file>`: Path to credential mapping JSON file (see below)
- `--output, -o <file>`: Output file path (default: stdout, suppresses report)
- `--dry-run`: Show what would change without writing output
- `--no-patch`: Only validate, don't patch credentials (shows report only)
- `--report <format>`: Report format - `json` or `text` (default: `text`)
- `--no-strict`: Don't exit on missing/duplicate credentials (strict mode is on by default)

**Credential Mapping (`--map`)**

The `--map` option allows you to map credential names from your workflow to different credential names in the target n8n instance. This is essential when credential names differ between environments.

**Map File Format:**

The map file is a JSON object where:
- **Key**: Source credential key in format `type:name` (from workflow)
- **Value**: Target credential key in format `type:name` (in target n8n)

**Example `credentials.map.json`:**

```json
{
  "postgres:prod-db": "postgres:prod-db-v2",
  "stripeApi:billing": "stripeApi:stripe-prod",
  "httpBasicAuth:api-auth": "httpBasicAuth:api-auth-prod",
  "slackApi:notifications": "slackApi:slack-prod"
}
```

**How Mapping Works:**

1. **Name Resolution**: When a credential is found in the workflow, the command first checks if a mapping exists for that credential key
2. **Target Lookup**: If a mapping exists, it looks up the mapped credential name in the target n8n instance instead of the original name
3. **ID Patching**: The workflow nodes are updated with the credential ID from the mapped credential, while the original credential name is preserved in the workflow JSON

**Example Usage:**

**Scenario**: Your workflow references `postgres:prod-db`, but the target n8n instance has it named `postgres:prod-db-v2`.

**Without mapping:**
```bash
cortex n8n:normalise workflow.json
# ❌ Error: MISS postgres:prod-db used by: Read Customers, Upsert Customer
```

**With mapping:**
```bash
# credentials.map.json
{
  "postgres:prod-db": "postgres:prod-db-v2"
}

cortex n8n:normalise workflow.json --map credentials.map.json
# ✅ Success: Maps postgres:prod-db → postgres:prod-db-v2 → finds credential ID
# ✅ Patches workflow: Updates credential ID, keeps name as "prod-db"
```

**Example Output (Text Format):**

```
normalise: target http://localhost:5678
found 7 credential refs in workflow

OK   postgres:prod-db (id 12) used by: Read Customers, Upsert Customer
OK   slackApi:slack-notifications (id 44) used by: Notify Ops
MISS stripeApi:billing used by: Charge Card

error: 1 missing credential
hint: create credential "billing" of type "stripeApi" in target n8n, or provide --map
```

**Important Notes:**

- The workflow credential **name remains unchanged**; only the credential **ID** is updated
- The **original workflow file is never modified**; output goes to a new file or stdout
- Mapping is one-way: source → target
- If a mapped credential doesn't exist in the target, it will be reported as missing
- Mappings can help disambiguate duplicate credentials by explicitly specifying which target credential to use
- Strict mode (default) exits with error code if any credentials are missing or duplicated
- Use `--report json` for CI/CD integration and programmatic parsing
- When outputting to stdout (no `--output`), the report is suppressed to keep JSON clean for piping

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
- `timeout` (optional, default: 600): Timeout in seconds
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

### Release

See [RELEASE.md](RELEASE.md) for details on how releases work.

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

