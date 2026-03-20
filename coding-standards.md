# Coding Standards: TSMS

**Version:** 1.1 (High-Governance Edition)  
**Applies to:** All development and integration for the TSMS Ingestion Engine.

All changes must comply with:
- [security-hygiene-guardrails.md](docs/security/security-hygiene-guardrails.md)
- [pr-review-checklist.md](docs/standards/pr-review-checklist.md)
- [operational_protocol.md](operational_protocol.md)

---

## 0. Core Engineering Principles

1. **Transaction Integrity First**: Code affecting financial calculations, checksums, or transaction status must be reviewed with extra rigor.
2. **Privacy by Design**: Protect Customer PII (Personal Identifiable Information) by default (RA 10173).
3. **Tenant Isolation**: Absolute row-level isolation via global scopes and `BelongsToTenant` trait.
4. **Least Privilege**: POS-to-API communication must use minimal necessary abilities (Sanctum `pos_api` guard).
5. **Traceability**: All state changes (Voids, Refunds) must be attributable to a `terminal_id`.
6. **Deterministic Behavior**: Financial logic must avoid floating-point ambiguity. Use `decimal(15,2)` in DB and precise rounding in PHP.

## 1. Naming & Domain Language

- **General**: Clear, descriptive names. `pii_` (PII), `tx_` (Transaction), `audit_` (Audit) prefixes encouraged.
- **Transaction Rules**: Must use V2.1 Payload terminology (`gross_sales`, `vat_amount`, `sc_vat_exempt_sales`).
- **Functions**: Read as intent: `PayloadChecksumService::validateWithVersion()`.

## 2. Business Logic Separation (High-Governance)

- **Mandate**: Transaction rules (checksum validation, ingestion logic, tax calculations) MUST NEVER reside in UI (React) or Controllers.
- **Location**: Use dedicated services in `app/Services/`:
    - `PayloadChecksumService` (Checksum Proofing)
    - `TransactionIngestService` (Atomicity & Persistence)
    - `DeadlockRetryService` (Concurrency Management)
- **No Hidden Logic**: Ingestion rules must not rely on magic numbers. Use `Transaction::STATUS_*` constants.

## 3. Transaction Workflow & Auditability

### 3.1 Transaction State Machines
Workflows must use explicit states: `PENDING` → `VALID` / `INVALID` → `COMPLETED` / `VOIDED` / `REFUNDED`. Never infer state from nulls.

### 3.2 Dual-Checksum Integrity
Every ingestion must trigger:
1. V2.1 string-based canonicalization.
2. V2.0 float-based fallback (for legacy).
3. Record Proof in `transaction_validations` table.

### 3.3 Immutability & Amendments
Transaction data, once validated, is immutable. Corrections require:
- **Voids**: `voided_at`, `void_reason`.
- **Refunds**: `refund_status`, `refund_amount`, `refund_reference_id`.

## 4. Database Standards: Multi-Tenant Safety

### 4.1 Tenant Enforcement
Every data access MUST include `tenant_id`. Handled via the mandatory **`BelongsToTenant` trait** and **`TenantScope`** in Laravel models.

### 4.2 Safe Execution
- **Eloquent Queries**: Default to `where('tenant_id', ...)` scope.
- **Background Jobs**: Workers must carry and re-establish `tenant_id` and `terminal_id` context.
- **Raw Queries**: Never use `DB::table` or raw SQL without manual `tenant_id` scoping to prevent cross-tenant lookup leakage.
- **Logs**: Every log line for transactional actions must include `tenant_id`.

## 5. POS Ingestion Standards

- **Idempotency**: Use `insertOrIgnore` with `transaction_id` + `terminal_id` to prevent duplicate processing.
- **Sharded Fairness**: Jobs must be routed to sharded queues (`s0-s7`) based on `tenant_id % 8` to prevent head-of-line blocking by noisy tenants.
- **Concurrency**: Use `DeadlockRetryService::withDeadlockRetry()` for all high-volume DB operations in the ingestion pipeline.
- **Dead Letter Queues**: Failed ingestion jobs must be manually reviewed in the DLQ UI.

## 6. Modern PHP & Standards (PHP 8.2+)

- **Strict Typing**: All new files **MUST** use `declare(strict_types=1);`.
- **Type Hints**: All parameters and return values must be explicitly type-hinted.
- **Promoted Properties**: Use constructor property promotion for dependency injection.
- **Readonly**: Prefer `readonly` for Services and Data Transfer Objects (DTOs).

## 7. UI/UX Standards (Stitch)
- **Source of Truth**: [TSMS Dashboard] workspace in Stitch.
- **Policy**: AI provides guides/screenshots; User performs final verification of Dashboard behavior.

## 8. Definition of Done
A feature is complete ONLY when:
1. Code follows these standards (passed `pr-review-checklist.md`).
2. Multi-tenant isolation is verified (no cross-tenant leakage).
3. Verification scripts (PHPUnit or standalone tools) passed with 100% success.
4. Documentation (Quartet) synchronized.
5. PR approved by domain-specific reviewer.

---
