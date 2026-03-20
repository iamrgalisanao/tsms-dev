# TSMS Governance Skill

---
name: governance
description: Enforce A.N.T. architecture and maintain documentation sync
argument-hint: sync|audit|sentinel
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
| `sentinel` | Run the `architectural-sentinel` subagent check. |

## Reference Files
- [gemini.md](file:///Users/teamsolo/Projects/PITX/tsms-dev/gemini.md)
- [ROADMAP.md](file:///Users/teamsolo/Projects/PITX/tsms-dev/ROADMAP.md)
- [findings.md](file:///Users/teamsolo/Projects/PITX/tsms-dev/findings.md)
- [documentation-sync.md](file:///Users/teamsolo/Projects/PITX/tsms-dev/.agents/workflows/documentation-sync.md)
