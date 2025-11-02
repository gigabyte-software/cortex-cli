#!/bin/bash

set -e

echo "========================================="
echo "Testing Down and Status Commands"
echo "========================================="
echo ""

cd /home/rob/projects/cortex-cli/tests/fixtures

echo "1. Testing 'cortex list' - Check commands are registered"
echo ""
../../bin/cortex list
echo ""

echo "========================================="
echo "2. Starting environment with 'cortex up'"
echo "========================================="
echo ""
../../bin/cortex up
echo ""

echo "========================================="
echo "3. Testing 'cortex status' - Should show running services"
echo "========================================="
echo ""
../../bin/cortex status
echo ""

echo "========================================="
echo "4. Testing 'cortex down' - Stop services"
echo "========================================="
echo ""
../../bin/cortex down
echo ""

echo "========================================="
echo "5. Testing 'cortex status' - Should show no services"
echo "========================================="
echo ""
../../bin/cortex status
echo ""

echo "========================================="
echo "6. Testing 'cortex down --volumes' - Clean up"
echo "========================================="
echo ""
../../bin/cortex down --volumes 2>/dev/null || echo "Already stopped (expected)"
echo ""

echo "========================================="
echo "✓ All tests completed successfully!"
echo "========================================="

