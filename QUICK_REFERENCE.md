# Multi-Instance Quick Reference

## Command Options

```bash
cortex up [OPTIONS]

Options:
  --namespace <id>       Custom container namespace prefix
  --port-offset <num>    Port offset to add to all exposed ports
  --avoid-conflicts      Auto-generate namespace and port offset
  --no-wait             Skip health checks
  --skip-init           Skip initialize commands
```

## Common Use Cases

### Single Developer (Default)
```bash
cortex up
cortex down
```

### Multi-Agent Orchestrator (Simple)
```bash
cortex up --avoid-conflicts
```

### Multi-Agent Orchestrator (Controlled)
```bash
# Agent 1
cortex up --namespace agent-1 --port-offset 1000

# Agent 2
cortex up --namespace agent-2 --port-offset 2000
```

## Lock File

**Location:** `.cortex.lock`

**Created when:**
- Using `--avoid-conflicts`
- Using `--namespace`
- Using `--port-offset`

**Prevents:** Duplicate instances in same directory

**Cleaned up:** Automatically by `cortex down`

## Namespace Resolution

**Default:** Directory-based
```
/workspace/agent-1/project/ → cortex-agent-1-project
```

**Override:** Use `--namespace` option

## Port Offset

**Default:** 0 (no offset)

**Auto mode:** Scans 8000-9000 range

**Explicit:** Use `--port-offset <num>`

**Applied to:** ALL exposed ports in docker-compose.yml

## Example Workflow

```bash
# In directory: /workspace/agent-1/project/

# Start with auto-conflicts avoidance
$ cortex up --avoid-conflicts

# Check status
$ cortex status
Environment Status
Namespace: cortex-agent-1-project
Port offset: +8000

# Application accessible at http://localhost:8080

# Stop
$ cortex down
```

## For Orchestrators

### Reading Port Information

```bash
$ cat .cortex.lock | jq -r '.port_offset'
8000
```

### Setting via Environment

```bash
export CORTEX_NAMESPACE="agent-${TASK_ID}"
export CORTEX_PORT_OFFSET=$((1000 * AGENT_NUM))

cortex up --namespace "$CORTEX_NAMESPACE" --port-offset "$CORTEX_PORT_OFFSET"
```

## Troubleshooting

### "Already running" error
```bash
# Solution: Stop existing instance first
cortex down
cortex up
```

### Port conflicts
```bash
# Solution: Use auto mode or explicit offset
cortex up --avoid-conflicts
# or
cortex up --port-offset 5000
```

### Finding namespace
```bash
# Check lock file
cat .cortex.lock | jq -r '.namespace'

# Or use status command
cortex status
```

