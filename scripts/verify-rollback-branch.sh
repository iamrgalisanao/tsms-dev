#!/usr/bin/env bash
set -euo pipefail

BASELINE_FILE="${BASELINE_FILE:-specs/001-100-tenant-resilience/rollback-baseline.txt}"
ROLLBACK_BRANCH="${ROLLBACK_BRANCH:-remove-webapp-forwarding}"
REMOTE_NAME="${REMOTE_NAME:-origin}"
RELEASE_REF="${RELEASE_REF:-HEAD}"
REMOTE_REF="refs/remotes/${REMOTE_NAME}/${ROLLBACK_BRANCH}"

fail() {
  echo "rollback guard failed: $*" >&2
  exit 1
}

[[ -f "$BASELINE_FILE" ]] || fail "missing baseline file: $BASELINE_FILE"

baseline="$(tr -d '[:space:]' < "$BASELINE_FILE")"
[[ "$baseline" =~ ^[0-9a-f]{40}$ ]] || fail "baseline file must contain exactly one 40-character commit SHA"

if git remote get-url "$REMOTE_NAME" >/dev/null 2>&1; then
  git fetch "$REMOTE_NAME" "$ROLLBACK_BRANCH:${REMOTE_REF}" >/dev/null 2>&1 \
    || fail "unable to fetch ${REMOTE_NAME}/${ROLLBACK_BRANCH}"
fi

current_tip="$(git rev-parse --verify "${REMOTE_REF}^{commit}" 2>/dev/null)" \
  || fail "unable to resolve ${REMOTE_NAME}/${ROLLBACK_BRANCH}; fetch full history before running"

[[ "$current_tip" == "$baseline" ]] \
  || fail "${REMOTE_NAME}/${ROLLBACK_BRANCH} moved: expected ${baseline}, got ${current_tip}"

git merge-base --is-ancestor "$baseline" "$RELEASE_REF" \
  || fail "baseline ${baseline} is not an ancestor of ${RELEASE_REF}"

echo "rollback guard passed: ${REMOTE_NAME}/${ROLLBACK_BRANCH} remains at ${baseline}"
