#!/bin/bash
set -euo pipefail

# cipher-pre-dev.sh
# Purpose: One-touch pre-development routine to ensure Cipher + BMad-Method
# memory context are prepared before coding.
#
# Steps:
#  1. Environment + dependency validation
#  2. Select config (final > legacy)
#  3. Generate / update daily activity digest (if not already for today)
#  4. Warm knowledge base (once per day) via curated searches
#  5. Refresh changed files vs origin/main (or main fallback)
#  6. BMad role grounding verification
#  7. Start MCP server (if not already running)
#  8. Summary report + next instructions

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

DATE_TAG=$(date '+%Y-%m-%d')
STATE_DIR="memAgent/.state"
mkdir -p "$STATE_DIR"
WARM_SENTINEL="$STATE_DIR/warm-${DATE_TAG}.flag"

CFG_FINAL="memAgent/cipher-final.yml"
CFG_LEGACY="memAgent/cipher.yml"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; CYAN='\033[0;36m'; NC='\033[0m'
info(){ echo -e "${GREEN}[INFO]${NC} $*"; }
warn(){ echo -e "${YELLOW}[WARN]${NC} $*"; }
err(){ echo -e "${RED}[ERROR]${NC} $*"; }
step(){ echo -e "${CYAN}▶${NC} $*"; }

fail(){ err "$1"; exit 1; }

step "Environment validation"
command -v cipher >/dev/null 2>&1 || fail "Cipher CLI not found (npm install -g @byterover/cipher)"
command -v git >/dev/null 2>&1 || fail "git not found"

if [[ -f memAgent/.env ]]; then
  # shellcheck disable=SC1091
  source memAgent/.env
else
  fail "memAgent/.env not found (copy from .env.example)"
fi

[[ -n "${OPENAI_API_KEY:-}" ]] || fail "OPENAI_API_KEY missing in memAgent/.env"
info "API key loaded (length ${#OPENAI_API_KEY})"

if [[ -f "$CFG_FINAL" ]]; then CFG="$CFG_FINAL"; elif [[ -f "$CFG_LEGACY" ]]; then CFG="$CFG_LEGACY"; else fail "No cipher config found"; fi
info "Using config: $CFG"

step "Daily activity digest"
DIGEST="docs/DAILY_ACTIVITY_${DATE_TAG}.md"
if [[ -f "$DIGEST" ]]; then
  info "Digest already exists for today: $(basename "$DIGEST")"
else
  bash scripts/cipher-generate-daily-activity.sh || warn "Digest generation returned non-zero"
fi

step "Warm knowledge base (once per day)"
if [[ -f "$WARM_SENTINEL" ]]; then
  info "Warm already performed today (remove $WARM_SENTINEL to force)"
else
  bash scripts/cipher-warm-all.sh || warn "Warm script reported issues"
  touch "$WARM_SENTINEL"
  info "Warm sentinel created: $WARM_SENTINEL"
fi

step "Refresh changed files vs origin/main"
bash scripts/cipher-refresh-changes.sh origin/main || warn "Refresh changes step had warnings"

step "Verify BMad role grounding"
BMAD_CHECK=$(cipher --agent "$CFG" --mode cli "List BMad roles (just the role names, comma separated)" 2>/dev/null || true)
if echo "$BMAD_CHECK" | grep -Eqi 'Orchestrator|Architect|Analyst'; then
  info "BMad roles detected: $(echo "$BMAD_CHECK" | tr '\n' ' ' | sed 's/.*://')"
else
  warn "Roles not clearly detected yet; run an explicit search: cipher --agent $CFG --mode cli 'search bmad roles orchestrator architect analyst'"
fi

step "Start MCP server if not running"
PID_FILE="memAgent/cipher_mcp.pid"
if [[ -f "$PID_FILE" ]] && ps -p "$(cat "$PID_FILE")" >/dev/null 2>&1; then
  info "MCP already running (PID $(cat "$PID_FILE"))"
else
  cipher --agent "$CFG" --mode mcp --port 3333 >/dev/null 2>&1 &
  MCP_PID=$!
  echo $MCP_PID > "$PID_FILE"
  info "Started MCP (PID $MCP_PID)"
fi

step "Summary"
echo "Config: $CFG"
echo "Digest: $(basename "$DIGEST") (present)"
[[ -f "$WARM_SENTINEL" ]] && echo "Warm: done today" || echo "Warm: not done"
echo "MCP PID: $(cat "$PID_FILE")"
echo "Next: Connect IDE or run: cipher --agent $CFG --mode cli 'search architecture overview'"

exit 0
