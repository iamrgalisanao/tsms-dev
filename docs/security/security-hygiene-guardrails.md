# Security & Hygiene Guardrails: TSMS

## 1. Multi-Tenant Isolation (Absolute Rule)
- **Global Scope**: All tenant-owned models MUST use `TenantScope` and the `BelongsToTenant` trait.
- **Background Jobs**: Every job MUST carry and re-authorize **tenant_id** context.
- **CI Enforcement**: The **Tenant Guardrail Scanner** will fail any PR containing unscoped queries on transactional models.

## 2. P.I.I. (Personally Identifiable Information)
- **Definition**: RA 10173 compliant. Includes customer info, senior/PWD IDs, and transaction details.
- **P.I.I. Denylist (CI Scanning)**: Usage of the following fields in unsafe contexts (logs, UI state, alerts) is forbidden:
    `first_name`, `last_name`, `middle_name`, `full_name`, `birth_date`, `address`, `phone`, `email`, `customer_code`, `senior_id`, `pwd_id`, `loyalty_id`, `credit_card_no`, `receipt_no`.
- **Encryption**: Demographics and sensitive identification numbers must be encrypted at rest (AES-256).

## 3. Forbidden Practices (Zero Tolerance)
1. **Logging PII**: Never log denylisted fields. Use `SafeAuditLogger` for data-safe auditing.
2. **Browser Storage**: PII MUST NEVER be stored in `localStorage`, `sessionStorage`, or `cookies`.
3. **Test Fixtures**: Real customer or terminal data is strictly prohibited in `tests/`, `factories/`, or `seeders/`.
4. **Third-Party Analytics**: PII must never be sent to external analytics or crash report platforms.

## 4. Automated CI Safety Gates
- **P.I.I. Leak Guardrail**: Compares code against the P.I.I. Denylist. Fails CI on positive matches in logs or non-secure storage.
- **Tenant Leak Guardrail**: Validates 100% tenant-scoped query coverage. Fails CI on `Model::all()` or raw SQL without `tenant_id`.
- **Failure Protocol**: 
    - CI FAIL -> Merge Blocked -> Mandatory Security Review -> Documented Justification.

## 5. System Hygiene
- **Payload Integrity**: Dual-layer checksum (V2.1/V2.0) is the non-negotiable entry gate.
- **Dead Code**: Remove all "TODO" or commented-out transactional snippets before push.
- **Orphaned Models**: Every new model (Transactions, Taxes, Adjustments) must be attributed to a tenant.
