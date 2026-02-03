# Phase 1 — Summary for Phase 2 Design

Purpose: capture the authoritative Phase‑1 behaviors, data contracts, and known limitations that Phase‑2 must preserve or improve.

## 1) Phase1 Data Flow & Transaction Logic
- Ingestion entrypoints: official envelope and legacy batch endpoints implemented in [app/Http/Controllers/API/V1/TransactionController.php](app/Http/Controllers/API/V1/TransactionController.php).
- Canonical checksum algorithm (transaction-level and submission-level) is implemented in [app/Services/PayloadChecksumService.php](app/Services/PayloadChecksumService.php). Phase‑2 MUST use the same canonicalization (ksort assoc arrays, cast monetary keys, JSON encode, SHA‑256) to remain compatible.
- Idempotency: submissions use `submission_uuid` + `TransactionSubmission` records; per-transaction dedupe checks are enforced in the controller flow.
- Batch rules: batches are homogeneous (single tenant_id + terminal_id) and include per-transaction checksums; forwarding uses a computed `batch_checksum` when grouping transactions for WebApp forwarding.
- Jobs: `ProcessTransactionJob` handles async processing; queue sharding by `tenant_id % N` is used when dispatching.

## 2) System Architecture (Phase1 view)
- Components: API (Laravel controllers), Worker pool (queue jobs), Redis cache (locks, tenant_version), RDBMS (transactions, adjustments, taxes, submission events), Outbound WebApp forwarder (HTTP client + circuit breaker).
- Feature flags and config-driven behaviors: forwarding toggle, capture-only mode, circuit-breaker settings live in `config('tsms.*')` and feature flags are enforced by forwarding service.

## 3) Module Responsibilities & Boundaries
- Ingestion & validation: [TransactionController.php](app/Http/Controllers/API/V1/TransactionController.php) — envelope validation, checksum validation, idempotency, write transactions and child rows.
- Checksum canonicalization: [PayloadChecksumService.php](app/Services/PayloadChecksumService.php) — authoritative algorithm for transaction and submission checksums.
- Business model + small calculation helpers: [app/Models/Transaction.php](app/Models/Transaction.php) — net/gross accessors, `otherTaxSum()`, receipt normalization, void/refund helpers.
- Forwarding & retry logic: [app/Services/WebAppForwardingService.php](app/Services/WebAppForwardingService.php) — build batch envelope, compute forwarding checksums (note: explicit `sc_vat_exempt_sales` is included in payload but excluded from checksum), tenant circuit-breaker, `WebappTransactionForward` persistence.
- Onboarding / provider contract: see [/_md/POS_provider_integration_checklist.md](/_md/POS_provider_integration_checklist.md) and [/_md/WEBAPP_FORWARDING_GUIDELINES.md](/_md/WEBAPP_FORWARDING_GUIDELINES.md).

## 4) UI & Reporting Behavior
- WebApp forwarding provides an envelope (schema v2.0) for single or batch sends; forwarding records store request/response and attempt counts. See forwarding doc and service for payload shape.
- If forwarding disabled or capture-only, payloads may be recorded but not sent; this causes divergent behavior between staging and production unless flags are aligned.

## 5) Phase1 Outstanding Issues & Technical Debt
- No single, central commercial-calculation service: business math (discounts, fees, net sales) is implemented across model accessors and denormalized DB columns in `Transaction` and ingestion logic — this must be stabilized for Phase‑2.
- Receipt normalization complexity: `normalizeReceiptNo()` rules and fallback variants are used in void flows; inconsistent receipt formatting from POS devices causes lookup ambiguity.
- Checksum semantics: slight differences in which keys are included/excluded for forwarding checksum vs ingestion checksum (e.g., `sc_vat_exempt_sales`) — Phase‑2 must preserve these differences or intentionally unify them with migration/compatibility plans.
- Forwarding feature‑flag divergence: forwarding disabled in staging by default; turning it on without readiness may create unexpected traffic and failures.

## 6) Phase1 Acceptance / UAT Summary
- Test coverage (unit + feature) exists for key flows: voiding, checksum, ingestion, forwarding (see PHPUnit test references and `.phpunit.result.cache`). Relevant tests live under `tests/Feature` and `tests/Unit`.
- UAT & onboarding guidance: [/_md/POS_provider_integration_checklist.md](/_md/POS_provider_integration_checklist.md) documents provider onboarding steps and acceptance criteria.

