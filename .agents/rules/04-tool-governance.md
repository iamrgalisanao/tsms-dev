# 04 Tool Governance Policy

This policy governs the selection, validation, and use of native tools and MCP servers to ensure deterministic outcomes and prevent accidental mutations or context-unaware actions.

## 1. Tool Selection Hierarchy
1. **Stack-Aware Discovery**: Before running any command (e.g., `php`, `npm`, `docker`), verify the tech stack in `docs/ai-governance/tech-stack-profile.md`.
2. **Approved Mapping**: Only use tools mapped to tasks in `docs/ai-governance/tooling-map.md`.
3. **Native vs MCP**: Prefer native bash tools for direct execution and MCP tools (like NotebookLM) for deep research and summarization.

## 2. Mandatory Validation Gates
- **Pre-Execution**: Restate the exact command and its intended side effect before execution if categorized as "High-Risk".
- **Post-Execution**: Verify tool output for success criteria. Treat "exit code 0" as necessary but not sufficient; check for semantic correctness of the output.
- **Error Handling**: If a tool fails, record the failure in `docs/ai-governance/task-ledger.md` and assess if a recovery or revert is required.

## 3. Tool Usage Constraints
- **Destructive Operations**: `rm`, `truncate`, `drop`, or recursive modifications require explicit authorization unless performed within a bounded, approved implementation plan.
- **Environment Isolation**: Ensure commands are run in the appropriate workspace context. Never execute outside the `tsms-dev` project root.
- **No Blind Trust**: AI-generated code or complex CLI flags must be double-checked against documentation before execution.

## 4. Allowed Operations Registry
Refer to `docs/ai-governance/allowed-operations.md` for the current whitelist of permitted terminal actions.
