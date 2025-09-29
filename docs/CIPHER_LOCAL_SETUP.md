# Local Cipher Setup (macOS + VS Code + BMad-method)

This guide walks through configuring the Cipher memory agent locally on a macOS development machine using Visual Studio Code. It assumes you already have the project repository checked out and that the BMad-method/team‑fullstack bundle is available (the repository includes web-bundles for this).

Target audience: developer or operator setting up a local Cipher memory for TSMS development and testing.

Prerequisites
 - macOS (this guide was written and tested on macOS Ventura/Monterey/greater)
 - Homebrew (recommended)
 - Node.js (>= 18+ recommended) and npm installed
 - PHP (with composer) and the project dependencies installed (for Laravel)
 - Visual Studio Code installed and configured
 - BMad-method (team-fullstack bundle) files present in the repository under `web-bundles/teams/team-fullstack.txt`

Quick overview
1. Install Cipher CLI (npm global)
2. Configure `memAgent/.env` with an OpenAI key and local ports
3. Run the helper script to start the local MCP server (or API/UI) and/or load the project bundle
4. Connect VS Code to the MCP server (localhost:3333) and run test queries

Files & scripts in this repository you can use
- `scripts/tsms-cipher-memory.sh` - wrapper to load bundle and start agents (MCP/API/UI)
- `scripts/cipher-warm-all.sh` - force embedding warm-up (optional)
- `scripts/cipher-refresh-changes.sh` - refresh embeddings for changed files
- VS Code tasks provided: search the Tasks panel for labels beginning with `TSMS: ` and `Cipher:` (examples: "TSMS: Load Project Knowledge into Cipher Memory", "TSMS: Start Cipher Memory Agent (MCP Mode)").

Step-by-step instructions

## 1) Install prerequisites (if you haven't already)

Open a terminal (zsh) and run the following (examples):

```bash
# Install Node.js (with Homebrew)
brew install node

# Install cipher CLI globally
npm install -g @byterover/cipher

# Ensure composer dependencies for Laravel project
composer install

# Install frontend/node deps if you will load UI parts
npm install
```

## 2) Configure environment for the memAgent

The repository contains a `memAgent/` directory with an `.env` used by the scripts. Copy the example (if present) and set your OpenAI API key.

```bash
cd /path/to/tsms-dev
cp memAgent/.env.example memAgent/.env 2>/dev/null || true
open memAgent/.env  # or use your editor
```

Ensure the following variables exist in `memAgent/.env` and are set appropriately:
- `OPENAI_API_KEY` – your OpenAI-compatible embedding key
- `CIPHER_PORT` or similar if you want to change default port (default used by project is 3333)
- any other environment controls used by the project scripts (inspect `memAgent/.env` / `memAgent/cipher-final.yml`)

Notes:
- Do NOT check sensitive keys into git.
- If you don't have an OpenAI key, you can still run the agent in-memory for limited local testing (some functions that require LLM calls will fail or be skipped).

## 3) Start the MCP agent (recommended for VS Code integration)

The repository provides a convenient script that wires up the environment and starts the agent. From the repo root run:

```bash
bash scripts/tsms-cipher-memory.sh --enable-memory --cipher-mode mcp --verbose
```

What this does:
- Loads `memAgent/.env`
- Starts the Cipher MCP server (the repo uses port 3333 by default)
- Runs in background (script prints the PID)

You can also run the VS Code task from the Command Palette: "Tasks: Run Task" → `TSMS: Start Cipher Memory Agent (MCP Mode)` (the workspace includes tasks configured for convenience).

## 4) Load the project knowledge bundle into memory

To ingest repo docs and code into the local vector store run the loader:

```bash
bash scripts/tsms-cipher-memory.sh --load-bundle --verbose
```

This will:
- Start a CLI-driven loader using the team bundle (e.g. `web-bundles/teams/team-fullstack.txt`) which teaches the agent how to act and what documents to index
- Create in-memory vector collections (by default) or persistent stores if you configured them

Alternative: Use the VS Code task `TSMS: Load Project Knowledge into Cipher Memory`.

## 5) Optional: warm or refresh embeddings

If you want to force embeddings to be (re)computed for changed files use:

