#!/bin/bash

# Run Module 2 PHPUnit Test
# This script runs the PHPUnit test for Module 2 verification

echo "TSMS Module 2 PHPUnit Test Runner"
echo "================================="
echo

# Change to the project directory
cd "$(dirname "$0")"

# Create a logs directory if it doesn't exist
mkdir -p logs/tests

# Set log file path with timestamp
LOG_FILE="logs/tests/module2-phpunit-$(date +%Y%m%d-%H%M%S).log"

echo "📝 Logging test results to: $LOG_FILE"
echo

echo "🚀 Running PHPUnit test for Module 2..."
php artisan test --filter=Module2Test --verbose 2>&1 | tee -a "$LOG_FILE"

# Check test execution status
if [ ${PIPESTATUS[0]} -ne 0 ]; then
    echo "❌ Tests completed with errors. See log for details: $LOG_FILE"
else
    echo "✅ Tests completed successfully. Log saved to: $LOG_FILE"
fi

# Check if test report was generated
if [ -f storage/app/module2_test_report.md ]; then
    echo
    echo "📊 Test report generated: storage/app/module2_test_report.md"
    echo "Report content:"
    echo "---------------"
    cat storage/app/module2_test_report.md
    
    # Copy to logs directory for easier access
    cp storage/app/module2_test_report.md logs/tests/module2-report-$(date +%Y%m%d-%H%M%S).md
    echo
    echo "📝 Report copied to: logs/tests/module2-report-$(date +%Y%m%d-%H%M%S).md"
fi

echo
echo "For more detailed module testing, you can also run:"
echo "php artisan test --filter=Transaction"
echo "php artisan test --filter=Retry"
echo "php artisan test --filter=Integration"
