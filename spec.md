# TSMS Domain Specification

## 1. Introduction
TSMS (Transaction Management System) is a modern, web-based, multi-tenant ingestion system designed for PITX terminal operations. It prioritizes absolute data isolation, auditability, and cryptographic integrity to provide a robust platform for transaction management.

### 1.1 Project Vision
To provide a scalable, secure, and standalone source of truth for transaction data that supports diverse POS workflows while maintaining strict tenant-level isolation.

### 1.2 Objectives
- **Multi-tenancy**: Support multiple independent terminal owners on a single platform.
- **Operational Excellence**: Streamline workflows from terminal registration to final validation.
- **Auditability**: Ensure all state changes (voids/refunds) are logged and verifiable.
- **Isolated Integrity**: Maintain a high-performance local database without external dependencies.

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

---

## 4. Transaction Standards & Integrity

### 4.1 Checksum Verification
- **Dual-Support**: All ingestion must pass SHA-256 integrity checks.
- **Fallback Logic**: Systems attempt V2.1 (String-based) validation first, falling back to V2.0 (Float-based) for legacy compatibility.

### 4.2 State Machine
- **Workflow**: `PENDING` → `VALID` / `INVALID` → `COMPLETED`.
- **Void/Refund**: POS-initiated voids require authenticated Sanctum tokens and must include a reason for audit tracking.

---

## 5. Non-Negotiables (Guardrails)

> [!IMPORTANT]
> These rules are non-negotiable for all team members.

- **Cryptographic Gatekeeping**: Never bypass the `PayloadChecksumService`.
- **Isolated Authority**: Never build or maintain logic for external WebApp forwarding; the forwarder is **DISABLED**.
- **Audit Requirement**: Never allow a transaction to be marked `VALID` without a corresponding record in the `TransactionValidation` table.
- **Composite Uniqueness**: Never duplicate transaction ID logic across tenants; always use the composite uniqueness of `transaction_id + terminal_id`.
- **Server Truth**: Never allow UI-side (ReactJS) calculations to override backend truth for totals or taxes.
- **Auth Discipline**: Never patch a terminal authentication defect without a root-cause investigation into the Sanctum ability matrix.
- **Data Minimization**: Never ignore the principle of Proportionality; collect only the minimum POS/Hardware data necessary for auditing.
- **Migration Safety**: Never modify existing table structures without a documented migration and rollback plan.
- **Protected Audits**: Never allow sensitive records to bypass protected audit rules during a refund or void action.
- **Privacy First**: Never initiate a major data-flow change without a Privacy Impact Assessment (PIA).

---

## 6. Data Privacy (RA 10173 Compliance)
TSMS is committed to the Philippines Data Privacy Act of 2012.

- **Security**: Mandatory encryption of Personal Information (PI) at rest and in transit.
- **Audit Logs**: Mandatory tracking of all Create/Update/Delete actions on transaction models.
- **Data Rights**: Support for the Right to Portability (Export) and Access for all terminal data owners.