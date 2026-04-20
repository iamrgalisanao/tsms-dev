# Workflow: Project Intake

This workflow defines the process for receiving a new requirement, normalizing it, and preparing for planning.

## Stage Gates
- **Gate 1: Minimum Viable Context**. Do we have enough information to define the business objective and success criteria?
- **Gate 2: Terminology Alignment**. Are technical and business terms normalized?

## Procedure
1.  **Read Inputs**: Ingest PRDs, BRDs, meeting notes, etc.
2.  **Identify Core Entities**: List actors, data objects, and primary workflows.
3.  **Check Constraints**: Identify technical, time, or regulatory constraints.
4.  **Extract Assumptions**: Explicitly log everything being assumed about the requirement.
5.  **Produce/Update Governance Docs**:
    - `docs/ai-governance/normalized-requirements.md`
    - `docs/ai-governance/assumptions-register.md`
    - `docs/ai-governance/fact-vs-assumption.md`
    - `docs/ai-governance/open-questions.md`
6.  **Summarize Intake**: Create an `intake-summary` artifact.

## Rejection Criteria
- Requirement is too vague to decompose.
- Requirement conflicts with core architectural non-negotiables.
- Critical tech stack information is missing.
