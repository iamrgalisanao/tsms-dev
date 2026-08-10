# Baseline Verification — `002-backfill-transaction-taxes`

**Date**: 2026-08-10 · **Branch**: `002-backfill-transaction-taxes` · **Runs on the main thread per `prompt-library.md` item 4**

**Verification state at the time this run was performed**: `HEAD 07b29425` (docs-only, spec/tasks/decision-memo commits; zero `app/` files). This document and `baseline-failures.txt` were then committed on top as `a3e7629e` — the run itself predates that commit, as it must, since a baseline can't cite the hash of the commit still being written.

## Commands

```bash
DB_DATABASE=tsms_db_test php artisan migrate:fresh --env=testing --force
DB_DATABASE=tsms_db_test php artisan test
```

No files modified. Test database is `tsms_db_test`, dropped and rebuilt fresh before the run.

## Results

**Migrations**: 100% clean. All migrations report `DONE`, including `2026_08_07_000001_add_official_ingestion_short_path_indexes` — the migration at the center of the staging incident — confirming its idempotency fix holds on a fresh build.

**Test suite**: `112 failed, 1 deprecated, 2 warnings, 3 risky, 5 skipped, 461 passed` (3049 assertions, 62.86s), across 35 failing test classes.

## Classification

**Zero failures touch this feature's scope.** No failing test references `transaction_taxes`, `otherTaxSum()`, `TaxReconstructionService`, or any backfill command/service — expected, since no implementation code exists yet on this branch (docs/spec only, 8 commits, no `app/` files touched). Every failure below is either a **pre-existing failure** or an **environment issue**; none is a **feature-relevant blocker**, by definition.

Root-caused two clusters covering roughly a third of the failures; the remainder were not individually root-caused (out of scope for a baseline snapshot — this document exists to let post-implementation runs be diffed against it, not to fix the existing suite):

| Cluster | Count (approx) | Root cause | Classification |
|---|---|---|---|
| `Tests\Feature\TransactionPipeline\TransactionValidationTest` | 21 `TypeError`s | Test calls `TransactionValidationService::validateTransaction()` with an array; the method signature requires `App\Models\Transaction`. Test predates a refactor of `validateTransaction()` into its current passive-no-op form (the same method discussed extensively in this feature's `other-tax-semantics.md` — confirmed at `TransactionValidationService.php:518`) and was never updated. | **Pre-existing failure** (test/code drift) |
| `Auth\AuthenticationTest`, `Auth\RateLimitingTest`, `PosTerminalDirectNotificationTest`, `PosTerminalNotificationTest` | 8 `UniqueConstraintViolationException`s | Duplicate-key collisions (`test@example.com`, terminal serials) across test methods within a single run. Root cause: `tests/TestCase.php:11` uses `RefreshDatabase` globally; `AuthenticationTest.php:17` additionally declares `DatabaseTransactions`. Laravel's `setUpTraits()` fires both reset strategies independently when both are present on a class hierarchy — a known anti-pattern that produces exactly this failure shape. | **Environment issue** (test-isolation defect in the suite itself) |
| `TransactionProcessingTest` (2 of its failures) | `QueryException` — `tenants_company_id_foreign` FK violation on tenant insert | Factory/seed ordering: a `Tenant` is created without an antecedent `Company` row. | **Pre-existing failure** (factory bug) |
| `WebappApi\ReportsTest`, `WebappApi\TransactionsTest` | 3 failures, all `401` instead of `200` | Consistent with `webapp_api.enabled` (or its token/ability config) not being set for the `testing` environment — the feature-flagged `v1/webapp/*` surface `CLAUDE.md` describes is conditionally registered. | **Environment issue** (config gap in `.env.testing` or `config/webapp_api.php` test defaults) |
| Remaining ~30 test classes (`TextFormatParserTest`, `DashboardPerformanceTest`, `SecurityReportControllerTest`, `Module2Test`, `RbacAuditTest`, `TokenIntrospectionTest`, `StoreOfficialResilienceTest`, `IngestionQuarantine*Test`, `ExampleTest`, etc.) | ~65 failures | Not individually root-caused. Span unrelated domains (security reporting, RBAC audit, text-format parsing, dashboard performance, ingestion quarantine, token introspection) with no thematic connection to tax handling. | **Pre-existing failures / environment issues** (unclassified in detail; full list preserved below for diffing) |

**CI corroboration**: `.github/workflows/ci.yml`'s `laravel` job runs an equivalent fresh MySQL 8.0 service container with the same migrate-then-test sequence this baseline used locally. The failure patterns found (test/code signature drift, a test-isolation anti-pattern) are properties of the committed test suite itself, not of this local machine — they would reproduce in CI on this branch today, independent of this feature.

## Accepted Baseline

**461 passing / 112 failing, on an unmodified branch, is the accepted baseline for this feature.** The number is high, but every investigated failure is explainable, pre-existing, and outside this feature's boundary. Full failure list committed alongside this document as [baseline-failures.txt](baseline-failures.txt) for exact-diff comparison once implementation begins — a newly-failing test not in that list is a regression introduced by this feature; a test on that list flipping to green is neutral (unless it's in this feature's own scope, in which case it's worth understanding why).

**Not this feature's job to fix**: none of these 112 failures block implementing the tax backfill, and per this repo's minimal-diff discipline, none should be touched as a side effect of this feature's slices. The `AuthenticationTest`/`DatabaseTransactions` trait collision and the `TransactionValidationTest` signature drift are both cheap, well-understood fixes if anyone wants to pick them up separately — flagging here rather than fixing, since neither is in scope.

## Decision

**`BASELINE_RECORDED`**
