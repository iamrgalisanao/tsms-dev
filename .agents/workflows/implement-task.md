# Workflow: Implement Task

This workflow governs the actual coding and local validation of a specific task.

## Stage Gates
- **Gate 1: Implementation Plan**. Has a bounded plan been created for the specific task?
- **Gate 2: Local Verification**. Has the change been verified via unit tests or manual tool execution?

## Procedure
1.  **Restate Objective**: Summarize the task in 1-2 sentences.
2.  **Verify Governance**: Check `01-project-context.md`, `05-coding-standards.md`, and `06-security-compliance.md`.
3.  **Propose Implementation**: Create a task-specific Implementation Plan artifact.
4.  **Execute boundedly**: Implement the changes in small, verifiable chunks.
5.  **Record Work**: Update `docs/ai-governance/task-ledger.md`.
6.  **Run Validation**: Use tools or tests defined in `docs/ai-governance/tooling-map.md`.
7.  **Summarize Session**: Create a `session-summary` artifact.

## Constraints
- Do not refactor unrelated areas.
- Do not change public contracts without justification.
- Do not trust tool output without evidence.
