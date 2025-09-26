## Transaction Ingestion - Phased Implementation Plan

Purpose
-------
This document describes a risk-aware, phased plan to ensure TX ingestion persists child records (taxes, adjustments, jobs, validations) reliably and consistently using the canonical foreign-key (`transaction_pk` => `transactions.id`). It is intended for developers, QA, and the Product Owner (PO) to review and approve before work begins.

Audience
--------
- PO (final approval)
- Developers (implementation)
- QA/DevOps (verification & rollout)
- DBA/Platform (migration & monitoring)

Context & Motivation
--------------------
The repository currently creates `transactions` rows from incoming POS submissions and then inserts child rows for taxes and adjustments. During a schema migration the codebase is partially transitioned from using a UUID `transaction_id` on child tables to using `transaction_pk` (numeric FK). Some ingestion code still creates child rows with a `transaction_id` attribute while models and relations expect `transaction_pk`. That mismatch risks orphaned child rows, inconsistent joins, and confusion.

Goal
----
Make ingestion produce child rows linked by `transaction_pk` consistently, with minimal service disruption, and provide a clear follow-up plan to canonicalize schema and naming.

Assumptions
-----------
- You can deploy code and run DB migrations/backfills in dev/staging/production.
- The migration that adds/backfills `transaction_pk` exists in the repo (e.g., `2025_08_13_000012_add_transaction_pk_foreign_keys.php`).
- Background workers (queues/Horizon) can be restarted during rollout.

Acceptance Criteria
-------------------
- New ingestion events produce `transaction_taxes` and `transaction_adjustments` rows with `transaction_pk = transactions.id` (no NULLs for new rows).
- Submission checksum validation, idempotency, and job dispatching behavior remain unchanged.
- Tests verifying the linkage pass in CI.
- No production increase in queue failures or forwarding errors after canary period.

High-level risks & mitigations
-----------------------------
- Orphaned child rows if controller creates child rows with `transaction_id` while models expect `transaction_pk`.
  - Mitigation: Update ingestion to use relation-based creation so Eloquent sets `transaction_pk` automatically.
- Migrations/backfills not run in all environments.
  - Mitigation: Verify migration status and backfill prior to changing code that relies on `transaction_pk`.
- Ambiguous column naming (`transaction_id` used for different semantics).
  - Mitigation: Medium-term plan to standardize and rename legacy columns.

Phased plan (detailed)
----------------------

PHASE 0 — Pre-work & verification (owners: Dev, DBA)
- Verify migration status in each environment:
  - `php artisan migrate:status` and check for the `transaction_pk` backfill migration.
- Baseline orphan check:
  - `SELECT COUNT(*) FROM transaction_taxes WHERE transaction_pk IS NULL;`
  - `SELECT COUNT(*) FROM transaction_adjustments WHERE transaction_pk IS NULL;`
- Confirm DB backups/snapshots are available before any significant migration.

PHASE 1 — Immediate fix (owners: Developer, QA)
Objective: Stop creating new orphan/mis-linked child rows.

What to change (low-risk):
- Replace static child-table creation calls that pass `'transaction_id' => $transaction->transaction_id` with relation-based creation using the saved Transaction model:
  - Change `\App\Models\TransactionAdjustment::create(['transaction_id' => $transaction->transaction_id, ...])` to `$transaction->adjustments()->create([...])`.
  - Change `\App\Models\TransactionTax::create(['transaction_id' => $transaction->transaction_id, ...])` to `$transaction->taxes()->create([...])`.

Why: Eloquent relation create will set the correct FK (`transaction_pk`) regardless of column naming and avoids `$fillable` mismatch problems.

Steps:
1. Implement code changes in `app/Http/Controllers/API/V1/TransactionController.php` (both `processAdjustmentsAndTaxes()` and inline processing in `storeOfficial`).
2. Add a feature test that submits a canonical sample (or uses controller invocation) and asserts `transaction_pk = transactions.id` for created child rows.
3. Run CI and the ingestion tests locally: `php artisan test --filter=TransactionIngestionTest`.
4. Deploy to staging. Run a light smoke test (post canonical payload and check DB).
5. Canary to production (small rollout / monitoring window) and monitor metrics/logs for 24–72 hours.

