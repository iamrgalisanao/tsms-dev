# Spec: Report VAT-Inclusive/Exclusive Correction Coverage

## Status

**Draft — not yet approved for implementation.** Written to document a confirmed, currently-open reporting-correctness gap identified during the delivery of `specs/001-100-tenant-resilience/`, but explicitly out of that feature's scope (see "Relationship to Other Work" below). This document does not authorize any code change on its own.

## Background

Different POS terminal setups report the `vatable_sales` figure two different ways: some send it already net of VAT (matching the printed Z-reading), others send it VAT-inclusive (the VAT amount baked into the figure). `App\Services\Reports\FinanceCalculationService::deriveMetrics()` (`app/Services/Reports/FinanceCalculationService.php:187`) already detects and corrects for this — verified working correctly against real Z-reading numbers for at least one tenant (Kyukyu) — via a heuristic comparison at line 226:

```php
$rawLooksVatInclusive = abs($vatableForGross - round($candidateExVat + $rawVat, 2)) <= 0.05;
```

**The gap**: this correction only runs in the report surfaces that actually call `deriveMetrics()`. Two other surfaces read the same underlying columns directly, with no correction applied — so the same tenant's numbers can look correct on one screen and wrong on another, purely depending on which surface you're looking at. This is not a data problem; the underlying stored values are consistent, only some readers correct for VAT-inclusive reporting and some don't.

## Current Implementation Summary

**Surfaces that call `deriveMetrics()` (corrected):**
- `app/Http/Controllers/Reports/CommercialReportsController.php:1115`
- `app/Services/Reports/HourlyReportService.php:56`
- `app/Services/Reports/SalesReportDataService.php:165, 172, 263, 266` — and its downstream consumers, `DailyReportService` and `WeeklyReportService`, plus `app/Http/Controllers/Finance/SalesReportExportController.php` (all read totals produced by `SalesReportDataService`)
- `app/Http/Controllers/TransactionLogController.php:916, 1004` (grand-total rows)

**Surfaces that do NOT call `deriveMetrics()` (uncorrected, raw column reads):**
- `app/Services/DashboardService.php` — raw `SUM(vat_amount)`/`SUM(sc_vat_exempt_sales)` aggregates at lines 205-209, and other raw `SUM(gross_sales)`/`SUM(net_sales)` aggregates elsewhere in the same file (e.g. lines 36-48). Notably, this file **already contains an anomaly-detection query that flags the exact mismatch this spec is about, without correcting it**:
  ```php
  // app/Services/DashboardService.php:154-155
  ->where('vatable_sales', '>', 0)
  ->whereRaw('ABS(vatable_sales * 0.12 - vat_amount) > 0.02');
  ```
  This confirms the discrepancy is already observable in this exact code path today — the detection exists, the correction does not.
- `app/Presenters/TransactionSummaryPresenter.php:43-71` — reads raw `tax_type` rows (`VAT`, `VATABLE_SALES`, `SC_VAT_EXEMPT_SALES`) and returns them as-is (lines 43-49, 63-71), with no VAT-inclusive/exclusive normalization. This backs transaction-detail display.

## Scope

