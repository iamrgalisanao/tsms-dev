# Spec: Report VAT-Inclusive/Exclusive Correction Coverage

## Status

**Draft — not yet approved for implementation.** Written to document a confirmed, currently-open reporting-correctness gap identified during the delivery of `specs/001-100-tenant-resilience/`, but explicitly out of that feature's scope (see "Relationship to Other Work" below). This document does not authorize any code change on its own.

This spec now explicitly covers **two related but distinct problem tracks** under one reporting-correctness umbrella — see "Problem Tracks" below. They share one architectural root cause and one target architecture, but have separate scope and separate acceptance criteria so neither is accidentally solved as a single vague "fix VAT" task.

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

## Related Gap: Tax-Type Alias Consistency

During verification of this spec, an additional cross-surface inconsistency was identified — related to, but distinct from, the VAT-inclusive/exclusive basis correction above.

Several newer reporting services recognize a broad set of `tax_type` aliases for VAT-exempt classification:
- `app/Services/Reports/FinanceCalculationService.php:12-15,54-57`
- `app/Services/Reports/SalesReportDataService.php:222-223`
- `app/Console/Commands/RefreshDailyTransactionSummaries.php:120`
- `app/Exports/TransactionLogsExport.php:192-195,207-210`

These recognize `SC_VAT_EXEMPT_SALES`, `VAT_EXEMPT_SALES`, `VATEXEMPT_SALES`, `VAT-EXEMPT`, `EXEMPT`, `VATEXEMPT` (and, in some of these files, `ZERO_RATED`/`NON-VAT`/`NON_VAT`/`ZERO-RATED`).

Other surfaces recognize only a narrower subset:
- `app/Http/Controllers/API/V1/TransactionController.php` (ingestion paths, `batchStore()`/`storeOfficial()`) — `SC_VAT_EXEMPT_SALES` only
- `app/Models/Transaction.php::otherTaxSum()` — `SC_VAT_EXEMPT_SALES` only
- `app/Presenters/TransactionSummaryPresenter.php` — `SC_VAT_EXEMPT_SALES` only
- `app/Services/TransactionValidationService.php::getTaxBuckets()` — a third, partially-overlapping set (`SC_VAT_EXEMPT_SALES`, `VAT_EXEMPT_SALES`, `VAT_EXEMPT`), missing `VATEXEMPT`/`EXEMPT`/`VAT-EXEMPT`

**Risk**: the same source transaction, carrying one of the narrower-set-only alias strings (e.g. `VATEXEMPT` with no underscore), can be classified differently depending on whether it is read at ingestion time, in `Transaction::otherTaxSum()`, in the transaction-detail presenter, or in a downstream report — independent of, and in addition to, the VAT-inclusive/exclusive basis question.

**Required outcome**: one canonical normalizer as the single source of truth for the alias list; no duplicated alias lists across report-facing or ingestion-facing code; unknown/unrecognized alias values are observable (not silently misclassified as "other tax"); canonical classification is tested identically across every affected surface.

This gap is tracked as **Track B** below — see "Problem Tracks" and "Recommended Architecture."

## Problem Tracks

Both gaps above share the same architectural root cause:

> Different reporting and presentation surfaces are interpreting financial semantics independently, instead of going through one canonical normalization and calculation boundary.

They are related enough to belong under this one spec document, but are kept as **separate subproblems with separate acceptance criteria** so they are not accidentally solved as one vague "fix VAT" task.

### Track A — VAT Reporting-Basis Consistency

The original gap described in "Background" above: `DashboardService` and `TransactionSummaryPresenter` read raw, uncorrected VAT/exempt figures instead of going through `FinanceCalculationService::deriveMetrics()`'s existing VAT-inclusive/exclusive correction. Full scope in "Scope — Track A" below.

### Track B — Tax-Type Alias Normalization

The newly-identified gap described in "Related Gap: Tax-Type Alias Consistency" above: inconsistent `tax_type` alias recognition across ingestion, model, presenter, and reporting surfaces. Full scope in "Scope — Track B" below.

Both tracks converge on the same target architecture (see "Recommended Architecture" below) rather than being patched surface-by-surface.