Verification (PHASE 1):
- SQL checks after deploy:
  - `SELECT COUNT(*) FROM transaction_taxes WHERE transaction_pk IS NULL AND created_at >= NOW() - INTERVAL 1 HOUR;`
  - `SELECT COUNT(*) FROM transaction_adjustments WHERE transaction_pk IS NULL AND created_at >= NOW() - INTERVAL 1 HOUR;`
- CI tests: ingestion-related tests pass.
- Smoke test: dispatch canonical `storeOfficial` submission and validate DB rows and job dispatch.

Rollback plan (PHASE 1):
- Revert the small commit and redeploy. No DB schema changes in this phase, so rollback is straightforward.

PHASE 2 — Verify & reconcile (owners: Dev, QA, Ops)
- Monitor for 24–72 hours after production canary.
- Check orphan counts vs previous baseline.
- Run a broader set of ingestion and forwarding tests.

PHASE 3 — Medium-term canonicalization (owners: Dev, DBA, PO)
Objective: Remove ambiguity and adopt a single canonical FK naming (`transaction_pk`).

Steps:
1. Repo audit: list occurrences of child row creation that use `transaction_id` instead of relations or `transaction_pk`.
2. Incrementally update all such sites to use relation-create or explicit `transaction_pk` values.
3. Ensure migrations that add `transaction_pk` and FKs have been executed in all environments.
4. Add documentation describing canonical conventions (e.g., child tables link by `transaction_pk` -> `transactions.id`).
5. Optional: rename/drop legacy columns in a carefully staged migration (two-step rename/backfill then drop after observation window).

PHASE 4 — Clean-up & finalize (owners: Dev, DBA)
- Decide whether to keep legacy `transaction_id` on child tables for audit. If not needed, rename to `transaction_uuid` then drop the legacy column after a monitoring window.

Implementation checklist (developer)
----------------------------------
- Edit ingestion code to use relation-based child creation.
- Add/adjust tests to validate `transaction_pk` linkage.
- Run test suite and static analysis.
- Deploy to staging; run smoke tests and DB checks.
- Canary deploy to production with monitoring.

Useful commands & SQL for verification
-------------------------------------
- Migration status:
  - `php artisan migrate:status`
- Run tests:
  - `php artisan test --filter=TransactionIngestionTest`
- Orphan checks:
  - `SELECT COUNT(*) FROM transaction_taxes WHERE transaction_pk IS NULL;`
  - `SELECT COUNT(*) FROM transaction_adjustments WHERE transaction_pk IS NULL;`
- Spot-check mapping for a recent transaction:
  - `SELECT t.id, t.transaction_id, tt.id AS tax_id, tt.transaction_pk FROM transactions t JOIN transaction_taxes tt ON tt.transaction_pk = t.id WHERE t.created_at > NOW() - INTERVAL 1 DAY LIMIT 20;`

Monitoring & success criteria (post-deploy)
-----------------------------------------
- No increase in `transaction-processing` queue failures.
- No new child rows with NULL `transaction_pk` for newly ingested transactions.
- Forwarding and validation metrics remain within normal thresholds.

Rollback & recovery (if migration/backfill needed)
-------------------------------------------------
- If a migration/backfill is required and causes issues, restore DB from pre-change backup (have backup/snapshot ready before any destructive migration).
- If code change causes immediate problems, revert the code change and redeploy; because the first phase avoids schema changes, a revert should be safe.

Handover to PO (action requested)
---------------------------------
PO, please review this plan and respond with one of the following:
- "approve" — I will implement PHASE 1 (apply the minimal code patch), run tests, and deploy to staging for QA.
- "approve + apply PHASE 3 now" — I will include the broader audit & canonicalization work in the same cycle (longer rollout window).
- "request changes" — list any changes you want to this plan (scope, timing, or owners).

When approved I will: implement the PHASE 1 patch, run the ingestion tests, and report results with SQL verification and a short deployment checklist for staging and production.

Revision history
----------------
- 2025-09-25 — Initial plan authored and submitted for PO review.
