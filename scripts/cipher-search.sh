#!/bin/bash
set -euo pipefail

# Simple convenience wrapper to run a search against the final Cipher agent.
# Usage: scripts/cipher-search.sh "query terms here"

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <search terms>" >&2
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CFG_FINAL="$ROOT_DIR/memAgent/cipher-final.yml"
CFG_LEGACY="$ROOT_DIR/memAgent/cipher.yml"

if [[ -f "$CFG_FINAL" ]]; then
  CFG="$CFG_FINAL"
elif [[ -f "$CFG_LEGACY" ]]; then
  CFG="$CFG_LEGACY"
else
  echo "No Cipher config found (cipher-final.yml or cipher.yml)" >&2
  exit 1
fi

if [[ -f "$ROOT_DIR/memAgent/.env" ]]; then
  # shellcheck disable=SC1091
  source "$ROOT_DIR/memAgent/.env"
fi

QUERY="$*"
echo "[cipher-search] query: $QUERY" >&2
exec cipher --agent "$CFG" --mode cli "search $QUERY"
