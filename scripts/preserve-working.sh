#!/usr/bin/env bash
# Simple helper to snapshot current working tree into a WIP branch and push to origin.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_DIR"

if [ ! -d .git ]; then
  echo "Not a git repository (no .git). Exiting."
  exit 1
fi

BRANCH="wip-$(date -u +%Y%m%dT%H%M%SZ)"
echo "Creating WIP branch: $BRANCH"

# Create branch from current HEAD
git checkout -b "$BRANCH"

# Stage all changes
git add -A

if git diff --cached --quiet; then
  echo "No changes to commit. Branch $BRANCH created at current HEAD."
else
  git commit -m "chore(wip): snapshot work in progress $BRANCH"
  # Push branch if remote exists
  if git remote | wc -l | grep -q '[1-9]'; then
    echo "Pushing $BRANCH to origin..."
    git push -u origin "$BRANCH"
  else
    echo "No git remote configured; created local branch $BRANCH only."
  fi
fi

echo "WIP snapshot complete: $BRANCH"