## Scope

### Scope — Track A: VAT Reporting-Basis Consistency

In scope:
- Deciding and implementing how `DashboardService` and `TransactionSummaryPresenter` should receive the same VAT-inclusive/exclusive correction `deriveMetrics()` already applies elsewhere — either by routing them through `deriveMetrics()` directly, or via a lighter-weight shared correction helper if the raw-SQL-aggregate performance profile of `DashboardService` can't tolerate per-row `deriveMetrics()` calls.
- Provider/tenant-aware detection and correction policy: is the VAT-inclusive/exclusive convention a property of the POS provider (like `timestamp_mode` already is, per `config/tsms.php`'s per-provider config), or does it need to be inferred per-transaction regardless of provider?
- Historical-vs-new-data behavior and mixed-rollout periods: if a provider's reporting convention changed over time, or multiple conventions coexist mid-migration, detection must not assume a clean date boundary.
- Reporting consistency across dashboard, summary, detailed transaction, and export views — the acceptance bar is that the same tenant/date range produces the same VAT-corrected totals regardless of which screen or export is used.
- Regression and reconciliation tests proving dashboard/detail/export totals agree with the already-correct CMSR/daily/weekly/hourly totals for the same data.
- Observability for detected mismatches (the existing `DashboardService:154-155` anomaly query is a candidate starting point — decide whether it becomes a monitoring signal on top of corrected data, or is retired once correction is applied everywhere).

### Scope — Track B: Tax-Type Alias Normalization

In scope:
- `app/Http/Controllers/API/V1/TransactionController.php` ingestion paths
- `app/Models/Transaction.php::otherTaxSum()`
- `app/Presenters/TransactionSummaryPresenter.php`
- `app/Services/TransactionValidationService.php::getTaxBuckets()` and its other-tax-sum logic
- All report/export consumers of tax-type classification (auditing the already-broad `FinanceCalculationService`/`SalesReportDataService`/`RefreshDailyTransactionSummaries`/`TransactionLogsExport` alias lists as the reference set, not reinventing it)
- Provider/tenant basis detection is **not** part of Track B — Track B is purely about tax-type string classification, not VAT-inclusive/exclusive basis

Explicitly not a Track B fix:
- patching `TransactionController`, `Transaction::otherTaxSum()`, and `TransactionSummaryPresenter` separately with their own copy of the broader alias list;
- adding more local `CASE`/`elseif` expressions;
- duplicating the alias list into yet another class.
See "Recommended Architecture" for the required approach instead.

### Explicitly out of scope (both tracks)
- **The Goldilocks POS terminal's VAT-subtraction defect.** This is a separate, unrelated problem: Goldilocks' POS terminal fails to subtract VAT at all on approximately 95% of its own transactions — a genuine defect in the data as submitted at the source, not a reporting-basis/display question. No correction on TSMS's reporting side can fix data that is already wrong on arrival. This needs a fix on the provider's end and is not an engineering task for TSMS. (Note: Goldilocks is also the tenant named in the *timezone* bucketing fix below — that was a different, already-resolved issue; the VAT defect is unrelated and remains open on the provider's side.)
- Any change to `specs/001-100-tenant-resilience/` or its US5 (Operational Readiness) scope — that feature's own spec explicitly states "Financial calculation/business-rule changes are out of scope except where required for retry-safe ingestion," and US5's observability scope is about ingestion/queue/circuit-breaker signals, not report correctness. This gap was identified during that feature's delivery but is intentionally tracked separately here.
- Any change to the 7-Eleven business-day-cutoff logic (`docs/specs/7-eleven-business-day-cutoff.md`) — a related but distinct business-date concern.
- Any change to the already-merged timezone/UTC bucketing fix (see below) beyond noting its relationship to this work.

## Recommended Architecture (Canonical Financial-Normalization Pipeline)

The long-term architectural target for both tracks:

```text
Raw transaction/tax rows
        ↓
Tax type normalization
        ↓
Provider/tenant reporting-basis resolution
        ↓
VAT-inclusive/exclusive correction
        ↓
Canonical financial metrics
        ↓
Dashboard / reports / summaries / exports
```

Today's inconsistency exists because different surfaces enter this pipeline at different points — or bypass it entirely. The fix should **not** be surface-by-surface patches (patch `DashboardService` separately, patch `TransactionSummaryPresenter` separately, add more local `CASE` expressions, duplicate the alias list into more classes). The fix should be: move all report-facing financial interpretation behind one reusable domain-service/value-object boundary.

### Component boundaries

**A. `TaxTypeNormalizer`** — normalizes raw tax-type strings (handling aliases such as `VAT-EXEMPT`, `VATEXEMPT`, `EXEMPT`, `SC_VAT_EXEMPT_SALES`, etc.) and returns a canonical enum/internal constant, e.g.:

```php
enum CanonicalTaxType: string
{
    case VatSale = 'vat_sale';
    case VatExempt = 'vat_exempt';
    case ZeroRated = 'zero_rated';
    case NonVat = 'non_vat';
    case Unknown = 'unknown';
}

TaxTypeNormalizer::normalize(?string $raw): CanonicalTaxType
```

The alias list exists in exactly one place. This is the Track B deliverable.

**B. `ReportingBasisResolver`** — determines whether the transaction/provider/tenant data is VAT-inclusive, VAT-exclusive, or mixed/unknown, and resolves tenant/provider-specific policy. `ReportingBasisResolver` owns the *runtime basis decision*; the broader historical, reconciliation, and rollout decisions (backfill scope, tolerance, anomaly-query fate — see "Required Decisions") remain feature-level governance decisions, not something the resolver class itself contains. This is the Track A deliverable, and is where the historical/rollout-aware policy from "Required Decisions" items 2–3 gets implemented.

**C. `FinanceCalculationService`** — calculates canonical financial metrics after normalization and basis resolution, and remains the *one* supported calculation path for CMSR, dashboard, detailed transaction summaries, hourly/daily/weekly reporting, exports, and grand totals. `deriveMetrics()` may remain the entry point, but likely needs a clearer input contract than raw, loosely-interpreted rows once `TaxTypeNormalizer`/`ReportingBasisResolver` exist upstream of it.

## Relationship to the Timezone/UTC Bucketing Fix

Two commits, already merged to `origin/main` (not yet present on this repository's local `main` or on the `remediate-backpressure-sharding-foundation` branch — see "Local Branch Note" below), fixed a **separate and orthogonal** issue:

- `b3dab702` "Fix report timezone bucketing" — centralized business-date resolution into a `ResolvesReportBusinessDate` trait and switched the "All Tenants" breakdown and hourly report views to use raw UTC timestamps for date-bucketing, instead of letting Eloquent's untimezoned datetime cast treat UTC-stamped values as if they were already Manila-local wall-clock time.
- `1e82a5db` "Fix legacy null-payload rows being over-shifted by the timezone fix" — a follow-up fixing over-correction of historical rows (ingested before payload capture existed) for providers whose legacy data was already local time, not true UTC. Confirmed on real data for tenant 10 (Goldilocks/terminal 79): the initial fix had inflated a day's transaction count from 194 (matching the printed Z-reading) to 213. The fix adds a provider-mode fallback consulted only when payload evidence is absent, and explicitly rejects a date-based cutover in favor of a provider-convention-based one, because audited data showed no clean date boundary — June 2026 was a genuinely mixed rollout month.

**These commits touch only *when* a transaction is bucketed into a business day/hour — they do not touch `vat_amount`, `vatable_sales`, `net_sales`, or `deriveMetrics()` in any way.** The two issues are independent: a transaction can be correctly bucketed on the right business day and still show the wrong VAT-corrected total on an uncorrected report surface, or vice versa. However, the *pattern* used to resolve the timezone issue — a provider-convention-aware fallback rather than a date-based cutover, informed by real reconciliation against Z-readings — is a directly reusable precedent for how this VAT-correction gap should likely be approached (see "Required Decisions" below).

**Local branch note (historical, resolved)**: at this document's original authoring, local `main` was 7 commits behind `origin/main` and missing the `b3dab702`/`1e82a5db` lineage. This has since been synced — see the closure note immediately below.

**Status: resolved dependency — closed.** `app/Traits/ResolvesReportBusinessDate.php` and both commits (`b3dab702`, `1e82a5db`) are confirmed present in local `main`. UTC/business-date bucketing is fully merged and stable. **No further implementation work on timezone/UTC bucketing is required in either track of this feature.** Neither Track A nor Track B should touch `ResolvesReportBusinessDate` or reopen bucketing logic — this prevents future agents from mixing already-stable bucketing changes into financial-calculation work.

## Provider/Tenant-Aware Detection and Correction Policy

**Open decision** (see "Required Decisions"): should VAT-inclusive-vs-exclusive be a per-provider configuration flag (mirroring `config('tsms.intake.timestamp_mode')`'s per-provider convention already established for the timezone fix), a per-transaction heuristic detection (as `deriveMetrics()` already does via `$rawLooksVatInclusive`), or both — config as a fast-path hint, heuristic as a fallback when config is absent, matching the layered approach `1e82a5db` used for the timezone fix (payload evidence first, provider-mode fallback second, never override a confirmed classification).

## Historical vs. New Data Behavior / Mixed-Rollout Periods

Per the timezone fix's own finding, do not assume a clean date boundary exists for when a provider's VAT-reporting convention might have changed — audit real data before assuming one. If historical data must be reprocessed (see "Rollout, Backfill, and Reconciliation Risks"), the correction must be idempotent and safe to re-run, consistent with how `1e82a5db` handled reprocessing `RefreshDailyTransactionSummaries`.

## Reporting Consistency Requirement

For any given tenant and date range, `DashboardService` and `TransactionSummaryPresenter` outputs must agree with `CommercialReportsController`/`SalesReportDataService`/`HourlyReportService`/`TransactionLogController` outputs for the same underlying transactions, once this work lands. This is the acceptance bar referenced in "Acceptance Criteria."

## Recommended Ingestion Strategy

Do not overwrite raw provider values indiscriminately. Preserve them alongside the normalized classification:

```text
raw_tax_type          = original provider value
canonical_tax_type    = normalized internal classification
normalization_version = rule-set version
```

This matters architecturally for: auditability, reconciliation, future rule corrections, provider disputes, and historical reprocessing. If adding columns is too disruptive to land immediately, normalize at the domain boundary first — but the design should still preserve the raw input somewhere, not discard it at ingestion.

## Recommended Rollout

1. **Discovery and reconciliation** — inventory all tax-type aliases actually present in production data; identify every direct raw VAT aggregation; identify every `deriveMetrics()` caller and bypass; build reconciliation queries comparing current result, corrected result, and delta by tenant/provider/date. No behavior change yet.
2. **Canonical normalization** — implement `TaxTypeNormalizer`; replace duplicated alias lists; add unknown-alias telemetry; keep financial outputs unchanged initially where possible.
3. **Calculation coverage** — route `DashboardService` through the canonical calculation path; route `TransactionSummaryPresenter` through the same path; remove or deprecate raw aggregate duplication; add cross-surface consistency tests.
4. **Historical policy** — decide backfill versus read-time correction (per "Required Decisions" item 4); run tenant/provider reconciliation; deploy behind a tenant/provider feature flag if the historical impact is material.
5. **Operational rollout** — compare old and new outputs; record deltas; require finance sign-off; progressively enable by provider or tenant.

## Regression and Reconciliation Tests

- Unit tests proving `DashboardService`'s (or its replacement/wrapped) VAT-related aggregates match `deriveMetrics()`'s corrected output for known VAT-inclusive and VAT-exclusive fixture transactions.
- Unit tests proving `TransactionSummaryPresenter`'s per-transaction VAT fields match `deriveMetrics()`'s per-transaction correction for the same fixtures.
- A cross-surface reconciliation test: given the same seeded transaction set, assert `DashboardService`'s aggregate total equals the sum of `SalesReportDataService`'s corrected per-day totals for the same range.
- Regression coverage confirming the existing `DashboardService:154-155` anomaly-detection query's behavior is preserved or deliberately superseded (decide which, per "Required Decisions").
- **Cross-surface consistency matrix (both tracks)**: for the same seeded transaction set, assert identical VAT totals, exempt totals, net sales, and discount values across CMSR, Dashboard, Hourly report, Daily report, Weekly report, Transaction summary, Export, and Grand totals.
- **Track B alias tests**: all known aliases map to the same `CanonicalTaxType` regardless of which surface reads them; unknown aliases are surfaced/observable, not silently misclassified as "other tax"; no double correction when both normalization and basis correction apply; raw provider values remain auditable after normalization; VAT-inclusive-provider, VAT-exclusive-provider, mixed-rollout-month, and historical-null/missing-metadata fixtures are all covered; corrected totals stay within the approved tolerance.

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

**Track A:**
- [ ] `DashboardService`'s VAT-related aggregates and `TransactionSummaryPresenter`'s per-transaction VAT fields apply the same correction logic `deriveMetrics()` already applies to CMSR/daily/weekly/hourly/export/transaction-log surfaces.
- [ ] For any tenant and date range, dashboard totals reconcile with CMSR/report totals for the same transactions, within the agreed tolerance.
- [ ] Regression tests cover both VAT-inclusive and VAT-exclusive source conventions, plus at least one mixed/ambiguous-provider fixture case.
- [ ] The Goldilocks provider-side VAT-subtraction defect remains explicitly untouched by this work and is separately communicated/tracked as a provider issue, not folded into this fix's scope.

**Track B:**
- [ ] One canonical `TaxTypeNormalizer` (or equivalent) is the sole source of truth for tax-type alias classification; no duplicated alias lists remain in ingestion, model, presenter, or reporting code.
- [ ] `TransactionController` ingestion paths, `Transaction::otherTaxSum()`, `TransactionSummaryPresenter`, and `TransactionValidationService::getTaxBuckets()` all classify the full alias set identically to `FinanceCalculationService`/`SalesReportDataService`/`RefreshDailyTransactionSummaries`.
- [ ] Unknown/unrecognized tax-type values are observable (logged/metered), not silently bucketed into "other tax."
- [ ] Raw provider tax-type values remain auditable after normalization (per "Recommended Ingestion Strategy").

**Both tracks:**
- [ ] No change is made to `specs/001-100-tenant-resilience/` scope, US5, or the timezone/UTC bucketing fix.

## Required Decisions (unresolved, blocking implementation)

1. **Correction methodology for bypass paths** — route `DashboardService`/`TransactionSummaryPresenter` through `deriveMetrics()` directly, or build a lighter-weight shared correction helper for raw-aggregate performance reasons?
2. **Provider/tenant scope** — config-driven (per-provider flag), heuristic-only (per-transaction, as today), or layered (config first, heuristic fallback)?
3. **Historical cutover** — is there a clean date boundary at all, or must detection be per-row/per-payload regardless of date (per the timezone fix's own precedent of rejecting a date-based cutover)?
4. **Backfill scope** — recompute existing cached/materialized aggregates, or forward-only?
5. **Consistency tolerance** — what variance between dashboard and detailed-report totals counts as a regression, and is the existing `>0.02`-peso per-row tolerance the right basis for an aggregate-level check?
6. **Anomaly-query fate** — keep `DashboardService:154-155` as a live monitoring signal post-fix, or retire it?
7. **Alias handling policy** (Track B) — the canonical alias set; unknown-value behavior; whether normalization occurs at ingestion time, read time, or both; whether historical raw values remain unchanged.

## Cross-References

- `specs/001-100-tenant-resilience/plan.md` — "Feature Status" section notes this gap (Track A) was identified during that feature's delivery and is tracked here, outside that feature's scope.
- `docs/specs/7-eleven-business-day-cutoff.md` — sibling lightweight spec doc, same `docs/specs/*.md` convention, a related but distinct business-date concern.
- Timezone/UTC bucketing fix: commits `b3dab702`, `1e82a5db` (merged to `origin/main` and confirmed present in local `main` — resolved, closed dependency, see "Relationship to the Timezone/UTC Bucketing Fix").
- Track B (tax-type alias normalization) was identified during verification of this same spec, not during a separate feature's delivery — see "Related Gap: Tax-Type Alias Consistency."
