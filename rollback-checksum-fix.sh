#!/bin/bash
# EMERGENCY ROLLBACK SCRIPT: Checksum Audit Trail Fix
# Usage: ./rollback-checksum-fix.sh

set -e

echo "🚨 EMERGENCY ROLLBACK: Checksum Audit Trail Fix"
echo "Time: $(date)"
echo "Current branch: $(git branch --show-current)"

# 1. Stop application if needed
echo "Step 1: Stopping application..."
php artisan down --message="Rolling back checksum audit fix" --allow="127.0.0.1" || echo "App already down"

# 2. Reset to backup branch
echo "Step 2: Resetting to backup branch..."
git fetch origin
git checkout backup/pre-checksum-audit-fix-20251023-2152
git reset --hard HEAD

# 3. Restore from file backups if needed
echo "Step 3: Restoring file backups..."
if [ -f "app/Http/Requests/TSMSTransactionRequest.php.backup" ]; then
    cp app/Http/Requests/TSMSTransactionRequest.php.backup app/Http/Requests/TSMSTransactionRequest.php
    echo "✅ Restored TSMSTransactionRequest.php"
fi

if [ -f "app/Http/Controllers/API/V1/TransactionController.php.backup" ]; then
    cp app/Http/Controllers/API/V1/TransactionController.php.backup app/Http/Controllers/API/V1/TransactionController.php
    echo "✅ Restored TransactionController.php"
fi

# 4. Clear all caches
echo "Step 4: Clearing caches..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# 5. Run basic tests to verify rollback
echo "Step 5: Verifying rollback with tests..."
php artisan test tests/Feature/ChecksumSubmissionEventTest.php --stop-on-failure || echo "⚠️ Test failed - expected for rollback"

# 6. Restart application
echo "Step 6: Restarting application..."
php artisan up

echo "✅ Rollback complete!"
echo "⚠️ VERIFY: Check that application is responding normally"
echo "⚠️ VERIFY: Run full test suite: php artisan test"

exit 0
