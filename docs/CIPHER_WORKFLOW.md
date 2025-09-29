# TSMS Cipher Memory Workflow

This document defines the end‑to‑end operational workflow for using the Cipher Memory Agent inside the TSMS project. It captures: configuration structure, startup modes, ingestion lifecycle, search patterns, troubleshooting, and safe extension practices.

---
## 1. Components Overview

| Component | File / Script | Purpose |
|-----------|---------------|---------|
| Final Config | `memAgent/cipher-final.yml` | Production / full knowledge memory agent configuration |
| Test Config | `memAgent/cipher-test.yml` | Minimal fast iteration (schema + key confirmation) |
| Runner Script | `scripts/run-cipher-final.sh` | Safe startup wrapper w/ env loading |
| VS Code Tasks | `.vscode/tasks.json` | One-click run (CLI / MCP / API / UI) |
| Env File | `memAgent/.env` | Stores `OPENAI_API_KEY` (never commit real secret) |

Cipher 0.2.2 requires a `providers` array and a `settings` object; the *final* config layers all memory / search features on top of that requirement.

---
## 2. Configuration Anatomy (Final)

`cipher-final.yml` high-level sections:

| Section | Key Fields | Notes |
|---------|-----------|-------|
| providers | static + file-based | Supplies system instructions & architecture context scaffolding |
| settings | maxGenerationTime, contentSeparator | Controls prompt aggregation & provider timing |
| llm / embeddings | provider, model, api_key_env, apiKey | Both must resolve an API key (env export) |
| vector_store | provider, path, collection | Local SQLite/dual manager (currently in-memory fallback for dual mode) |
| memory | chunk_size, overlap_tokens, similarity_threshold | Core retrieval tuning |
| data_sources | file / directory specs | Declarative ingestion targets (lazy-loaded on demand) |
| logging | level, file, console | Elevate to `debug` during diagnosis |
| search | boost_recent, context_window | Retrieval shaping heuristics |
| context_tags | Domain hints (not strict filters) |
| mcpServers | {} | Empty; extend for external MCP endpoints |

### Minimal Test Config (`cipher-test.yml`)
Used to verify startup and API key path. Excludes data ingestion complexity.

---
## 3. Startup Modes

| Mode | Command | Use Case |
|------|---------|----------|
| CLI | `./scripts/run-cipher-final.sh cli` | Interactive Q&A and manual search |
| MCP | `./scripts/run-cipher-final.sh mcp` | Editor / tooling integration |
| API | `./scripts/run-cipher-final.sh api` | Programmatic HTTP / WS (future automation) |
| UI  | `./scripts/run-cipher-final.sh ui`  | Visual interface (if version supports) |

VS Code: Run Task → choose any *Cipher: Final ...* entry.

---
## 4. Environment & Secrets

1. Ensure `memAgent/.env` contains (example):
   ```bash
   OPENAI_API_KEY=sk-...redacted...
   ```
2. Do **not** commit real keys.
3. The runner script auto-sources `memAgent/.env`. Manual sessions must `source` it first.

Validation:
```bash
source memAgent/.env
echo "Key length: ${#OPENAI_API_KEY}"   # Non-zero → loaded
```

---
## 5. Ingestion Lifecycle

Cipher 0.2.2 (current) lazily ingests from `data_sources` when:
1. A search is performed (`search <query>` in CLI), or
2. A conversation requires retrieval context.

To force initial population:
```bash
./scripts/run-cipher-final.sh cli "search architecture overview"
```
Check logs (set `logging.level: debug`) for lines like:
`[VectorStore:Index] Inserted N vectors`

### Rebuilding Memory
If underlying docs changed significantly:
1. Stop agent.
2. (Optional) Remove vector DB: `rm memAgent/data/tsms_vector_store.db` (if persistent backend configured in future).
3. Restart and trigger a broad search.

---
## 6. Search & Retrieval Usage

