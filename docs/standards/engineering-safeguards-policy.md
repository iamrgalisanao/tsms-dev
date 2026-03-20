# Engineering Safeguards Policy: TSMS

**Version:** 1.0  
**Status:** Mandatory Enforcement (CI/CD)

---

## 1. Purpose
This policy defines automated safeguards to prevent high-risk errors (P.I.I. exposure and Tenant isolation leaks) in the TSMS (Transaction Management System). These gates are enforced automatically in the CI pipeline.

---

## 2. P.I.I. Leak Detection Guardrail

### 2.1 Definition of P.I.I.
Includes any data identifying a customer directly or indirectly (RA 10173).

### 2.2 P.I.I. Denylist Fields
The CI scanner flags usage of these fields in logs, browser storage, or test fixtures:
- `first_name`, `last_name`, `middle_name`, `full_name`, `birth_date`, `dob`
- `address`, `phone`, `email`, `customer_code`, `national_id`
- `senior_id`, `pwd_id`, `loyalty_id`, `credit_card_no`, `receipt_no`

### 2.3 Forbidden Practices
- **Logging PII**: Forbidden. Use `SafeAuditLogger` class for data-safe auditing.
- **Browser Storage**: PII must not be stored in `localStorage`, `sessionStorage`, or `cookies`.
- **Test Data**: Real customer or terminal data is strictly prohibited in the codebase.
- **Secrets in Code**: Never commit `.env` keys, passwords, or API tokens.

---

## 3. Tenant Data Leak Prevention

### 3.1 Global Isolation
All tenant-owned models **MUST** use the `BelongsToTenant` trait and global query scope.

### 3.2 Job & Context Security
Every background job **MUST** carry and re-authorize:
- `tenant_id`
- `terminal_id`
- **Submission UUID** (for audit correlation)

### 3.3 Safe Exports & Raw Queries
- Reports and Exports must explicitly append `AND tenant_id = ?` to all raw SQL queries.
- Mass Assignment: Sensitive fields (`tenant_id`, `terminal_id`) must never be in the `$fillable` array.

---

## 4. CI Pipeline Verification
The following checks must pass before merging to any protected branch:

1.  **P.I.I. Leak Scanner**: Fails CI on denylist matches in logs or non-secure code paths.
2.  **Tenant Guardrail Scanner**: Fails CI on unscoped queries (`Model::all()`, `DB::statement()` without `tenant_id`).
3.  **Integrity Tests**: 100% pass on checksum fallback logic (V2.1/V2.0).
4.  **Dependency Scanner**: Fails on known critical vulnerabilities in PHP (Composer) or JS (NPM) packages.

---

## 5. System Hygiene
- **Dead Code Removal**: Standard `TODO` or commented-out snippets must be removed before push.
- **Orphaned Models**: Every new model (Transactions, Voids, Refunds) must be attributed to a tenant.
- **Exception Masking**: Production errors must never leak stack traces containing PII or database schemas.

---

## 6. Governance
No agent or engineer may bypass these safeguards. Disabling guardrails requires a formal Security Review and Architecture approval.
