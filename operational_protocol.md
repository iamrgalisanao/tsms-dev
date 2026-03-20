# Operational Protocol for TSMS Development

This document is a **mandatory pre-flight and task-gating protocol** for AI-assisted development of the TSMS (Transaction Management System). It must be followed at the beginning of every session to ensure secure development, controlled change management, and terminal data integrity.

---

## 1. Protocol Intent
The AI must not jump directly into coding without establishing task type, risk level, and TSMS-specific safety boundaries. This ensures:

- **Secure Development Discipline**: Controlled change management and auditability.
- **Tenant Isolation**: Prevention of cross-tenant data leakage between PITX business units.
- **Data Integrity**: Accuracy and dual-layer checksum compliance (V2.0/V2.1).
- **Validation Readiness**: Rigorous testing before implementation closure.

---

## 2. Session Startup Rules
At the start of each session, the AI must:

1.  **Verify the active branch**: `git branch --show-current`
2.  **Inspect the repo state**: `git status --short`, `git diff --name-only`
3.  **Classify the task** and risk level.
4.  **Identify Protected Areas** affected.
5.  **Define the expected validation path** before implementation begins.

---

## 3. Task Classification (Mandatory)

### 3.1 Work Type
`Feature` | `Fix` | `Refactor` | `Research` | `Compliance` | `Operational` | `Hotfix`

### 3.2 Risk Level
`Low` | `Medium` | `High` | `Critical`
> [!IMPORTANT]
> **High/Critical** risk levels require explicit senior review before proceeding to EXECUTION.

### 3.3 Impact Tags
- **General**: `UI (ReactJS)`, `Integration`, `Secrets/Config`, `Dependency Change`, `Auth`
- **TSMS-Specific**: `Tenant Isolation`, `Transaction Record`, `Void/Refund Logic`, `Checksum Parsing`, `Redis Sharding`

---

## 4. Repo State and Protected Areas

### 4.1 Protected Areas
Treat the following as strictly **protected**:
- **Auth/Security**: Sanctum `pos_api` guard, ability-based routing, and environment secrets.
- **TSMS Core**: `tenant_id` global scopes, `PayloadChecksumService`, and the Audit Engine.
- **Queue Logic**: Sharded Redis queue distribution (`s0-s7`) and Horizon configuration.

### 4.2 Security and Code Hygiene Scan
Before implementation, scan for:
- **Debug Artifacts**: `console.log`, `dd()`, or hardcoded credentials.
- **TSMS Hygiene**: Queries missing `tenant_id` filters (Tenant Leakage) or state changes without audit triggers.
- **Redundancy**: Multiple audit log creation attempts in the same method.

---

## 5. Required Companion Document Triggers
Consult focused architecture docs as required:

- **`docs/architecture/sharding-strategy.md`**: Required when affecting Redis queues or job serialization.
- **`docs/architecture/checksum-v2-protocol.md`**: Required when changing ingestion or payload parsing.
- **`docs/security/terminal-auth-matrix.md`**: Required when changing Sanctum token abilities or POS roles.
- **`.agents/subagents/compliance-scanner.md`**: Required for all ingestion or PII-affecting PR reviews.
- **`.agents/subagents/architectural-sentinel.md`**: Required for new Service/Logic implementation.
- **`.agents/subagents/ui-ux-critic.md`**: Required for all Dashboard/React modifications.
- **`.agents/skills/[skill]/SKILL.md`**: Required for specialized domain tasks (Ingestion, Governance, Security).

---

## 6. Validation Readiness Gate
No task is implementation-ready until success criteria are defined:

- **Tenant Isolation**: Verification that a terminal cannot access another tenant's data.
- **Checksum Check**: Evidence that both V2.1 and V2.0 fallback validation paths work.
- **Audit Check**: Confirmation that a record was generated in the `TransactionValidation` table.

---

## 7. Mandatory Session Output Format
At the first task boundary, the AI must provide:

- **Active Branch**: `[branch-name]`
- **Work Type**: `[Feature/Fix/etc.]`
- **Risk Level**: `[Low/Med/High/Critical]`
- **Impact Tags**: `[TSMS-Specific tags]`
- **Planned Validation**: `[How success will be proven]`