Patterns in CLI:
```
search POS transaction normalization
search "idempotent void transaction safeguards"
```
Or natural language prompting:
```
Explain the POS transaction normalization flow.
```
If results feel thin:
* Raise `max_results` (e.g., 15 → 25) in `memory`.
* Lower `similarity_threshold` slightly (0.75 → 0.70) but monitor noise.
* Increase `chunk_size` if context splitting is too aggressive.

---
## 7. Memory Tuning Cheat Sheet

| Goal | Adjust | Effect |
|------|--------|--------|
| Broader recall | similarity_threshold ↓ | Adds fuzzier matches |
| More precision | similarity_threshold ↑ | Reduces off-topic snippets |
| Larger semantic units | chunk_size ↑ | Fewer, denser chunks (risk: token bloat) |
| Higher context richness | max_results ↑ | Returns more embeddings per query |
| Faster initial responses | max_results ↓, chunk_size ↓ | Smaller retrieval surface |

Edge considerations:
* Overlap too low → context fragmentation.
* Overlap too high → redundant embedding cost.
* Very large chunk_size → risk of truncation for certain queries.

---
## 8. Extending Providers

Add a new static provider (e.g., compliance rules):
```yaml
providers:
  - name: "compliance-rules"
    type: "static"
    priority: 60
    enabled: true
    config:
      content: |
        Follow TSMS compliance guidelines. If a request risks PHI leakage, ask for redaction.
```
Maintain descending priority; higher = earlier merge influence.

---
## 9. MCP Expansion

Current config has no external MCP servers. To add one:
```yaml
mcpServers:
  doc-tools:
    transport: stdio
    command: "node"
    args: ["./mcp/doc-tools.js"]
    autoRestart: true
```
Run in MCP mode and verify handshake logs.

---
## 10. Troubleshooting Matrix

| Symptom | Probable Cause | Action |
|---------|---------------|--------|
| `Failed to load agent config` (early) | YAML indentation / missing providers/settings | Validate via Node YAML parse test |
| `API key for openai not found` | Env not sourced / wrong var name | `echo $OPENAI_API_KEY`; ensure runner script used |
| `Invalid LLM configuration provided` (followed by success) | Early lazy key resolution quirk | Ignorable if later “Verified API key” / success path continues |
| Empty search results | Not ingested yet / threshold high | Trigger broad search, lower similarity_threshold |
| Memory not persisting across restarts | In-memory fallback mode | Await or configure persistent backend once supported |
| High token usage | Large chunk_size / many results | Tune chunk_size & max_results |

Quick YAML parse check:
```bash
node -e "console.log(require('yaml').parse(require('fs').readFileSync('memAgent/cipher-final.yml','utf8')));"
```

---
## 11. Security & Hygiene

| Area | Guideline |
|------|-----------|
| Secrets | Never commit real API keys; keep only `.env.example` placeholder |
| Data scope | Ensure sensitive tables or PII paths are excluded in `data_sources` |
| Log level | Use `info` outside debugging sessions (avoid sensitive leakage) |
| Tool calls | Confirm no unintended shell commands executed by model beyond allowed internal tools |

Add exclusions by extending the root directory data_source `exclude` list.

---
## 12. Operational Playbooks

### A. First-Time Setup
```bash
npm install -g @byterover/cipher
cp memAgent/.env.example memAgent/.env  # add key
source memAgent/.env
./scripts/run-cipher-final.sh cli "search architecture"
```

### B. Daily Dev Loop
1. Start MCP server (VS Code task or script).  
2. Ask architecture or integration questions directly in tooling.  
3. If new docs added → run a broad search to embed.

### C. Adding New Knowledge
1. Drop new `.md` file in `_md/` or `docs/`.  
2. Run: `./scripts/run-cipher-final.sh cli "search <topic>"` to force ingestion.  
3. Confirm embedding logs.

### D. Reset State (Cold Rebuild)
```bash
rm -f memAgent/data/tsms_vector_store.db || true
./scripts/run-cipher-final.sh cli "search transaction pipeline"
```

---
## 13. Roadmap Suggestions

