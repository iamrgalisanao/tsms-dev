#!/bin/bash

# Test Module 2 Components
# This script runs the PHP verification tests for Module 2

echo "TSMS Module 2 Test Runner"
echo "========================="
echo

# Change to the project directory
cd "$(dirname "$0")"

# Create the test directory if it doesn't exist
mkdir -p tests/Scripts

# Create a log directory if it doesn't exist
mkdir -p logs/tests

# Set log file path with timestamp
LOG_FILE="logs/tests/module2-test-$(date +%Y%m%d-%H%M%S).log"

echo "📝 Logging test results to: $LOG_FILE"
echo

# Run the test script and capture output to log file
php tests/Scripts/RunModule2Tests.php 2>&1 | tee -a "$LOG_FILE"

# Check if the test script execution had errors
if [ ${PIPESTATUS[0]} -ne 0 ]; then
    echo
    echo "❌ Tests completed with errors. Check the log file for details: $LOG_FILE"
    echo "   Common issues:"
    echo "   - Missing required classes/files"
    echo "   - PHP syntax errors"
    echo "   - Database connection issues"
    echo
else
    echo
    echo "✅ Tests completed successfully. Log saved to: $LOG_FILE"
fi

echo "For more detailed tests, run: php artisan test --filter=Transaction"
