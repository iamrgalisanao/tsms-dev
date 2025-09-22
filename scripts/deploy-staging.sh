#!/usr/bin/env bash

# TSMS Staging Deployment Script
# Purpose: Automate a safe Laravel deployment on the staging server.
# Usage (on staging server):
#   export APP_DIR=/var/www/tsms-dev   # if not using current directory
#   bash scripts/deploy-staging.sh

set -euo pipefail

APP_DIR="${APP_DIR:-$(pwd)}"
cd "$APP_DIR"

echo "==> TSMS Staging Deploy starting in: $APP_DIR"

echo "==> PHP version"
php -v || true

echo "==> Clearing caches (pre)"
php artisan config:clear || true
php artisan cache:clear || true
php artisan route:clear || true
php artisan view:clear || true
php artisan event:clear || true

echo "==> Putting app in maintenance mode"
php artisan down || true

echo "==> Installing composer dependencies (no-dev)"
COMPOSER_ALLOW_SUPERUSER=1 composer install \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --optimize-autoloader

echo "==> Running database migrations (force)"
php artisan migrate --force

echo "==> Optimizing caches (config/route/view/event)"
php artisan config:cache
php artisan route:cache || true
php artisan view:cache || true
php artisan event:cache || true

echo "==> Restarting queues/Horizon if present"
php artisan queue:restart || true
php artisan horizon:terminate || true

echo "==> Bringing app up"
php artisan up

echo "==> Quick API route health check (/api/v1)"
php artisan route:list --path=api/v1 || true

if command -v curl >/dev/null 2>&1; then
  echo "==> Hitting health endpoint"
  curl -fsS http://127.0.0.1/api/v1/health || true
fi

echo "✅ TSMS staging deployment completed."
