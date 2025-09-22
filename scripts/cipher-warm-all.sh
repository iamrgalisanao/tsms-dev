#!/bin/bash
set -euo pipefail

# Warm ingestion by issuing a broad curated set of semantic searches to force lazy embedding of key domains.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CFG_FINAL="$ROOT_DIR/memAgent/cipher-final.yml"
CFG_LEGACY="$ROOT_DIR/memAgent/cipher.yml"
if [[ -f "$CFG_FINAL" ]]; then CFG="$CFG_FINAL"; elif [[ -f "$CFG_LEGACY" ]]; then CFG="$CFG_LEGACY"; else echo "No cipher config found" >&2; exit 1; fi

if [[ -f "$ROOT_DIR/memAgent/.env" ]]; then source "$ROOT_DIR/memAgent/.env"; fi

queries=(
  "architecture overview"
  "transaction pipeline normalization"
  "POS provider integration flow"
  "idempotent void transaction safeguards"
  "security logging audit trail"
  "deployment checklist staging production"
  "bmad roles orchestrator architect analyst ux expert product owner"
  "test strategy feature tests void transaction"
  "notification system design"
  "circuit breaker design"
)

echo "[warm] Using config: $CFG"
for q in "${queries[@]}"; do
  echo "[warm] search: $q"
  cipher --agent "$CFG" --mode cli "search $q" >/dev/null 2>&1 || echo "[warm] WARN: search failed: $q" >&2
done
echo "[warm] Complete. Review logs for [VectorStore:Index] lines."
