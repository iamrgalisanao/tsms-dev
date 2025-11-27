#!/usr/bin/env bash
set -euo pipefail

# Remote clean script for the TSMS repo
# Usage:
#   ./scripts/remote-clean.sh [--yes] [--branch BRANCH] [--no-vendor] [--no-node] [--no-deps]
# Defaults to a dry-run description unless --yes is provided.

DRY_RUN=true
FORCE=false
NO_VENDOR=false
NO_NODE=false
NO_DEPS=false
BRANCH=""

usage() {
  cat <<'USAGE'
Usage: remote-clean.sh [options]

Options:
  --yes             Execute actions (by default the script runs in dry-run)
  --branch BRANCH   Target branch to reset to (defaults to current branch)
  --no-vendor       Do not remove/install composer vendor dir
  --no-node         Do not remove/install node_modules
  --no-deps         Skip dependency installs entirely (implies --no-vendor --no-node)
  --force           Proceed even if there are uncommitted changes (use with care)
  -h, --help        Show this help

Examples:
  # Dry-run (default)
  ./scripts/remote-clean.sh

  # Execute and reinstall PHP deps, keep node_modules
  ./scripts/remote-clean.sh --yes --no-node

  # Force reset to origin/main and clean everything
  ./scripts/remote-clean.sh --yes --branch main --force
USAGE
}

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --yes)
        DRY_RUN=false
        shift
        ;;
      --force)
        FORCE=true
        shift
        ;;
      --no-vendor)
        NO_VENDOR=true
        shift
        ;;
      --no-node)
        NO_NODE=true
        shift
        ;;
      --no-deps)
        NO_DEPS=true
        NO_VENDOR=true
        NO_NODE=true
        shift
        ;;
      --branch)
        BRANCH="$2"
        shift 2
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      *)
        echo "Unknown arg: $1" >&2
        usage
        exit 1
        ;;
    esac
  done
}

confirm() {
  if [ "$DRY_RUN" = true ]; then
    echo "DRY-RUN: $*"
  else
    echo "$*"
  fi
}

main() {
  parse_args "$@"

  # Ensure we're inside a git repo
  if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "ERROR: Not inside a git repository." >&2
    exit 1
  fi

  REPO_ROOT=$(git rev-parse --show-toplevel)
  cd "$REPO_ROOT"

  CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
  TARGET_BRANCH=${BRANCH:-$CURRENT_BRANCH}

  echo "Repository: $REPO_ROOT"
  echo "Current branch: $CURRENT_BRANCH"
  echo "Target branch: $TARGET_BRANCH"

  echo
  echo "Checking working tree status..."
  GIT_STATUS=$(git status --porcelain)
  if [ -n "$GIT_STATUS" ] && [ "$FORCE" = false ]; then
    echo "Uncommitted changes detected:" >&2
    git status --short
    echo
    echo "Aborting. Run with --force to proceed anyway, or commit/stash your changes." >&2
    exit 2
  fi

  # Show planned actions
  echo
  echo "Planned actions:" 
  echo " - git fetch origin"
  echo " - git reset --hard origin/$TARGET_BRANCH"
  echo " - git clean -fd (remove untracked files/directories)"
  if [ "$NO_DEPS" = false ]; then
    echo " - remove vendor and reinstall composer dependencies (unless --no-vendor)"
    echo " - remove node_modules and run npm ci (unless --no-node)"
  else
    echo " - skip dependency installs (--no-deps)"
  fi
  echo " - php artisan config:clear && route:clear && view:clear && cache:clear"

  if [ "$DRY_RUN" = true ]; then
    echo
    echo "DRY RUN: no changes will be made. Rerun with --yes to execute." 
    exit 0
  fi

  echo
  echo "Executing clean steps..."

  echo "> git fetch origin"
  git fetch origin --prune

  echo "> git reset --hard origin/$TARGET_BRANCH"
  git reset --hard "origin/$TARGET_BRANCH"

  echo "> git clean -fd"
  git clean -fd

  if [ "$NO_DEPS" = false ]; then
    if [ "$NO_VENDOR" = false ]; then
      echo "> remove vendor/ and run composer install"
      rm -rf vendor
      if command -v composer >/dev/null 2>&1; then
        COMPOSER_MEMORY_LIMIT=${COMPOSER_MEMORY_LIMIT:--1}
        COMPOSER_MEMORY_LIMIT=$COMPOSER_MEMORY_LIMIT composer install --no-interaction --prefer-dist --optimize-autoloader
      else
        echo "composer not found in PATH, skipping composer install" >&2
      fi
    else
      echo "> skipping vendor cleanup (--no-vendor)"
    fi

    if [ "$NO_NODE" = false ]; then
      if [ -f package.json ]; then
        echo "> remove node_modules/ and run npm ci"
        rm -rf node_modules
        if command -v npm >/dev/null 2>&1; then
          npm ci --no-audit --no-fund
        else
          echo "npm not found in PATH, skipping npm ci" >&2
        fi
      else
        echo "> no package.json present, skipping node cleanup"
      fi
    else
      echo "> skipping node cleanup (--no-node)"
    fi
  else
    echo "> dependency install skipped (--no-deps)"
  fi

  # Clear Laravel caches
  if command -v php >/dev/null 2>&1 && [ -f artisan ]; then
    echo "> php artisan config:clear"
    php artisan config:clear || true
    echo "> php artisan route:clear"
    php artisan route:clear || true
    echo "> php artisan view:clear"
    php artisan view:clear || true
    echo "> php artisan cache:clear"
    php artisan cache:clear || true
  else
    echo "php or artisan not found; skipping laravel cache clears" >&2
  fi

  echo
  echo "Clean complete. Current HEAD: $(git rev-parse --abbrev-ref HEAD)@$(git rev-parse --short HEAD)"
}

main "$@"
