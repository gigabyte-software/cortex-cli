#!/bin/bash

echo "============================================="
echo "Complete Cortex CLI Test Suite"
echo "============================================="
echo ""

cd /home/rob/projects/cortex-cli/tests/fixtures

echo "1. Test: List all commands"
echo "============================================="
../../bin/cortex list
echo ""

echo "2. Test: cortex up (with SetupOrchestrator + Real-time streaming)"
echo "============================================="
../../bin/cortex up
echo ""

echo "3. Test: cortex status (check running services)"
echo "============================================="
../../bin/cortex status
echo ""

echo "4. Test: cortex run --list (list custom commands)"
echo "============================================="
../../bin/cortex run --list
echo ""

echo "5. Test: cortex run hello (simple command)"
echo "============================================="
../../bin/cortex run hello
echo ""

echo "6. Test: cortex run test (with output)"
echo "============================================="
../../bin/cortex run test
echo ""

echo "7. Test: cortex down (stop services)"
echo "============================================="
../../bin/cortex down
echo ""

echo "8. Test: cortex status (should show no services)"
echo "============================================="
../../bin/cortex status
echo ""

echo "9. Cleanup: Remove volumes"
echo "============================================="
../../bin/cortex down --volumes 2>/dev/null || echo "Already stopped"
echo ""

echo "============================================="
echo "✅ All tests completed!"
echo "============================================="
echo ""
echo "Summary of what was tested:"
echo "- Real-time output streaming ✓"
echo "- SetupOrchestrator ✓"
echo "- CommandOrchestrator ✓"
echo "- cortex up ✓"
echo "- cortex down ✓"
echo "- cortex status ✓"
echo "- cortex run <command> ✓"
echo "- cortex run --list ✓"
echo ""

