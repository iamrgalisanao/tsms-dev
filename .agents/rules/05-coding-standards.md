# 05 Coding Standards

## Core Engineering Principles
- **Transaction Integrity First**: Rigorous review for code affecting financial calculations, checksums, or status.
- **Privacy by Design**: Protect Customer PII by default (RA 10173).
- **Tenant Isolation**: Absolute row-level isolation via global scopes and `BelongsToTenant` trait.
- **Traceability**: All state changes (Voids, Refunds) must be attributable and logged.
- **Deterministic Behavior**: Use `decimal(15,2)` in DB and precise rounding in PHP. Avoid floating-point ambiguity.

## Architectural Patterns (SOLID)
- **Service Pattern**: Business logic MUST reside in `final readonly` Service classes in `app/Services/`. Controllers only handle flow.
- **Dependency Inversion**: Favor Constructor Injection over facade reliance.
- **Strict Typing**: All new files MUST use `declare(strict_types=1);`.

## Multi-Tenant Safety
- **Global Scopes**: All tenant models MUST use `BelongsToTenant`.
- **Manual Scoping**: Raw `DB::table` calls MUST include manual `where('tenant_id', ...)` scoping.
- **Context Persistence**: Background jobs must re-establish `tenant_id` context.

## Transaction Workflow
- **State Machine**: `PENDING` -> `VALID`/`INVALID` -> `COMPLETED`/`VOIDED`/`REFUNDED`.
- **Immutability**: Once validated, financial data is immutable.
- **Dual-Checksum**: Trigger V2.1 string canonicalization and V2.0 float fallback.

## Performance & Concurrency
- **Deadlock Handling**: Use `DeadlockRetryService::withDeadlockRetry()`.
- **Queue Fairness**: Route jobs based on `tenant_id % 8`.

## Definition of Done
1. Follows standards and passed `pr-review-checklist.md`.
2. Multi-tenant isolation verified.
3. Verification scripts passed with 100% success.
4. Documentation synchronized.
