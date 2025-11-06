# Cortex Init Command

## Overview

The `cortex init` command initializes a new Cortex project by creating the `.cortex/` directory structure and an example `cortex.yml` configuration file.

## Usage

```bash
# Initialize Cortex in current directory
cortex init

# Force overwrite existing files
cortex init --force

# Only create .cortex directory, skip cortex.yml
cortex init --skip-yaml
```

## What It Creates

When you run `cortex init`, it creates the following structure:

```
.
├── .cortex/
│   ├── README.md              # Full documentation about .cortex folder
│   ├── tickets/
│   │   └── .gitkeep           # Keeps directory in git
│   ├── specs/                 # Cucumber/Gherkin feature specifications
│   └── meetings/
│       └── index.json         # Meeting index (empty by default)
└── cortex.yml                 # Example configuration file
```

## Options

### `--force` / `-f`

Overwrite existing files if they already exist.

```bash
cortex init --force
```

Without this flag, the command will fail if `cortex.yml` or `.cortex/` already exists.

### `--skip-yaml`

Create only the `.cortex/` directory structure without generating `cortex.yml`.

```bash
cortex init --skip-yaml
```

Useful if you want to create a custom `cortex.yml` from scratch or already have one.

## Templates

The init command uses template files located in the `templates/` directory:

- `templates/cortex.yml.template` - Example Cortex configuration
- `templates/cortex-readme.md.template` - .cortex folder documentation
- `templates/meetings-index.json.template` - Empty meetings index

You can edit these templates to customize what gets generated for new projects.

### Template Location

- **Running from source**: Templates are in `/workspace/templates/`
- **Running as PHAR**: Templates are bundled inside the PHAR file

## The .cortex Folder

The `.cortex/` folder is the knowledge base for your project. It contains:

### `tickets/`

Per-ticket context and planning. Each Linear ticket gets its own subfolder:

```
tickets/
└── CORE-123/
    ├── README.md      # Human-readable overview
    ├── ticket.json    # Machine-readable Linear data
    ├── plan.md        # Implementation plan
    ├── specs.md       # Links to related feature specs
    └── assets/        # Screenshots, mockups, diagrams
```

### `specs/`

Cucumber/Gherkin feature specifications organized by product feature (not by ticket):

```
specs/
├── invoice-creation.feature
├── invoice-editing.feature
└── shared/
    └── authentication-steps.feature
```

### `meetings/`

Meeting notes organized chronologically:

```
meetings/
├── 2025-10/
│   ├── 15-daily-standup.md
│   └── 20-sprint-planning.md
└── index.json
```

## Generated cortex.yml

The generated `cortex.yml` includes:

- Docker Compose configuration
- Primary service definition
- Service wait configuration
- Pre-start commands
- Initialization commands
- Example custom commands

You should customize this file for your specific project.

## Example Workflow

```bash
# 1. Navigate to your project
cd ~/projects/my-app

# 2. Initialize Cortex
cortex init

# 3. Review and customize the generated files
vim cortex.yml
cat .cortex/README.md

# 4. Start your environment
cortex up
```

## Error Handling

### Already Initialized

If Cortex is already initialized (`.cortex/` or `cortex.yml` exists):

```
Error: Cortex is already initialized in this directory
Use --force to overwrite existing files
```

Solution: Use `--force` flag or manually remove existing files.

### Permission Denied

If you don't have write permissions:

```
Error: Initialization failed: Failed to create directory: .cortex
```

Solution: Ensure you have write permissions in the current directory.

### Template Not Found

If running from source and templates are missing:

```
Error: Template file not found: /path/to/templates/cortex.yml.template
```

Solution: Ensure the `templates/` directory exists in the project root.

## Integration with PHAR Build

The templates are automatically included in the PHAR build via `box.json`:

```json
{
  "directories": [
    "src",
    "templates"
  ]
}
```

This ensures the init command works both when running from source and as a compiled PHAR.

## Testing

Comprehensive unit tests are available in `tests/Unit/Command/InitCommandTest.php`:

```bash
# Run init command tests
./bin/cortex test -- --filter=InitCommandTest
```

Tests cover:
- Directory structure creation
- File generation from templates
- --force option
- --skip-yaml option
- Already initialized detection
- Error handling

## Future Enhancements

Potential improvements for future versions:

1. **Interactive Mode**: Ask questions to customize generated files
2. **Project Templates**: Laravel, Symfony, generic PHP templates
3. **Auto-detection**: Detect project type and customize accordingly
4. **Git Integration**: Optionally initialize git repository
5. **Custom Templates**: Allow users to specify custom template URLs

## See Also

- [.cortex/README.md](templates/cortex-readme.md.template) - Full .cortex folder documentation
- [cortex.example.yml](cortex.example.yml) - Example configuration
- [README.md](README.md) - Main Cortex CLI documentation
