#!/bin/bash

# Verify Module 2 Implementation
# This script runs the PHPUnit test to verify Module 2 implementation

echo "TSMS Module 2 Verification"
echo "=========================="
echo

# Change to the project directory
cd "$(dirname "$0")"

# Create a logs directory if it doesn't exist
mkdir -p logs/tests

# Set log file path with timestamp
LOG_FILE="logs/tests/module2-verify-$(date +%Y%m%d-%H%M%S).log"

echo "📝 Logging test results to: $LOG_FILE"
echo

echo "🚀 Running PHPUnit test for Module 2..."
php artisan test --filter=Module2VerificationTest 2>&1 | tee -a "$LOG_FILE"

# Check test execution status
if [ ${PIPESTATUS[0]} -ne 0 ]; then
    echo "❌ Tests completed with errors. See log for details: $LOG_FILE"
else
    echo "✅ Tests completed successfully. Log saved to: $LOG_FILE"
    
    # Copy the test log to a more readable report file
    grep -A 20 "MODULE 2 VERIFICATION SUMMARY" "$LOG_FILE" > "logs/tests/module2-summary-$(date +%Y%m%d-%H%M%S).txt"
    echo "📊 Summary report saved to: logs/tests/module2-summary-$(date +%Y%m%d-%H%M%S).txt"
fi
