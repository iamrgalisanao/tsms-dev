#!/usr/bin/env bash
set -euo pipefail

# deploy_ingestion_wip.sh
# Safe, idempotent deployment helper for feature/ingestion-transaction-pk-fix
# Usage (on staging server):
#   BACKUP_OK=1 BRANCH=feature/ingestion-transaction-pk-fix TERMINAL_ID=1 ./scripts/deploy_ingestion_wip.sh
# To run migrations as part of this script set RUN_MIGRATIONS=1 (operator must be certain backups exist):
#   BACKUP_OK=1 RUN_MIGRATIONS=1 ./scripts/deploy_ingestion_wip.sh

BRANCH=${BRANCH:-feature/ingestion-transaction-pk-fix}
RUN_MIGRATIONS=${RUN_MIGRATIONS:-0}
BACKUP_OK=${BACKUP_OK:-0}
TERMINAL_ID=${TERMINAL_ID:-}

ROOT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT_DIR"

echo "Deploy helper running on $(hostname)"
echo "Target branch: $BRANCH"

if [ "$BACKUP_OK" != "1" ]; then
  echo "WARNING: BACKUP_OK!=1. Ensure a DB backup/snapshot has been taken before deploying."
  echo "Set BACKUP_OK=1 to acknowledge and continue."
  exit 1
fi

# Fetch and check out branch
git fetch origin --prune
if git show-ref --verify --quiet refs/heads/$BRANCH; then
  git checkout $BRANCH
  git reset --hard origin/$BRANCH
else
  git checkout -b $BRANCH origin/$BRANCH
fi

echo "Updating composer dependencies (no-dev)..."
composer install --no-dev --optimize-autoloader

# If JS assets exist, try to build
if [ -f package.json ]; then
  if command -v npm >/dev/null 2>&1; then
    echo "Installing JS dependencies and building assets (npm ci && npm run build)..."
    npm ci --silent
    npm run build --silent || echo "npm run build failed or no build script defined"
  else
    echo "npm not found, skipping JS asset build"
  fi
fi

# Optionally run migrations (disabled by default)
if [ "$RUN_MIGRATIONS" = "1" ]; then
  echo "Running migrations --force"
  php artisan migrate --force
else
  echo "Skipping migrations (RUN_MIGRATIONS != 1). Ensure migrations have been applied separately."
fi

# Clear and cache config/routes
php artisan view:clear || true
php artisan config:cache || true
php artisan route:cache || true

# Restart workers
if php artisan horizon:status >/dev/null 2>&1; then
  echo "Terminating Horizon so new workers will start"
  php artisan horizon:terminate
else
  echo "Restarting queue workers"
  php artisan queue:restart
fi

# Optional smoke test: requires TERMINAL_ID
if [ -n "$TERMINAL_ID" ]; then
  echo "Running smoke test using TERMINAL_ID=$TERMINAL_ID"
  TXID=$(php artisan tinker --execute="\$t = \App\\Models\\Transaction::factory()->create(['terminal_id'=>$TERMINAL_ID,'customer_code'=>'SMOKE-TEST','base_amount'=>1.23,'validation_status'=>'PENDING']); echo \$t->id;" 2>/dev/null)
  echo "Created test transaction TXID=$TXID"
  php artisan queue:work --once --tries=3 --timeout=60
  echo "Inspect transaction_validations and security_events after job run:" 
  echo "  php artisan tinker --execute=\"print_r(\DB::table('transaction_validations')->where('transaction_id', $TXID)->get()->toArray());\""
  echo "  php artisan tinker --execute=\"print_r(\DB::table('security_events')->whereRaw(\"JSON_EXTRACT(context, '$.transaction_id') = $TXID\")->get()->toArray());\""
else
  echo "No TERMINAL_ID provided. Skipping smoke test. To run smoke test set TERMINAL_ID env and re-run."
fi

echo "Deploy helper completed. Review outputs above for errors or follow-up actions."
