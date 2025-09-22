#!/bin/bash
set -euo pipefail

# Incremental change refresher: triggers embedding for recently changed files vs origin/main (or fallback to main).

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

BASE_REF=${1:-origin/main}
CFG_FINAL="memAgent/cipher-final.yml"
CFG_LEGACY="memAgent/cipher.yml"
[[ -f "$CFG_FINAL" ]] && CFG="$CFG_FINAL" || CFG="$CFG_LEGACY"

if [[ -f "memAgent/.env" ]]; then source memAgent/.env; fi

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "Not a git repo" >&2; exit 1
fi

if ! git show-ref --quiet $BASE_REF; then
  echo "Base ref $BASE_REF not found; using main" >&2
  BASE_REF=main
fi

changed=$(git diff --name-only "$BASE_REF"...HEAD | grep -E '\\.(md|MD|php|ts|tsx|js|jsx|yml|yaml)$' || true)
if [[ -z "$changed" ]]; then
  echo "[refresh] No matching changed files vs $BASE_REF"; exit 0
fi

echo "[refresh] Base ref: $BASE_REF"
echo "[refresh] Using config: $CFG"
echo "[refresh] Changed files:" $changed

for f in $changed; do
  if [[ ! -f "$f" ]]; then continue; fi
  # extract a few distinctive tokens from beginning & middle
  sample=$( (head -n 40 "$f"; tail -n 40 "$f") | sed 's/[^A-Za-z0-9 ]/ /g' | tr ' ' '\n' | grep -E '.{4,}' | head -n 25 | tr '\n' ' ' | cut -c1-400 )
  [[ -n "$sample" ]] || continue
  echo "[refresh] search(file=$f)"
  cipher --agent "$CFG" --mode cli "search $sample" >/dev/null 2>&1 || echo "[refresh] WARN search failed for $f" >&2
done

echo "[refresh] Complete."
