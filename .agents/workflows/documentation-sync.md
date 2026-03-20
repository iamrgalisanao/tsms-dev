---
description: Synchronize project documentation after every feature or fix.
---

For every feature or fix implemented, the AI MUST update the following files in lockstep before notifying the user of task completion:

1. **`docs/CHANGELOG.md`**
   - Add a descriptive entry under the "[Unreleased]" section.
   - Categorize as `Added`, `Changed`, `Fixed`, or `Security`.

2. **`ROADMAP.md`**
   - Update milestone or feature completion status.
   - Move items to "Completed" if applicable.

3. **`progress.md`**
   - Update the overall completion percentage.
   - Update detailed status for the specific clinical/transactional module.

4. **`WALKTHROUGH.md`**
   - Add a high-level summary of the logic and technical implementation.
   - Include any compliance or data integrity notes.

5. **Compliance Docs**
   - Update `docs/RBAC_matrix.md` if permission requirements changed.
   - Update `docs/standards/transaction-integrity-model.md` for logic changes.

6. **Feature Records**
   - Ensure a corresponding file exists in `docs/context/features/` documenting the implementation.

7. **Subagent Validation**
   - Execute mandated subagent scans (`.agents/subagents/compliance-scanner.md`, `.agents/subagents/architectural-sentinel.md`) for all logical changes.
