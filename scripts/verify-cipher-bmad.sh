#!/bin/bash
set -euo pipefail

# verify-cipher-bmad.sh
# Purpose: End-to-end health & integration check that
#  1. Cipher CLI is installed & runnable
#  2. Final agent config loads
#  3. OpenAI key is present
#  4. MCP/API ports (if running) are listening
#  5. BMad-Method bundle content is actually influencing responses
#  6. Basic memory search succeeds
#
# Exit codes:
#  0 success
#  1 missing dependency / fatal issue
#  2 partial success (core works but BMad context not confirmed)

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CONFIG_FINAL="$ROOT_DIR/memAgent/cipher-final.yml"
CONFIG_LEGACY="$ROOT_DIR/memAgent/cipher.yml"
BUNDLE_FILE="$ROOT_DIR/web-bundles/teams/team-fullstack.txt"
ENV_FILE="$ROOT_DIR/memAgent/.env"
TMP_OUT="$(mktemp -t cipher_bmad_check_XXXX.txt)"
MODE_QUERY_TIMEOUT=25

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; CYAN='\033[0;36m'; NC='\033[0m'

log() { local level="$1"; shift; local msg="$*"; case "$level" in
  INFO) echo -e "${GREEN}[INFO]${NC} $msg";;
  WARN) echo -e "${YELLOW}[WARN]${NC} $msg";;
  ERROR) echo -e "${RED}[ERROR]${NC} $msg";;
  STEP) echo -e "${CYAN}▶${NC} $msg";;
esac }

fail() { log ERROR "$*"; rm -f "$TMP_OUT"; exit 1; }

log STEP "Cipher + BMad integration verification starting"

# 1. Dependencies
command -v cipher >/dev/null 2>&1 || fail "Cipher CLI not found in PATH"
log INFO "Cipher CLI version: $(cipher --version 2>/dev/null || echo unknown)"

# 2. Env
if [[ ! -f "$ENV_FILE" ]]; then
  fail "Missing env file: $ENV_FILE (copy from memAgent/.env.example)"
fi
set -a; source "$ENV_FILE"; set +a
[[ -n "${OPENAI_API_KEY:-}" ]] || fail "OPENAI_API_KEY not set in $ENV_FILE"
log INFO "API key length: ${#OPENAI_API_KEY} (masked)"

# 3. Config selection
AGENT_CFG="$CONFIG_FINAL"
if [[ ! -f "$AGENT_CFG" ]]; then
  if [[ -f "$CONFIG_LEGACY" ]]; then
    log WARN "Final config missing, falling back to legacy cipher.yml"
    AGENT_CFG="$CONFIG_LEGACY"
  else
    fail "No agent configuration file found (cipher-final.yml or cipher.yml)"
  fi
fi
log INFO "Using agent config: $AGENT_CFG"

# 4. Dry run: simple ping
log STEP "Running LLM availability ping"
if ! cipher --agent "$AGENT_CFG" --mode cli "Respond with: PING_OK" | tee "$TMP_OUT" | grep -q "PING_OK"; then
  fail "Ping prompt did not return expected token PING_OK"
fi
log INFO "LLM basic round-trip succeeded"

# 5. Memory search sanity (will trigger ingestion lazily)
log STEP "Performing sample memory search (POS architecture)"
if ! cipher --agent "$AGENT_CFG" --mode cli "search POS transaction pipeline" > "$TMP_OUT" 2>&1; then
  fail "Search command failed"
fi
grep -qi "transaction" "$TMP_OUT" && log INFO "Search returned content referencing 'transaction'" || log WARN "Search output did not clearly reference 'transaction' (check logs)"

# 6. BMad bundle presence
if [[ -f "$BUNDLE_FILE" ]]; then
  log STEP "Validating BMad-Method role knowledge"
  # Force ingestion by searching for distinctive role tokens (lazy embedding trigger)
  for term in Orchestrator Architect Analyst "UX Expert" "Product Owner"; do
    cipher --agent "$AGENT_CFG" --mode cli "search $term" >/dev/null 2>&1 || true
  done
  # Ask model after forced searches
  if cipher --agent "$AGENT_CFG" --mode cli "List the BMad-Method roles you have available; respond with comma-separated tokens only" | tee "$TMP_OUT" | grep -Eqi "Orchestrator|Architect"; then
    log INFO "Roles enumeration includes expected tokens (Orchestrator/Architect)"
    BMAD_OK=1
  else
    log WARN "Did not detect expected BMad role tokens in output (content may not be chunked yet)"
    BMAD_OK=0
  fi
else
  log WARN "Bundle file missing: $BUNDLE_FILE (cannot confirm BMad context)"
  BMAD_OK=0
fi

# 7. MCP/API port visibility (optional)
for svc in mcp api ui; do
  PID_FILE="$ROOT_DIR/memAgent/cipher_${svc}.pid"
  if [[ -f "$PID_FILE" ]]; then
    pid=$(cat "$PID_FILE")
    svc_upcase=$(echo "$svc" | tr '[:lower:]' '[:upper:]')
    if ps -p "$pid" >/dev/null 2>&1; then
      if lsof -Pn -i :3333 2>/dev/null | grep -q "$pid"; then
        log INFO "$svc_upcase service running (PID $pid) listening on :3333"
      else
        log WARN "$svc_upcase PID $pid alive but port 3333 not detected (may use different port)"
      fi
    else
      log WARN "$svc_upcase PID file present but process not alive"
    fi
  fi
done

echo ""
if [[ ${BMAD_OK:-0} -eq 1 ]]; then
  log STEP "All core checks passed (Cipher + BMad integration healthy)"
  rc=0
else
  log STEP "Core Cipher checks passed, but BMad role validation inconclusive"
  rc=2
fi
rm -f "$TMP_OUT"
exit $rc
