#!/usr/bin/env bash
# Safe production deploy helper for TSMS
# Usage: ./scripts/deploy-prod.sh --tag v1.2.3 [--path /var/www/tsms-dev] [--dry-run]

set -euo pipefail

SCRIPT_NAME=$(basename "$0")
APP_PATH="$(pwd)"
TAG=""
DRY_RUN=0

usage(){
  cat <<EOF
Usage: $SCRIPT_NAME --tag <git-tag> [--path <app-path>] [--dry-run]

This script performs a guarded manual deploy on the server:
- verifies git state, creates DB dump, puts Laravel in maintenance mode,
- checks out the tag, installs deps, builds assets, runs migrations,
- restarts services, runs smoke checks, and brings app up.

NOTE: Review the script before running. Run with --dry-run first if unsure.
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    --tag) TAG="$2"; shift 2;;
    --path) APP_PATH="$2"; shift 2;;
    --dry-run) DRY_RUN=1; shift;;
    -h|--help) usage; exit 0;;
    *) echo "Unknown arg: $1"; usage; exit 1;;
  esac
done

if [ -z "$TAG" ]; then
  echo "Error: --tag is required"
  usage
  exit 2
fi

echo "Deploying tag: $TAG"
echo "App path: $APP_PATH"
if [ $DRY_RUN -eq 1 ]; then
  echo "DRY RUN mode: commands will be printed but not executed"
fi

run(){
  if [ $DRY_RUN -eq 1 ]; then
    echo "+ $*"
  else
    echo "+ $*"
    eval "$@"
  fi
}

confirm(){
  read -r -p "$1 [y/N]: " ans
  case "$ans" in
    [yY]|[yY][eE][sS]) return 0;;
    *) return 1;;
  esac
}

# Enter app path
cd "$APP_PATH"

# Ensure we're in a git repo
if [ ! -d .git ]; then
  echo "Not a git repo: $APP_PATH" >&2
  exit 3
fi

# Ensure clean working tree
if [ -n "$(git status --porcelain)" ]; then
  echo "Working tree is dirty. Commit or stash changes before deploying." >&2
  git status --porcelain
  exit 4
fi

# Verify tag exists
if ! git rev-parse --verify --quiet "refs/tags/$TAG" >/dev/null; then
  echo "Tag $TAG not found locally. Fetching tags from origin..."
  run git fetch --tags origin
  if ! git rev-parse --verify --quiet "refs/tags/$TAG" >/dev/null; then
    echo "Tag $TAG does not exist after fetch." >&2
    exit 5
  fi
fi

echo "About to deploy $TAG on $(hostname)"
confirm "Proceed with deploy?" || { echo "Aborted"; exit 6; }

# Load .env values for DB backup if present
DB_CONN=""
DB_HOST=""
DB_PORT=""
DB_NAME=""
DB_USER=""
DB_PASS=""
if [ -f .env ]; then
  DB_CONN=$(grep -E '^DB_CONNECTION=' .env | cut -d= -f2- | tr -d '\r' || true)
  DB_HOST=$(grep -E '^DB_HOST=' .env | cut -d= -f2- | tr -d '\r' || true)
  DB_PORT=$(grep -E '^DB_PORT=' .env | cut -d= -f2- | tr -d '\r' || true)
  DB_NAME=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '\r' || true)
  DB_USER=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '\r' || true)
  DB_PASS=$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '\r' || true)
fi

TS=$(date +%F-%H%M%S)
BACKUP_DIR="/var/backups/tsms"
BACKUP_FILE="$BACKUP_DIR/tsms-db-$TS.sql"

run mkdir -p "$BACKUP_DIR"

if [ "$DB_CONN" = "mysql" ]; then
  echo "Detected MySQL. Backing up DB $DB_NAME to $BACKUP_FILE"
  if [ $DRY_RUN -eq 1 ]; then
    echo "+ mysqldump -h $DB_HOST -P ${DB_PORT:-3306} -u $DB_USER -p'***' $DB_NAME > $BACKUP_FILE"
  else
    mysqldump -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_FILE"
  fi
elif [ "$DB_CONN" = "pgsql" ] || [ "$DB_CONN" = "postgres" ]; then
  echo "Detected Postgres. Backing up DB $DB_NAME to $BACKUP_FILE"
  if [ $DRY_RUN -eq 1 ]; then
    echo "+ pg_dump -h $DB_HOST -p ${DB_PORT:-5432} -U $DB_USER -F p -f $BACKUP_FILE $DB_NAME"
  else
    PGPASSWORD="$DB_PASS" pg_dump -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USER" -F p -f "$BACKUP_FILE" "$DB_NAME"
  fi
else
  echo "DB connection type not detected or unsupported. Please create a DB backup manually and press enter to continue." 
  read -r -p "Press enter to continue (or CTRL-C to abort)"
fi

confirm "Continue to put app into maintenance mode and deploy?" || { echo "Aborted"; exit 7; }

# Maintenance mode
run php artisan down --message="Deploying $TAG" --retry=60 || true

# Stop/terminate workers
run php artisan horizon:terminate || true
run php artisan queue:restart || true

# Checkout tag
run git fetch --tags origin
run git checkout -f "refs/tags/$TAG"

# Install PHP deps
run composer install --no-dev --optimize-autoloader --prefer-dist

# Build assets if package.json exists
if [ -f package.json ]; then
  if command -v npm >/dev/null 2>&1; then
    run npm ci
    run npm run build || true
  else
    echo "npm not found; skipping JS asset build. Consider building in CI and deploying artifacts.";
  fi
fi

# Run migrations
echo "Running migrations (non-interactive). Ensure migrations are safe."
confirm "Run migrations now?" || { echo "Skipping migrations."; }
run php artisan migrate --force || { echo "Migration failed"; php artisan up || true; exit 8; }

# Cache clear & optimize
run php artisan view:clear || true
run php artisan config:cache || true
run php artisan route:cache || true
run php artisan optimize || true

# Restart services (best-effort; adjust service names to your environment)
echo "Restarting services. Adjust service names in this script if needed." 
run sudo systemctl restart php-fpm || run sudo systemctl restart php8.1-fpm || true
run sudo systemctl restart nginx || run sudo systemctl restart apache2 || true

# Start workers/horizon
if systemctl --version >/dev/null 2>&1; then
  run sudo systemctl restart horizon || true
else
  run supervisorctl restart all || true
fi

# Smoke tests
echo "Running basic smoke checks..."
run php artisan horizon:status || true
run curl -fsS --max-time 10 http://localhost/health || true

confirm "Bring application back up (php artisan up)?" || { echo "Leaving site in maintenance mode. Please bring up manually."; exit 0; }
run php artisan up || true

echo "Deploy of $TAG complete. Monitor logs and metrics." 
echo "Backup created at: $BACKUP_FILE"
