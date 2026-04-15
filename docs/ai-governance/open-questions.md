# Open Questions

| ID | Issue | Related Stage | Risk Level |
|----|-------|---------------|------------|
| Q1 | Should `progress.md` be migrated into the AI Governance `task-ledger.md`? | Intake | Low |
| Q2 | Are there specific POS vendors struggling with the FIFO rule (Sequential Submission)? | Ingestion | Medium |
| Q3. **[RESOLVED 2026-04-10]** How should the system handle "mathematical inconsistencies" in transaction payloads during background validation?
    *   **Resolution**: TSMS strictly follows the "Passive Ingestion" rule (archive the raw truth). Inconsistencies are flagged as `FAILED` (if strict) or listed in `SecurityEvents` (if audit-only). For reconciliation mismatches (e.g., Famous Apr 9), the system provides a [Reconciliation Playbook](file:///Users/teamsolo/Projects/PITX/tsms-dev/docs/ai-governance/reconciliation-playbook.md) to explain formulaic divergence between POS and TSMS. | Validation | High |
| Q4 | Is there a need for a real-time monitoring dashboard for sharded queues `s0-s7`? | Operational | Medium |
| Q5 | Should the `tools/data` directory be treated as a permanent archive or a temporary staging area? | Tools | Low |
