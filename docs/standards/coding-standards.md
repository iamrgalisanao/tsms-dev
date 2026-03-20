# Coding Standards: TSMS

**Version:** 1.2 (High-Governance Edition)  
**Applies to:** All development and integration for the TSMS Ingestion Engine.

All changes must comply with:
- [security-hygiene-guardrails.md](../security/security-hygiene-guardrails.md)
- [code-review-standards.md](code-review-standards.md)
- [pr-review-checklist.md](pr-review-checklist.md)
- [operational_protocol.md](../../operational_protocol.md)

---

## 0. Core Engineering Principles

1. **Transaction Integrity First**: Code affecting financial calculations, checksums, or transaction status must be reviewed with extra rigor.
2. **Privacy by Design**: Protect Customer PII (Personal Identifiable Information) by default (RA 10173).
3. **Tenant Isolation**: Absolute row-level isolation via global scopes and `BelongsToTenant` trait.
4. **Least Privilege**: POS-to-API communication must use minimal necessary abilities (Sanctum `pos_api` guard).
5. **Traceability**: All state changes (Voids, Refunds) must be attributable and logged.
6. **Deterministic Behavior**: Financial logic must avoid floating-point ambiguity. Use `decimal(15,2)` in DB and precise rounding in PHP.

## 1. Clean Code & Scalability (SOLID)

- **S (Single Responsibility)**: Controllers should ONLY handle request flow. Business logic MUST reside in dedicated Service classes.
- **O (Open/Closed)**: Logic like `PayloadChecksumService` must be open for extension (e.g., adding V2.2) but closed for core modification.
- **L (Liskov Substitution)**: Model traits like `BelongsToTenant` must be interchangeable and non-destructive.
- **I (Interface Segregation)**: Services should provide narrow, focused methods (e.g., `validateWithVersion`) rather than monolithic ones.
- **D (Dependency Inversion)**: Favor Constructor Injection (`readonly` properties) over facade reliance or manual instantiation.

## 2. Naming & Domain Language

- **General**: Clear, descriptive names. `pii_` (PII), `tx_` (Transaction), `audit_` (Audit) prefixes encouraged.
- **Transaction Rules**: Must use V2.1 Payload terminology (`gross_sales`, `vat_amount`, `sc_vat_exempt_sales`).
- **Functions**: Read as intent: `PayloadChecksumService::validateWithVersion()`.

## 3. Business Logic separation

- **Mandate**: Transaction rules (checksum validation, ingestion logic, tax calculations) MUST NEVER reside in UI (React) or Controllers.
- **Location**: Use dedicated services in `app/Services/`.
- **Patterns**: Use the **Action** or **Service** pattern to encapsulate complex multi-step processes (e.g., `TransactionIngestService`).

## 4. Transaction Workflow & Auditability

### 4.1 State Machines
Explicit states: `PENDING` → `VALID` / `INVALID` → `COMPLETED` / `VOIDED` / `REFUNDED`.

### 4.2 Dual-Checksum Integrity
Every ingestion must trigger:
1. V2.1 string-based canonicalization.
2. V2.0 float-based fallback (for legacy).

### 4.3 Immutability
Once validated, financial data is **immutable**. Changes require explicit Void/Refund events.

## 5. Multi-Tenant Safety (Non-Negotiable)

### 5.1 Global Scopes
- All models representing tenant data MUST use `BelongsToTenant`.
- Raw `DB::table` calls MUST include manual `where('tenant_id', ...)` scoping.

### 5.2 Context Persistence
- Background jobs must re-establish `tenant_id` context before execution.

## 6. Performance & Concurrency

- **Deadlock Handling**: High-volume DB writes must use `DeadlockRetryService::withDeadlockRetry()`.
- **Job Sharded Fairness**: Route jobs based on `tenant_id % 8` to sharded Redis queues.
- **Eager Loading**: Prevent N+1 queries by eager loading relevant relations (e.g., `terminal`, `tenant`).

## 7. Modern PHP (8.2+) Standards

- **Strict Typing**: All new files **MUST** use `declare(strict_types=1);`.
- **Type Safety**: Avoid `mixed` types; use union types or generics where applicable.
- **Readonly Classes**: Use `final readonly class` for all stateless Services.

## 8. Definition of Done
A feature is complete ONLY when:
1. Code follows these standards (passed `pr-review-checklist.md`).
2. Multi-tenant isolation is verified.
3. Verification scripts passed with 100% success.
4. Documentation (Quartet) synchronized.
5. PR approved via `code-review-standards.md` gates.
