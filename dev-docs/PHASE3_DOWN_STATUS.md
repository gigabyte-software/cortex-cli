# Phase 3 (Partial) - Down & Status Commands

## ✅ What Was Implemented

Two new commands have been added to Cortex CLI:
1. **`cortex down`** - Stop Docker services
2. **`cortex status`** - Check service status

## 1. Down Command

### Features
- Stops all Docker Compose services
- Optional `--volumes` flag to remove volumes
- Uses same beautiful purple/teal color scheme
- Finds cortex.yml automatically

### Usage

```bash
# Stop services (keep volumes)
cortex down

# Stop services and remove volumes
cortex down --volumes
```

### Output Example

```
▸ Stopping environment
  Docker services stopped

Environment stopped successfully
```

### Implementation
**File:** `src/Command/DownCommand.php`

- Loads cortex.yml to get compose file path
- Calls `DockerCompose::down()` with optional volumes flag
- Shows purple success message
- Handles errors gracefully

## 2. Status Command

### Features
- Shows all running services in a table
- Color-coded status and health indicators
- Checks if services are running
- Helpful message if nothing is running

### Usage

```bash
cortex status
```

### Output Example

When services are running:
```
▸ Service Status

┌─────────┬──────────┬─────────┐
│ Service │ Status   │ Health  │
├─────────┼──────────┬─────────┤
│ app     │ running  │ healthy │
│ db      │ running  │ healthy │
└─────────┴──────────┴─────────┘
```

When nothing is running:
```
▸ Service Status
  No services are currently running
  Run "cortex up" to start the environment
```

### Color Coding

**Status:**
- 🟢 Green = running
- 🔴 Red = exited
- 🟡 Yellow = other states

**Health:**
- 🟢 Green = healthy, running
- 🔴 Red = unhealthy
- 🟡 Yellow = starting
- ⚪ Gray = unknown, no healthcheck

### Implementation
**File:** `src/Command/StatusCommand.php`

- Loads cortex.yml
- Calls `DockerCompose::ps()` to get service list
- Calls `HealthChecker::getHealthStatus()` for each service
- Uses Symfony Console Table component
- Color codes status based on state

## Files Modified

### New Files (2)
```
src/Command/DownCommand.php
src/Command/StatusCommand.php
```

### Modified Files (1)
```
src/Application.php - Registered new commands
```

## Testing

### Manual Test Workflow

```bash
# 1. Navigate to test directory
cd /home/rob/projects/cortex-cli/tests/fixtures

# 2. Check commands are registered
../../bin/cortex list

# You should see:
# - down
# - status
# - up

# 3. Start environment
../../bin/cortex up

# 4. Check status (should show running services)
../../bin/cortex status

# 5. Stop services
../../bin/cortex down

# 6. Check status again (should show no services)
../../bin/cortex status

# 7. Clean up with volumes
../../bin/cortex down --volumes
```

### Expected Behavior

| Command | When Services Running | When Services Stopped |
|---------|----------------------|----------------------|
| `cortex status` | Shows table with services | "No services running" |
| `cortex down` | Stops services | Shows error or "already stopped" |
| `cortex down -v` | Stops + removes volumes | Shows error or "already stopped" |

## Integration with Existing Commands

### Typical Workflow

```bash
# Start your dev environment
cortex up

# Check everything is running
cortex status

# Work on your project...

# Stop when done
cortex down

# Or stop and clean volumes
cortex down --volumes
```

## Command Help

### Down Command Help
```bash
cortex down --help

Description:
  Tear down the development environment

Usage:
  down [options]

Options:
  -v, --volumes         Remove volumes as well
  -h, --help            Display help
```

### Status Command Help
```bash
cortex status --help

Description:
  Check the health status of services

Usage:
  status
```

## Technical Details

### DownCommand
- **Dependencies:** ConfigLoader, DockerCompose
- **Options:** --volumes (-v)
- **Error Handling:** Catches ConfigException and generic exceptions
- **Output:** Purple success message on completion

### StatusCommand
- **Dependencies:** ConfigLoader, DockerCompose, HealthChecker
- **Options:** None
- **Error Handling:** Handles no config, no services gracefully
- **Output:** Symfony Console Table with color-coded cells

## Color Scheme

Both commands use the Gigabyte brand colors:
- **Purple (#7D55C7)** - Success messages
- **Teal (#2ED9C3)** - Section headers with ▸ arrow
- **Smoke (#D2DCE5)** - Status messages
- **Green/Red/Yellow** - Status indicators in table

## Edge Cases Handled

### DownCommand
- ✅ No cortex.yml found
- ✅ Services already stopped
- ✅ Invalid compose file path
- ✅ Docker not running

### StatusCommand
- ✅ No cortex.yml found
- ✅ No services running
- ✅ Services without healthchecks
- ✅ Docker not running
- ✅ Empty service list

## What's Next

These two commands complete the basic lifecycle:
- ✅ `cortex up` - Start environment
- ✅ `cortex status` - Check environment
- ✅ `cortex down` - Stop environment

**Not Yet Implemented (Future):**
- Real-time output streaming (separate commit)
- SetupOrchestrator refactoring (optional)
- Integration tests (optional)

## Success Criteria

Down & Status commands are working if:
- ✅ Both commands show in `cortex list`
- ✅ `cortex down` stops Docker services
- ✅ `cortex down --volumes` removes volumes
- ✅ `cortex status` shows running services in table
- ✅ `cortex status` shows helpful message when nothing running
- ✅ Colors match Gigabyte brand
- ✅ Error messages are clear and helpful

## Quick Start

Test the new commands right now:

```bash
cd /home/rob/projects/cortex-cli/tests/fixtures
../../bin/cortex up && ../../bin/cortex status && ../../bin/cortex down
```

This will:
1. Start services
2. Show status table
3. Stop services

All in one line!

