# Harness Contract

This file is the single source of truth for agent invocation identifiers. Every other file in this pack (`agent-matrix.md`, `prompt-library.md`, `checklists.md`, `CLAUDE.md`) must reference the names below, not restate or re-derive them. If a name changes, fix it here first — a copy anywhere else is a bug.

Confirmed by direct test: `Agent(subagent_type: "Software Architect")` resolves correctly. The filename stem (`engineering-software-architect`) does not.

## 1. Agent Name Validation

Agent invocation must use the exact frontmatter `name:` value from `.claude/agents/*.md`, not the filename.

Run before adopting this pack:

```bash
grep -h '^name:' .claude/agents/*.md | sed 's/^name: *//' | sort
```

Expected names used by this pack:

```text
Software Architect
Senior Developer
Code Reviewer
Git Workflow Master
```

If the repository uses different names, update the orchestration files once at the source. Do not compensate with inconsistent prompt aliases.

## 2. Agent Invocation

Use the `Agent` tool with:

- `subagent_type`: exact frontmatter `name:` value;
- foreground execution for slice-loop agents;
- the appropriate model for the risk gate;
- a narrowly scoped prompt.

Do not describe the invocation as “use the X agent” if the actual tool call uses a different subagent type.

## 3. Foreground Execution

Run in foreground when:

- the result determines the immediate next action;
- the same agent will receive follow-up corrections;
- the main thread must inspect the result before proceeding;
- repository mutation is possible.

Background execution is appropriate only for independent, non-mutating research tasks whose outputs do not need immediate sequential decisions.

## 4. SendMessage Continuity

For a slice loop:

```text
Agent(Senior Developer, foreground)
→ targeted verification
→ Agent(Code Reviewer, foreground)
→ finding
→ SendMessage(original Senior Developer agent id, delta-only fix)
→ rerun verification
```

Do not spawn a fresh developer agent for every minor fix. A new agent repeats context discovery and increases token cost.

Spawn a new agent only when:

- the prior agent session ended;
- the task changes responsibility;
- the prior agent has become unreliable;
- independent review requires separation.

## 5. Model Policy

Recommended model routing:

| Gate | Model |
|---|---|
| Mechanical formatting or metadata check | Haiku or inherited low-cost model |
| Scoped implementation | Sonnet |
| Routine code review | Sonnet |
| High-risk architecture review | Opus |
| Redis/Lua, auth, tenancy, migrations, queue topology review | Opus |
| Final pre-push audit for high-risk feature | Opus |
| Git execution after an approved plan | Sonnet |

Do not use the largest model for every step. Do not use a low-cost model for high-risk correctness gates.

## 6. Capability Variance

Tool names and argument fields can vary by harness version.

The orchestration pack therefore defines behavioral requirements rather than hardcoding unverified tool JSON.

Before automation, confirm:

- exact Agent tool schema;
- exact SendMessage tool schema;
- whether tool allowlists/denylists are supported;
- whether model selection is supported per agent call;
- whether foreground/background execution is explicit.
