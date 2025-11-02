#!/bin/bash

echo "========================================"
echo "Testing Phase 2 Implementation"
echo "========================================"
echo ""

cd /home/rob/projects/cortex-cli/tests/fixtures

echo "1. Testing cortex up command..."
echo ""

../../bin/cortex up

EXIT_CODE=$?

echo ""
echo "========================================"
if [ $EXIT_CODE -eq 0 ]; then
    echo "✓ Test passed! Exit code: $EXIT_CODE"
else
    echo "✗ Test failed! Exit code: $EXIT_CODE"
fi
echo "========================================"
echo ""

echo "2. Checking if containers are running..."
docker-compose -f docker-compose.test.yml ps

echo ""
echo "3. Cleaning up..."
docker-compose -f docker-compose.test.yml down -v > /dev/null 2>&1

echo "✓ Cleanup complete"
echo ""

