# TSMS Domain Specification (V2.2 Final)

## 1. Introduction
TSMS (Transaction Management System) is a high-performance, multi-tenant ingestion system designed for PITX terminal operations. It prioritizes absolute data isolation, auditability, and cryptographic integrity to provide a robust platform for transaction management.

### 1.1 Project Vision
To provide a scalable, secure, and standalone source of truth for transaction data that supports diverse POS workflows while maintaining strict tenant-level isolation.

### 1.2 Objectives
- **Multi-tenancy**: Support multiple independent terminal owners on a single platform.
- **Interoperability**: Adhere to POS V2.2 Final standards with dual-layer checksum verification.
- **Operational Excellence**: Streamline workflows from terminal registration to final validation with optimized asynchronous processing.
- **Auditability**: Ensure all state changes (voids/refunds) are logged and verifiable.

---

## 2. Core Architecture (A.N.T. Design)
The system follows a 3-layer architectural pattern:

- **Layer 1: Architecture (SOPs & Policy)**: Located in `docs/architecture/`. Contains design documents for sharding, checksum protocols, and audit standards.
- **Layer 2: Navigation (Context Resolution)**: Handles `tenant_id` resolution, Sanctum authentication (`pos_api`), and ability-based routing.
- **Layer 3: Tools (Deterministic Execution)**: Python/PHP scripts in `tools/` for payload validation, data seeding, and automated parsing.

---

## 3. Multi-Tenancy & Isolation
TSMS employs a "Shared-Database, Isolated-Schema" approach enforced via global query scopes.

### 3.1 Data Isolation
- **Tenant Scope**: Every database query is automatically scoped by `tenant_id`.
- **Isolation Enforcement**: Middleware ensures terminals only access data belonging to their assigned tenant.
- **Queue Sharding**: Jobs are sharded across 8 queues (`s0-s7`) based on `tenant_id` to prevent performance bottlenecks.

### 3.2 Tenant Onboarding (Modes)
Tenants are bootstrapped according to their operational scale:
- **Mode 0 (MVP)**: Basic transaction ingestion and validation.
- **Mode 1 (Standard)**: Adds void/refund capabilities and basic reporting.
- **Mode 2 (Enterprise)**: Adds advanced audit trails, sharded fairness, and high-availability configuration.

### 3.3 Shared Staff Protocol
The system supports cross-tenant auditability for shared administrators (PITX Ops), while keeping the underlying transaction data strictly isolated to the original tenant owner.

---

## 4. Transaction Standards & Integrity

### 4.1 Checksum Verification (Dual-Layer)
- **Layer 1 (Transaction)**: An inner SHA-256 hash of the transaction object.
- **Layer 2 (Submission)**: An outer SHA-256 hash of the entire submission envelope, including the already-hashed transaction.
- **Canonicalization**: Strict alphabetical sorting (ksort) and 2-decimal string formatting are required before hashing.

### 4.2 Ingestion Model
TSMS utilizes a **Synchronous Handshake** followed by an **Asynchronous Completion** model:
1. **Handshake**: The POS submits the transaction via `POST /transactions/official`. The server verifies the token and checksums synchronously. If valid, the record is persisted in the database and a `200 OK` (ACCEPTED) is returned.
2. **Asynchronous Validation**: A background worker (`ProcessTransactionJob`) performs computation audits, tax-math reconciliation, and business rule enforcement.
3. **Polling/Status**: POS systems must poll the `/status` endpoint until a terminal state (`VALID` or `FAILED`) is reached.

### 4.3 State Machine
- **Workflow**: `PENDING` → `VALID` / `FAILED`.
- **Void/Refund**: POS-initiated voids and refunds require the original transaction to be successfully ingested. Voids move the transaction to `VOIDED`, while refunds update `is_refunded` and compute `refund_amount`.

### 4.4 Standards Readiness
While focused on POS V2.2 data, models are designed for future **HL7 v2** and **FHIR** compatibility, allowing the ingestion engine to evolve into a clinical-ready hub if required.

---

## 5. Non-Negotiables (Guardrails)

> [!IMPORTANT]
> These rules are non-negotiable for all team members.

