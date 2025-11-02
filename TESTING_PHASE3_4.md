# Testing Phase 3 & 4 Implementation

## Quick Test

The fastest way to test everything:

```bash
cd /home/rob/projects/cortex-cli/tests/fixtures
../../bin/cortex up
../../bin/cortex run --list
../../bin/cortex run hello
../../bin/cortex status
../../bin/cortex down
```

## Comprehensive Test Suite

Run the complete test script:

```bash
cd /home/rob/projects/cortex-cli
./test-complete.sh
```

This tests:
1. ✅ Command listing
2. ✅ cortex up (with streaming)
3. ✅ cortex status
4. ✅ cortex run --list
5. ✅ cortex run hello
6. ✅ cortex run test
7. ✅ cortex down
8. ✅ Final status check

## Individual Feature Tests

### 1. Test Real-Time Streaming

```bash
cd tests/fixtures
../../bin/cortex up
```

**Look for:**
- Output appears line-by-line (not all at once)
- PHP version output from initialize phase
- Real-time feedback

### 2. Test Custom Commands

```bash
# List commands
../../bin/cortex run --list

# Expected output:
▸ Available Commands

┌─────────┬──────────────────────┐
│ Command │ Description          │
├─────────┼──────────────────────┤
│ test    │ Run test suite       │
│ hello   │ Simple hello command │
│ info    │ Show PHP info        │
└─────────┴──────────────────────┘
```

### 3. Test Running Custom Commands

```bash
# Simple command
../../bin/cortex run hello

# Expected output:
▸ Running: hello
  Simple hello command
    Hello from Cortex CLI!

Command completed successfully (0.2s)
```

```bash
# Command with more output
../../bin/cortex run test

# Should see:
- PHP version
- "Running tests..."
- Execution time
```

### 4. Test SetupOrchestrator

```bash
../../bin/cortex up

# Verify:
- Pre-start commands execute
- Docker services start
- Health checks run
- Initialize commands execute
- Real-time output throughout
```

### 5. Test Error Handling

```bash
# Try running non-existent command
../../bin/cortex run nonexistent

# Expected:
✗ Command 'nonexistent' not found in cortex.yml
```

```bash
# Try running when services aren't started
../../bin/cortex down
../../bin/cortex run test

# Expected:
# Should show Docker error or start services first message
```

## What to Verify

### Real-Time Streaming ✓
- [ ] Output appears immediately, not all at once
- [ ] Long commands show progress
- [ ] Multi-line output is handled correctly

### SetupOrchestrator ✓
- [ ] All 4 phases execute in order
- [ ] Pre-start commands run on host
- [ ] Initialize commands run in container
- [ ] Health checks work correctly
- [ ] Error messages are clear

### CommandOrchestrator ✓
- [ ] Commands execute in container
- [ ] Output streams in real-time
- [ ] Execution time is shown
- [ ] Errors are handled gracefully

### RunCommand ✓
- [ ] `cortex run <name>` executes commands
- [ ] `cortex run --list` shows all commands
- [ ] Help message is displayed if no arguments
- [ ] Unknown commands show helpful error

### UpCommand Refactoring ✓
- [ ] Works exactly as before
- [ ] No breaking changes
- [ ] Code is much simpler
- [ ] Still shows all output correctly

## Expected Output Colors

All commands should use Gigabyte brand colors:

- **Purple** (#7D55C7) - Headers, completion messages
- **Teal** (#2ED9C3) - Section arrows (▸)
- **Smoke** (#D2DCE5) - Status messages
- **Red** - Errors only
- **Yellow** - Warnings only
- **Green** - Table indicators (running/healthy)

## Performance Check

Commands should execute quickly:

- `cortex run hello` - ~0.2s
- `cortex run test` - ~0.5s
- `cortex up` - depends on services (usually 5-20s)
- `cortex status` - ~0.1s
- `cortex down` - ~2-5s

## Common Issues

### Issue: Commands not found
**Cause:** Services not running
**Solution:** Run `cortex up` first

### Issue: No real-time output
**Cause:** Command finishes too quickly
**Solution:** Try `cortex run info` for longer output

### Issue: Permission errors
**Cause:** Docker permissions
**Solution:** Check Docker group membership

## Test Checklist

Before committing, verify:

- [ ] `cortex up` works with streaming
- [ ] `cortex down` stops services
- [ ] `cortex status` shows table
- [ ] `cortex run --list` shows commands
- [ ] `cortex run <cmd>` executes correctly
- [ ] All colors are correct
- [ ] Error messages are helpful
- [ ] No PHP errors or warnings
- [ ] UpCommand is simplified
- [ ] Documentation is updated

## Success Criteria

Phase 3 & 4 are working if:

1. ✅ Real-time output streams during command execution
2. ✅ SetupOrchestrator handles all setup phases
3. ✅ UpCommand delegates to SetupOrchestrator
4. ✅ Custom commands can be defined and executed
5. ✅ `cortex run --list` shows all commands
6. ✅ All existing functionality still works
7. ✅ Gigabyte colors are used throughout
8. ✅ Error handling is consistent

## Debug Mode

If something isn't working:

```bash
# Add verbose output
cortex up -vvv

# Check Docker directly
cd tests/fixtures
docker-compose ps
docker-compose logs

# Test PHP version
docker-compose exec app php -v
```

## Next Steps After Testing

If all tests pass:
1. Update plan.md to mark Phase 3 & 4 complete
2. Commit changes with descriptive message
3. Move on to Phase 5 (Polish & PHAR)

If tests fail:
1. Note which tests failed
2. Check error messages
3. Review relevant code
4. Re-test after fixes

