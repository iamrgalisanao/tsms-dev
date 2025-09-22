#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [[ -f memAgent/.env ]]; then
  # shellcheck disable=SC2046
  set -a; source memAgent/.env; set +a
fi

if [[ -z "${OPENAI_API_KEY:-}" ]]; then
  echo "[ERROR] OPENAI_API_KEY not set. Export it or put it in memAgent/.env" >&2
  exit 1
fi

CONFIG="memAgent/cipher-final.yml"
MODE="${1:-cli}"
shift || true

echo "[INFO] Starting Cipher (mode=$MODE, config=$CONFIG)" >&2
exec cipher --agent "$CONFIG" --mode "$MODE" "$@"