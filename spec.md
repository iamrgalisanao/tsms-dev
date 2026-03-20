# TSMS Domain Specification

## 1. Introduction
TSMS (Transaction Management System) is a high-performance, multi-tenant ingestion system designed for PITX terminal operations. It prioritizes absolute data isolation, auditability, and cryptographic integrity to provide a robust platform for transaction management.

### 1.1 Project Vision
To provide a scalable, secure, and standalone source of truth for transaction data that supports diverse POS workflows while maintaining strict tenant-level isolation.

### 1.2 Objectives
- **Multi-tenancy**: Support multiple independent terminal owners on a single platform.
- **Interoperability**: Adhere to POS V2.1 standards with future readiness for HL7/FHIR clinical data exchange.
- **Operational Excellence**: Streamline workflows from terminal registration to final validation with sub-second latency.
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

### 4.1 Checksum Verification
- **Dual-Support**: All ingestion must pass SHA-256 integrity checks.
- **Fallback Logic**: Systems attempt V2.1 (String-based) validation first, falling back to V2.0 (Float-based) for legacy compatibility.

### 4.2 State Machine
- **Workflow**: `PENDING` → `VALID` / `INVALID` → `COMPLETED`.
- **Void/Refund**: POS-initiated voids require authenticated Sanctum tokens and must include a documented reason for audit tracking.

### 4.3 Standards Readiness
While focused on POS data, models are designed for future **HL7 v2** and **FHIR** compatibility, allowing the ingestion engine to evolve into a clinical-ready hub if required.

---

## 5. Non-Negotiables (Guardrails)

> [!IMPORTANT]
> These rules are non-negotiable for all team members.

1. **Cryptographic Gatekeeping**: Never bypass the `PayloadChecksumService`.
2. **Isolated Authority**: Never build or maintain logic for external WebApp forwarding; the forwarder is **DISABLED**.
3. **Audit Requirement**: Never allow a transaction to be marked `VALID` without a corresponding record in the `TransactionValidation` table.
4. **Validation Integrity**: Never mark a clinical-workflow work complete without regression testing.
5. **Report Fidelity**: Never silently change transaction reports, results, or audit-affecting behavior.
6. **Documentation First**: Never deploy result-sensitive or treatment-sensitive (financial) changes without documentation updates.
7. **Audit Priority**: Never let a commercial feature override auditability or privacy rules.
8. **Compliance Investigation**: Never patch a compliance defect (DPA/NPC) without a root-cause investigation.
9. **Offline Integrity**: Never allow offline sync to create duplicate or untraceable records or unauthorized data cached on client.
10. **Persistence Mapping**: Never treat file-based and relational persistence as interchangeable without documented mapping rules.
11. **Protected Rules**: Never allow sensitive records to bypass protected audit rules during a refund or void action.
12. **Lawful Basis**: Never process sensitive personal information without a verified lawful basis (Consent or DPA Exception).
13. **Proportionality**: Never ignore the principle of Proportionality; collect only the minimum data necessary for the purpose.
14. **Privacy Impact**: Never initiate a major feature or data-flow change without a Privacy Impact Assessment (PIA).
15. **Composite Uniqueness**: Always use the composite uniqueness of `transaction_id + terminal_id`.
16. **Server Truth**: Never allow UI-side (ReactJS) calculations to override backend truth (MySQL) for totals or taxes.

---

## 6. Required Project Context System

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