```bash
bash scripts/cipher-refresh-changes.sh
# or force a complete warm-up (may be slow)
bash scripts/cipher-warm-all.sh
```

The repo also exposes a full setup task (load + start MCP) named `TSMS: Full Cipher Setup (Load + Start MCP)`.

## 6) Connect VS Code to the MCP server

In VS Code install any Cipher / MCP extension or configure your dev tooling to speak to the MCP server at `localhost:3333`.

If your extension asks for host/port, supply:

- Host: `localhost`
- Port: `3333`

After connection you should be able to run searches, ask contextual questions, and use the project-specific team bundle prompts loaded earlier.

## 7) Using the cipher CLI directly (examples)

Run a quick search from the repo root (uses the `cipher` executable installed earlier):

```bash
# Example: simple search for 'z-reading parsing'
cipher --agent memAgent/cipher-final.yml --mode cli --action search --query "z-reading parsing"

# Run a CLI action defined by the agent
cipher --agent memAgent/cipher-final.yml --mode cli 'Search for POS transaction processing'
```

Note: if your environment or the YAML agent references a specific provider, the CLI will try to use it (embedding model calls require `OPENAI_API_KEY`).

## 8) Start the Cipher API or Web UI (optional)

You can start the API or UI modes if you prefer a browser interface. The repo provides tasks for these as well.

```bash
# Start API server (background)
bash scripts/tsms-cipher-memory.sh --enable-memory --cipher-mode api --verbose &

# Start web UI (background)
bash scripts/tsms-cipher-memory.sh --enable-memory --cipher-mode ui --verbose &
```

See the workspace tasks: `Cipher: Final API Server` and `Cipher: Final UI Server` for ready-made tasks that also run in background.

## 9) Common troubleshooting

- "Invalid LLM configuration provided to createContextManager" — you can still load embeddings (embedding-only flows work), but full LLM-based context tools need a valid provider key. Check `memAgent/.env` and `memAgent/cipher-final.yml`.
- "No matching changed files vs origin/main" when refreshing — ensure you have local changes or force a full bundle load using `--load-bundle`.
- Port conflicts: if 3333 is used, set/override the port variable in `memAgent/.env` and in the invocation.
- If embedding calls fail due to rate limits or missing keys, verify `OPENAI_API_KEY` and your network connectivity.

## 10) Verification checklist (quick)

1. MCP server responds: `curl http://localhost:3333/health` (if the agent exposes a health endpoint) or confirm the startup log printed a PID.
2. Run a sample search and verify results: `cipher --agent memAgent/cipher-final.yml --mode cli --action search --query "transaction validation"`
3. Confirm docs like `docs/DAILY_ACTIVITY_2025-09-27.md` are discoverable by query.

## 11) Security & local usage notes

- Keep `memAgent/.env` private. Do not commit secrets.
- For reproduction and CI, prefer using a non-production OpenAI key or a local mock embedder.

## 12) Helpful commands (copyable)

```bash
# Start MCP (background)
bash scripts/tsms-cipher-memory.sh --enable-memory --cipher-mode mcp --verbose

# Load project bundle
bash scripts/tsms-cipher-memory.sh --load-bundle --verbose

# Full setup: load + start MCP (background)
bash scripts/tsms-cipher-memory.sh --load-bundle --enable-memory --cipher-mode mcp --verbose &

# Refresh changed files only
bash scripts/cipher-refresh-changes.sh

# Warm all knowledge (force embeddings)
bash scripts/cipher-warm-all.sh
```

## 13) If you use the BMad-method / team-fullstack bundle

The repo already includes the team bundle at `web-bundles/teams/team-fullstack.txt`. When you run the loader with `--load-bundle` the script will use that bundle to configure the agent roles and workflows (Analyst, PM, Architect etc.). No extra steps are required beyond setting `OPENAI_API_KEY` in `memAgent/.env`.

## 14) Next steps and automation

- Add a VS Code launch/task configuration referencing the workspace tasks if you want one‑click start/stop.
- Add a helper script that checks `memAgent/.env` and fails fast if required keys are missing.

If you'd like, I can also:
- Add a `docs` PR with this file included in the repo (I already added this file), or
- Create a small helper task in `.vscode/tasks.json` to run full setup with one click.

---
Created: 2025-09-27 (local developer guide)