1. **Passive Ingestion**: Always ingest the transaction payload passively without mutation. The database must reflect the "raw truth" as submitted by the POS.
2. **Cryptographic Gatekeeping**: Never bypass the `PayloadChecksumService`.
3. **Isolated Authority**: The WebApp forwarding engine (`ForwardTransactionsToWebAppJob`) MUST remain **PERMANENTLY DISABLED**. TSMS is a standalone archive.
4. **Audit Requirement**: Never allow a transaction to be marked `VALID` without a corresponding record in the `TransactionValidation` table.
5. **Validation Integrity**: Never mark a clinical-workflow work complete without regression testing.
6. **Report Fidelity**: Never silently change transaction reports, results, or audit-affecting behavior.
7. **Documentation First**: Never deploy financial or audit-sensitive changes without documentation updates.
8. **Audit Priority**: Never let a commercial feature override auditability or privacy rules.
9. **Uniqueness Authority**: Always use the composite uniqueness of `tenant_id + transaction_id`.
10. **Idempotency**: Always reuse the original `transaction_id` for retries. If an ID exists but fields differ, return `409 Conflict`.
11. **Server Truth**: Never allow UI-side calculations to override backend truth (MySQL) for totals or taxes.
12. **Lawful Basis**: Never process personal information without a verified lawful basis (Consent or DPA Exception).
12. **Proportionality**: Collect only the minimum data necessary for the purpose.

---

## 6. Phase 1 Constraints (Deadlock Prevention)

To ensure system stability during peak hours, TSMS enforces the following technical constraints for Phase 1:

- **Single Transaction Rule**: Every API submission must contain exactly **one** transaction. Batch submissions via the `transactions` array are disabled.
- **Sequential Submission (FIFO)**: Terminals must submit transactions in chronological order. Subsequent events (voids/refunds) sent before the parent sale is ingested will trigger a `404 Not Found`.
- **Locking Strategy**: Background jobs are sharded by `tenant_id` (`s0-s7`) to minimize DB lock contention.

---

## 7. Server Response Protocols

TSMS uses standardized HTTP status codes paired with internal machine-readable codes to guide POS integration behavior.

### 7.1 Ingestion Responses (POST /official)
- **200 OK (`already_processed`)**: The transaction was already successfully ingested. POS should treat this as a success and proceed to the next record.
- **201 Accepted (`ACCEPTED`)**: The initial handshake is complete. The transaction is persisted and queued for background validation.
- **409 Conflict (`DUPLICATE_TRANSACTION`)**: The `transaction_id` exists, but the submitted payload (monetary values or metadata) differs from the original.
- **422 Unprocessable Entity (`BATCH_DISABLED`)**: Payload contains a `transactions` array, which is prohibited in Phase 1.

### 7.2 Operation Responses (Void/Refund)
- **404 Not Found**: The target `transaction_id` does not exist in TSMS. This typically happens if the POS violates the **Sequential Submission Rule** (e.g., trying to void a sale that hasn't been ingested yet).
- **403 Forbidden**: Terminal attempted to operate on a transaction belonging to another terminal or tenant.

### 7.3 Status Polling (GET /status)
- **validation_status**: `PENDING` (Job not started), `VALID` (Audit passed), `FAILED` (Audit failed).
- **job_status**: `QUEUED`, `PROCESSING`, `COMPLETED`, `FAILED`.

---

## 8. Required Project Context System

To maintain architectural discipline, the following files and directories must exist and be maintained:

### 6.1 Required Root Files
- `task_plan.md`: Overall phases, milestones, and priorities.
- `progress.md`: Current completion state, blockers, and next steps.
- `findings.md`: Verified discoveries, research evidence, and issue lessons.
- [`coding-standards.md`](docs/standards/coding-standards.md): Mechanical rules for naming, sharding, and safety.

### 6.2 Required Directories
- `docs/architecture/`: HL7 Engine, Unified Architecture, Sharding Logic.
- `docs/compliance/`: DPA Manual, PIA Reports, Audit Protocols.
- `docs/security/`: RBAC Matrix, Security Hygiene Guardrails.
- `tools/`: Deterministic scripts for validation and seeding.

---

## 7. Data Privacy & DPA Compliance (RA 10173)

TSMS is committed to the Philippines Data Privacy Act of 2012 (DPA). Privacy-by-Design is integrated into every workflow.

### 7.1 Core Principles
- **Transparency**: Tenants/Terminals must be informed about data processing logic.
- **Legitimate Purpose**: Data is processed only for declared operational reasons.
- **Proportionality**: Minimize data collection to what is necessary for auditing.

### 7.2 Data Subject Rights
The system supports the following rights for data owners:
1. **Right to be Informed**
2. **Right to Access** (Export/Portal)
3. **Right to Object** (Opt-out of tracking)
4. **Right to Rectification**
5. **Right to Erasure or Blocking**
6. **Right to Data Portability** (Audit Log Export)

### 7.3 Technical Measures
- **RBAC**: Role-Based Access Control enforced at the API layer.
- **Encryption**: Mandatory encryption of Personal Information (PI) at rest and in transit.
- **PIA**: Mandatory Privacy Impact Assessment for every new ingestion module.