### In scope
- Deciding and implementing how `DashboardService` and `TransactionSummaryPresenter` should receive the same VAT-inclusive/exclusive correction `deriveMetrics()` already applies elsewhere — either by routing them through `deriveMetrics()` directly, or via a lighter-weight shared correction helper if the raw-SQL-aggregate performance profile of `DashboardService` can't tolerate per-row `deriveMetrics()` calls.
- Provider/tenant-aware detection and correction policy: is the VAT-inclusive/exclusive convention a property of the POS provider (like `timestamp_mode` already is, per `config/tsms.php`'s per-provider config), or does it need to be inferred per-transaction regardless of provider?
- Historical-vs-new-data behavior and mixed-rollout periods: if a provider's reporting convention changed over time, or multiple conventions coexist mid-migration, detection must not assume a clean date boundary.
- Reporting consistency across dashboard, summary, detailed transaction, and export views — the acceptance bar is that the same tenant/date range produces the same VAT-corrected totals regardless of which screen or export is used.
- Regression and reconciliation tests proving dashboard/detail/export totals agree with the already-correct CMSR/daily/weekly/hourly totals for the same data.
- Observability for detected mismatches (the existing `DashboardService:154-155` anomaly query is a candidate starting point — decide whether it becomes a monitoring signal on top of corrected data, or is retired once correction is applied everywhere).

### Explicitly out of scope
- **The Goldilocks POS terminal's VAT-subtraction defect.** This is a separate, unrelated problem: Goldilocks' POS terminal fails to subtract VAT at all on approximately 95% of its own transactions — a genuine defect in the data as submitted at the source, not a reporting-basis/display question. No correction on TSMS's reporting side can fix data that is already wrong on arrival. This needs a fix on the provider's end and is not an engineering task for TSMS. (Note: Goldilocks is also the tenant named in the *timezone* bucketing fix below — that was a different, already-resolved issue; the VAT defect is unrelated and remains open on the provider's side.)
- Any change to `specs/001-100-tenant-resilience/` or its US5 (Operational Readiness) scope — that feature's own spec explicitly states "Financial calculation/business-rule changes are out of scope except where required for retry-safe ingestion," and US5's observability scope is about ingestion/queue/circuit-breaker signals, not report correctness. This gap was identified during that feature's delivery but is intentionally tracked separately here.
- Any change to the 7-Eleven business-day-cutoff logic (`docs/specs/7-eleven-business-day-cutoff.md`) — a related but distinct business-date concern.
- Any change to the already-merged timezone/UTC bucketing fix (see below) beyond noting its relationship to this work.

## Relationship to the Timezone/UTC Bucketing Fix

Two commits, already merged to `origin/main` (not yet present on this repository's local `main` or on the `remediate-backpressure-sharding-foundation` branch — see "Local Branch Note" below), fixed a **separate and orthogonal** issue:

- `b3dab702` "Fix report timezone bucketing" — centralized business-date resolution into a `ResolvesReportBusinessDate` trait and switched the "All Tenants" breakdown and hourly report views to use raw UTC timestamps for date-bucketing, instead of letting Eloquent's untimezoned datetime cast treat UTC-stamped values as if they were already Manila-local wall-clock time.
- `1e82a5db` "Fix legacy null-payload rows being over-shifted by the timezone fix" — a follow-up fixing over-correction of historical rows (ingested before payload capture existed) for providers whose legacy data was already local time, not true UTC. Confirmed on real data for tenant 10 (Goldilocks/terminal 79): the initial fix had inflated a day's transaction count from 194 (matching the printed Z-reading) to 213. The fix adds a provider-mode fallback consulted only when payload evidence is absent, and explicitly rejects a date-based cutover in favor of a provider-convention-based one, because audited data showed no clean date boundary — June 2026 was a genuinely mixed rollout month.

**These commits touch only *when* a transaction is bucketed into a business day/hour — they do not touch `vat_amount`, `vatable_sales`, `net_sales`, or `deriveMetrics()` in any way.** The two issues are independent: a transaction can be correctly bucketed on the right business day and still show the wrong VAT-corrected total on an uncorrected report surface, or vice versa. However, the *pattern* used to resolve the timezone issue — a provider-convention-aware fallback rather than a date-based cutover, informed by real reconciliation against Z-readings — is a directly reusable precedent for how this VAT-correction gap should likely be approached (see "Required Decisions" below).

**Local branch note**: as of this document's authoring, local `main` in this repository is 7 commits behind `origin/main`, and is missing exactly the `b3dab702`/`1e82a5db` lineage — `app/Traits/ResolvesReportBusinessDate.php` does not yet exist in this working tree. This is a local git-hygiene gap, not evidence the fix is broken or reverted upstream. Anyone picking up this VAT-correction work should first sync local `main` with `origin/main` before starting, since the timezone fix's `ResolvesReportBusinessDate` trait establishes a provider-config-driven pattern worth following, not duplicating.

## Provider/Tenant-Aware Detection and Correction Policy

**Open decision** (see "Required Decisions"): should VAT-inclusive-vs-exclusive be a per-provider configuration flag (mirroring `config('tsms.intake.timestamp_mode')`'s per-provider convention already established for the timezone fix), a per-transaction heuristic detection (as `deriveMetrics()` already does via `$rawLooksVatInclusive`), or both — config as a fast-path hint, heuristic as a fallback when config is absent, matching the layered approach `1e82a5db` used for the timezone fix (payload evidence first, provider-mode fallback second, never override a confirmed classification).

## Historical vs. New Data Behavior / Mixed-Rollout Periods

Per the timezone fix's own finding, do not assume a clean date boundary exists for when a provider's VAT-reporting convention might have changed — audit real data before assuming one. If historical data must be reprocessed (see "Rollout, Backfill, and Reconciliation Risks"), the correction must be idempotent and safe to re-run, consistent with how `1e82a5db` handled reprocessing `RefreshDailyTransactionSummaries`.

## Reporting Consistency Requirement

For any given tenant and date range, `DashboardService` and `TransactionSummaryPresenter` outputs must agree with `CommercialReportsController`/`SalesReportDataService`/`HourlyReportService`/`TransactionLogController` outputs for the same underlying transactions, once this work lands. This is the acceptance bar referenced in "Acceptance Criteria."

## Regression and Reconciliation Tests

- Unit tests proving `DashboardService`'s (or its replacement/wrapped) VAT-related aggregates match `deriveMetrics()`'s corrected output for known VAT-inclusive and VAT-exclusive fixture transactions.
- Unit tests proving `TransactionSummaryPresenter`'s per-transaction VAT fields match `deriveMetrics()`'s per-transaction correction for the same fixtures.
- A cross-surface reconciliation test: given the same seeded transaction set, assert `DashboardService`'s aggregate total equals the sum of `SalesReportDataService`'s corrected per-day totals for the same range.
- Regression coverage confirming the existing `DashboardService:154-155` anomaly-detection query's behavior is preserved or deliberately superseded (decide which, per "Required Decisions").

## Observability for Detected Mismatches

Decide whether the existing anomaly-flagging query (`DashboardService:154-155`) should:
(a) remain as a live monitoring signal that now fires only on genuine source-data anomalies (like Goldilocks' provider-side defect) once the reporting-basis correction is applied everywhere, or
(b) be retired as redundant once correction is universal.
Either way, a monitoring signal for "VAT-inclusive-vs-exclusive mismatch detected at the source" is valuable to keep, since it is exactly the mechanism that would surface a future Goldilocks-style provider defect.

## Rollout, Backfill, and Financial-Reconciliation Risks

- **Backfill scope decision required**: does this correction need to be applied retroactively to cached/materialized values (e.g. `daily_transaction_summaries`, if `DashboardService` reads from a cache/summary table rather than live transactions), or is a forward-only fix (corrects all future reads, leaves historical cached aggregates as-is) acceptable? This mirrors the backfill-scope question already resolved for the timezone fix.
- **Financial-reconciliation risk**: changing `DashboardService`'s totals is user-visible and could shift previously-reported numbers for a tenant that was being under- or over-corrected — this needs sign-off from whoever owns financial-reporting accuracy expectations before rollout, not a silent deploy.
- **Consistency-tolerance decision required**: the existing anomaly query uses a `>0.02` absolute-peso tolerance at the per-row level; decide whether the same or a different tolerance applies to aggregate-level cross-surface reconciliation tests.

## Acceptance Criteria

- [ ] `DashboardService`'s VAT-related aggregates and `TransactionSummaryPresenter`'s per-transaction VAT fields apply the same correction logic `deriveMetrics()` already applies to CMSR/daily/weekly/hourly/export/transaction-log surfaces.
- [ ] For any tenant and date range, dashboard totals reconcile with CMSR/report totals for the same transactions, within the agreed tolerance.
- [ ] Regression tests cover both VAT-inclusive and VAT-exclusive source conventions, plus at least one mixed/ambiguous-provider fixture case.
- [ ] The Goldilocks provider-side VAT-subtraction defect remains explicitly untouched by this work and is separately communicated/tracked as a provider issue, not folded into this fix's scope.
- [ ] No change is made to `specs/001-100-tenant-resilience/` scope, US5, or the timezone/UTC bucketing fix.

## Required Decisions (unresolved, blocking implementation)

1. **Correction methodology for bypass paths** — route `DashboardService`/`TransactionSummaryPresenter` through `deriveMetrics()` directly, or build a lighter-weight shared correction helper for raw-aggregate performance reasons?
2. **Provider/tenant scope** — config-driven (per-provider flag), heuristic-only (per-transaction, as today), or layered (config first, heuristic fallback)?
3. **Historical cutover** — is there a clean date boundary at all, or must detection be per-row/per-payload regardless of date (per the timezone fix's own precedent of rejecting a date-based cutover)?
4. **Backfill scope** — recompute existing cached/materialized aggregates, or forward-only?
5. **Consistency tolerance** — what variance between dashboard and detailed-report totals counts as a regression, and is the existing `>0.02`-peso per-row tolerance the right basis for an aggregate-level check?
6. **Anomaly-query fate** — keep `DashboardService:154-155` as a live monitoring signal post-fix, or retire it?

## Cross-References

- `specs/001-100-tenant-resilience/plan.md` — "Feature Status" section notes this gap was identified during that feature's delivery and is tracked here, outside that feature's scope.
- `docs/specs/7-eleven-business-day-cutoff.md` — sibling lightweight spec doc, same `docs/specs/*.md` convention, a related but distinct business-date concern.
- Timezone/UTC bucketing fix: commits `b3dab702`, `1e82a5db` (merged to `origin/main`).
