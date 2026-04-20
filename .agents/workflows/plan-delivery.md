# Workflow: Plan Delivery

This workflow defines how to break down normalized requirements into actionable implementation units.

## Stage Gates
- **Gate 1: Decomposition Completeness**. Does every task map to a requirement or technical dependency?
- **Gate 2: Verification Strategy**. Does every major task have a defined validation method?

## Procedure
1.  **Read Context**: Review `normalized-requirements.md` and `tech-stack-profile.md`.
2.  **Define Phases**: Group tasks into logical release phases (e.g., Phase 1: Core Logic, Phase 2: Observability).
3.  **Identify High-Risk Gates**: Mark tasks involving security, billing, or schema changes for extra review.
4.  **Produce/Update Governance Docs**:
    - `docs/ai-governance/delivery-plan.md`
    - `docs/ai-governance/task-breakdown.md`
    - `docs/ai-governance/stage-gates.md`
    - `docs/ai-governance/verification-checklist.md`
5.  **Summarize Planning**: Create a `planning-summary` artifact.

## Acceptance Criteria
- Plan is approved by the user.
- Risks and dependencies are visible and mitigated.
- Rollback or revert strategy is defined for high-risk items.