## 7) Phase1 Tenant Behavior & Constraints
- Batches must not mix `(tenant_id, terminal_id)` pairs; forwarding groups by tenant:terminal.
- Tenant-level circuit-breaker and tenant observer track failed forwarding attempts and can isolate noisy tenants without tripping global breaker.
- Sharding: job dispatch uses per-tenant hashing to distribute load (tenant_id modulo shard count).

## 4 Critical Items (must be preserved in Phase 2)
1. Commercial calculation logic (where it lives):
   - Core math is implemented in `Transaction` model accessors (net/gross, `otherTaxSum()`) and in the ingestion aggregation logic that writes denormalized columns. Files: [app/Models/Transaction.php](app/Models/Transaction.php) and ingestion controller flows in [app/Http/Controllers/API/V1/TransactionController.php](app/Http/Controllers/API/V1/TransactionController.php).
   - Phase‑2 action: extract a single, well-tested `CommercialCalculator` service that reproduces existing accessors' results and add end‑to‑end tests using representative payloads (use examples in `corrected_payload*.json`).

2. Transaction state behavior (authoritative states & transitions):
   - Validation statuses and job statuses are defined in `Transaction` model constants and enforced in ingestion and job flows. See [app/Models/Transaction.php](app/Models/Transaction.php) and controller flows in [TransactionController.php](app/Http/Controllers/API/V1/TransactionController.php).
   - Phase‑2 action: mirror constants and lifecycle in Phase‑2 services; include tests for: RECEIVED → QUEUED → PROCESSING → COMPLETED, and VOID/REFUND constraints (no void if refunds exist; void limited to same business day by timezone config).

3. POS file/envelope format rules (exact contract):
   - Required envelope keys and shape are documented in [/_md/POS_provider_integration_checklist.md](/_md/POS_provider_integration_checklist.md) and the forwarding examples in [/_md/WEBAPP_FORWARDING_GUIDELINES.md](/_md/WEBAPP_FORWARDING_GUIDELINES.md).
   - Adjustments and taxes must include expected types (forwarder enforces required adjustment and tax types); canonical checksum requires sorted keys & specific monetary casts. Phase‑2 must keep the same JSON canonicalization to compute identical SHA‑256 checksums.

4. Phase1 system limitations to call out immediately:
   - Business math is spread across model accessors (risk of divergence).
   - Receipt lookup ambiguity (normalization + fallback heuristics) may cause false negatives during POS voids.
   - Forwarding behavior is feature‑flagged → test vs prod divergence risk.
   - No authoritative tenant roster file discovered here — onboarding records may be external to the repo.

## Recommended Phase2 Next Steps (practical and minimal)
1. Implement a `CommercialCalculator` service that wraps current accessors and add golden‑file tests (use `corrected_payload*.json` example payloads).
2. Preserve the `PayloadChecksumService` canonicalization exactly in Phase‑2; add unit tests reproducing known example checksums.
3. Canonicalize receipt normalization logic (single implementation) and add inbound acceptance tests for void flows (include case‑variants: upper/lower/trim/unicode).
4. Add an explicit tenant roster (CSV/DB seed) and a migration plan so Phase‑2 can enforce tenant-level contracts and run tenant breakers in preprod safely.

---
References (authoritative source files):
- [app/Services/PayloadChecksumService.php](app/Services/PayloadChecksumService.php)
- [app/Http/Controllers/API/V1/TransactionController.php](app/Http/Controllers/API/V1/TransactionController.php)
- [app/Services/WebAppForwardingService.php](app/Services/WebAppForwardingService.php)
- [app/Models/Transaction.php](app/Models/Transaction.php)
- [/_md/POS_provider_integration_checklist.md](/_md/POS_provider_integration_checklist.md)
- [/_md/WEBAPP_FORWARDING_GUIDELINES.md](/_md/WEBAPP_FORWARDING_GUIDELINES.md)
- [/_md/POS_TERMINAL_DATA_DICTIONARY.md](/_md/POS_TERMINAL_DATA_DICTIONARY.md)
