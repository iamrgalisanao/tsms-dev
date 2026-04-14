# TSMS Governance Skill

---
name: governance
description: Enforce A.N.T. architecture and maintain documentation sync
argument-hint: sync|audit|fix|sentinel
---

# Governance Skill

## Goals
- Maintain the "Source of Truth Quartet" (Gemini, Spec, Protocol, Standards).
- Ensure all features follow Layer 1 (Architecture) before implementation.
- Automate the documentation-sync process.

## Actions
| Action | Description |
|--------|-------------|
| `sync` | Execute the `documentation-sync` workflow across all core docs. |
| `audit` | Generate or update `findings.md` with compliance scan results. |
| `fix` | Create a technical fix record in `docs/context/fixes/` for regressions. |
| `sentinel` | Run the `architectural-sentinel` subagent check. |
| `heal` | Invoke the `self-healing` protocol to autonomously repair syntax/build errors. |

## Reference Files
- [gemini.md](file:///Users/teamsolo/Projects/PITX/tsms-dev/gemini.md)
- [ROADMAP.md](file:///Users/teamsolo/Projects/PITX/tsms-dev/ROADMAP.md)
- [findings.md](file:///Users/teamsolo/Projects/PITX/tsms-dev/findings.md)
- [documentation-sync.md](file:///Users/teamsolo/Projects/PITX/tsms-dev/.agents/workflows/documentation-sync.md)
- [self-healing.md](file:///Users/teamsolo/Projects/PITX/tsms-dev/.agents/workflows/self-healing.md)
