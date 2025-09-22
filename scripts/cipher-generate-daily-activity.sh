#!/bin/bash
set -euo pipefail

# Generate a daily activity digest summarizing changed files, aggregated diff stats,
# commit messages in the last 24h, and current uncommitted working tree changes.
# Afterwards (unless skipped) trigger a cipher refresh to embed recent changes.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"
OUT_DIR="docs"
DATE_TAG=$(date '+%Y-%m-%d')
OUT_FILE="$OUT_DIR/DAILY_ACTIVITY_${DATE_TAG}.md"

# Primary timeframe spec (default last 24h). Allow override via SINCE_SPEC env.
SINCE_SPEC=${SINCE_SPEC:-"24 hours ago"}
YESTERDAY=$(date -v-1d '+%Y-%m-%d' 2>/dev/null || date -d 'yesterday' '+%Y-%m-%d')

mkdir -p "$OUT_DIR"

# Helper: aggregate insertions/deletions per file over last 24h
aggregate_diff_stats() {
	git log --since="$SINCE_SPEC" --pretty=tformat: --numstat 2>/dev/null \
		| awk 'NF==3 {adds[$3]+=$1; dels[$3]+=$2} END {for (f in adds) printf "%s\t%d\t%d\n", f, adds[f], dels[f]}' \
		| grep -E '\\.(md|MD|php|ts|tsx|js|jsx|yml|yaml)$' || true
}

echo "# Daily Activity Digest - $DATE_TAG" > "$OUT_FILE"
echo "Generated: $(date -u '+%Y-%m-%dT%H:%M:%SZ')" >> "$OUT_FILE"
echo "Window Since (human): $YESTERDAY" >> "$OUT_FILE"
echo "Window Spec Used: $SINCE_SPEC" >> "$OUT_FILE"
echo "" >> "$OUT_FILE"

echo "## Changed Files (24h)" >> "$OUT_FILE"
changed_24h=$(git log --since="$SINCE_SPEC" --name-only --pretty=format: | sort -u | grep -E '\\.(md|php|ts|js|yml|yaml)$' || true)
if [[ -z "$changed_24h" ]]; then
	echo "(None)" >> "$OUT_FILE"
else
	echo "$changed_24h" | sed 's/^/- /' >> "$OUT_FILE"
fi
echo "" >> "$OUT_FILE"

echo "## 24h Diff Stats (Aggregated Insertions/Deletions)" >> "$OUT_FILE"
stats=$(aggregate_diff_stats)
if [[ -z "$stats" ]]; then
	echo "(None)" >> "$OUT_FILE"
else
	total_add=0; total_del=0
	while IFS=$'\t' read -r file adds dels; do
		[[ -z "$file" ]] && continue
		echo "- $file +${adds} -${dels}" >> "$OUT_FILE"
		total_add=$((total_add + adds))
		total_del=$((total_del + dels))
	done <<< "$stats"
	echo "" >> "$OUT_FILE"
	echo "Totals: +${total_add} / -${total_del}" >> "$OUT_FILE"
fi
echo "" >> "$OUT_FILE"

echo "## Commits (24h window spec)" >> "$OUT_FILE"
commits=$(git log --since="$SINCE_SPEC" --pretty=format:'- %h %s (%an)' || true)
if [[ -z "$commits" ]]; then
	echo "(None)" >> "$OUT_FILE"
else
	echo "$commits" >> "$OUT_FILE"
fi
echo "" >> "$OUT_FILE"

# Derive file list directly from the commits we just listed (guaranteed visibility)
echo "## Files From Listed Commits" >> "$OUT_FILE"
commit_shas=$(git log --since="$SINCE_SPEC" --pretty=format:%H || true)
if [[ -z "$commit_shas" ]]; then
	echo "(None)" >> "$OUT_FILE"
else
	commit_files_tmp=$(mktemp)
	# Collect files; tolerate no matches without causing script exit.
	set +e
	while read -r sha; do
		git show --name-only --pretty=format: "$sha" 2>/dev/null \
			| grep -E '\\.(md|MD|php|ts|tsx|js|jsx|yml|yaml|json|sh)$'
	done <<< "$commit_shas" | grep -v '^$' | sort -u > "$commit_files_tmp"
	set -e
	if [[ ! -s "$commit_files_tmp" ]]; then
		echo "(None)" >> "$OUT_FILE"
	else
		while read -r f; do
			if grep -Fxq "$f" <<< "$changed_24h"; then
				echo "- $f" >> "$OUT_FILE"
			else
				echo "- $f (not in time-filtered Changed Files list)" >> "$OUT_FILE"
			fi
		done < "$commit_files_tmp"
	fi
	rm -f "$commit_files_tmp"
fi
echo "" >> "$OUT_FILE"

echo "## Uncommitted Working Tree Changes" >> "$OUT_FILE"
if git diff --quiet && git diff --cached --quiet; then
	echo "(Clean)" >> "$OUT_FILE"
else
	echo "Legend: M=Modified, A=Added, D=Deleted, R=Renamed, ??=Untracked" >> "$OUT_FILE"
	git status --porcelain | while read -r line; do
		code=${line:0:2}
		path=${line:3}
		# handle rename format "R100 from -> to"
		if [[ "$code" == R* && "$path" == *" -> "* ]]; then
			from=${path%% -> *}; to=${path##* -> }
			echo "- [$code] $from -> $to" >> "$OUT_FILE"
		else
			echo "- [$code] $path" >> "$OUT_FILE"
		fi
	done
fi
echo "" >> "$OUT_FILE"

echo "## Notes" >> "$OUT_FILE"
echo "Auto-generated. Use to seed memory ingestion (resides in docs/). Set SKIP_CIPHER_REFRESH=1 to skip auto refresh." >> "$OUT_FILE"

echo "[activity] Wrote $OUT_FILE"

if [[ "${SKIP_CIPHER_REFRESH:-0}" != "1" ]]; then
	if command -v cipher >/dev/null 2>&1; then
		echo "[activity] Triggering incremental cipher refresh..."
		bash scripts/cipher-refresh-changes.sh || echo "[activity] WARN: cipher refresh encountered an issue" >&2
	else
		echo "[activity] Cipher CLI not found; skipping refresh." >&2
	fi
else
	echo "[activity] SKIP_CIPHER_REFRESH=1 set; skipping cipher refresh." 
fi