| Enhancement | Rationale |
|-------------|-----------|
| Persistent vector dual-store | Survive restarts & enable diff-based updates |
| Embedding batch optimization | Reduce per-file load latency |
| Knowledge graph enablement | Richer cross-doc linkage for integration queries |
| Session pruning cron | Prevent unbounded SQLite growth |
| Provider health diagnostics | Faster detection of misconfigured file providers |

---
## 13b. Change Tracking & Ingestion Acceleration

Implemented tooling now enables proactive tracking of project movement:

| Script | Purpose |
|--------|---------|
| `scripts/cipher-warm-all.sh` | Forces broad initial embedding via curated semantic searches |
| `scripts/cipher-refresh-changes.sh` | Embeds only changed files vs a git base (default `origin/main`) |
| `scripts/cipher-generate-daily-activity.sh` | Produces a digest file in `docs/` to summarize daily changes for retrieval |

### Usage Patterns

Initial cold start after pull:
```bash
./scripts/cipher-warm-all.sh
```

After making or pulling changes:
```bash
./scripts/cipher-refresh-changes.sh
```

Daily (optionally via cron/CI):
```bash
./scripts/cipher-generate-daily-activity.sh && ./scripts/cipher-warm-all.sh
```

### When To Use Which
| Scenario | Script |
|----------|--------|
| Fresh environment / new dev onboard | warm-all |
| Small incremental edits | refresh-changes |
| Release day / audit log | daily-activity + warm-all |

### Extensibility Ideas
* Add a pre-push git hook that runs `cipher-refresh-changes.sh` to ensure new docs are embedded.
* Append vector count deltas to the daily digest for growth monitoring.
* Add a `cipher-inventory` command that enumerates which sources have produced embeddings (requires Cipher API / future hooks).

---
## 14. FAQ

**Why two configs?**  
`cipher-test.yml` isolates base schema & key path; `cipher-final.yml` is full production memory spec.

**Why is there an early LLM warning?**  
Transient; lazy initialization corrects once env processed.

**How do I add a custom tool?**  
Add an MCP server (stdio or sse) exposing methods, then reference under `mcpServers`.

**How to reduce noise in results?**  
Increase `similarity_threshold` (e.g., 0.80) or reduce `max_results`.

---
## 15. Quick Command Reference

```bash
# Run final CLI
./scripts/run-cipher-final.sh cli

# Force ingest via broad search
./scripts/run-cipher-final.sh cli "search architecture"

# Test minimal config
cipher --agent memAgent/cipher-test.yml --mode cli "ping"

# Start MCP server (background via VS Code task recommended)
./scripts/run-cipher-final.sh mcp

# Full pre-dev routine (digest + warm + refresh + MCP)
./scripts/cipher-pre-dev.sh
```

---
### Pre-Development Routine (Recommended Daily Sequence)

1. Ensure env/API key: `grep OPENAI_API_KEY memAgent/.env`
2. Run VS Code task: `Cipher: Pre-Dev Routine` (or manually `./scripts/cipher-pre-dev.sh`)
3. Confirm MCP running (task output or `cat memAgent/cipher_mcp.pid`)
4. Ask a grounding question: `search architecture overview`
5. Proceed with coding / story work.

Decision Branches:
| Situation | Action |
|-----------|--------|
| New day / first session | Pre-Dev Routine (performs warm) |
| Already warmed today | Routine skips warm (idempotent) |
| Roles not recalled | `cipher --agent memAgent/cipher-final.yml --mode cli "search bmad roles"` |
| Large doc changes pulled | Run `Cipher: Full Re-Warm (Digest + Warm)` |

Sentinels & State:
| File | Meaning |
|------|---------|
| `memAgent/.state/warm-<date>.flag` | Warm ingestion done for that day |
| `docs/DAILY_ACTIVITY_<date>.md` | Digest available & ingestible |

Minimal Manual Fallback (if scripts unavailable):
```bash
source memAgent/.env
cipher --agent memAgent/cipher-final.yml --mode mcp --port 3333 &
./scripts/cipher-warm-all.sh
./scripts/cipher-refresh-changes.sh
```

---
**Maintainer Note:** Keep this document updated when upgrading Cipher versions (API or schema changes may simplify providers/settings requirements or add native load/search subcommands).
