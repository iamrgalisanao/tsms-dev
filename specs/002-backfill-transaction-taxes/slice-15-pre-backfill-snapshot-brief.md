# Slice 15 Architecture Brief — Pre-Backfill Aggregate Snapshot (T073, T074)

**Date**: 2026-08-12 · **Status**: Approved to implement against this brief

## Why this brief exists

FR-012's snapshot is **"unrecoverable once the run begins and is the only defensible `before` value"** for materiality (FR-009a) and for proving no unintended aggregate drift (SC-003). Getting this wrong doesn't surface as a visible bug — it surfaces months later as an indefensible materiality list nobody can trust, with no way to recapture the real baseline. That combination (silent failure mode + irreversibility) is why this is a full brief-and-review slice on its own, even though it is read-only against reporting and writes only to two brand-new tables.

**This brief covers T073/T074 only: capture and durable persistence of the pre-backfill baseline.** It does not compute materiality, does not refresh any aggregate, does not touch `transaction_taxes`/`transactions`, and does not run against real data without separate authorization (this feature's standing rule, unchanged).

## Grounding: the real rendered report path (verified against current code, not assumed)

- Entry point: `App\Services\Reports\SalesReportDataService::getCmsrReportData(SalesReportFilter $filter): SalesReportResult` (`app/Services/Reports/SalesReportDataService.php:19`). Build the filter with `SalesReportFilter::forTenantYearMonth($tenantId, $year, $month)` (`SalesReportFilter.php:28`) — the same construction path the real export controller uses.
- `SalesReportResult` (readonly DTO) exposes `source` directly (`'daily_transaction_summaries'` or `'raw_transactions'`) — **no need to re-derive the source-detection logic** (`hasCompleteDailySummaryRefresh()`, `SalesReportDataService.php:364-381`); just read `->source` off the result. This is what makes FR-012a's pinning requirement cheap to satisfy correctly.
- **This service always queries the primary connection** — no `reporting` connection override exists in `SalesReportDataService`. The `raw_transactions` fallback path is expensive: a non-sargable computed-`CASE` date predicate (`ResolvesReportBusinessDate`) plus a row-by-row `cursor()` JSON-payload decode for every transaction in a tenant's month. Given `daily_transaction_summaries`/`report_refresh_states` will very likely **not** have complete coverage for the defect-window months (summaries lag ~2 days by default), **assume most calls hit the expensive raw path**, competing with live primary traffic — this is why this command needs a throttle option and must run off-peak, same as every other command in this feature (FR-005's spirit, even though every operation here is a read).
- There is no existing tenant×month iteration pattern to reuse (`ReportingRefreshCommand`/`ReportingDispatchCommand` operate on an unrelated table/connection and don't share this logic). `Tenant` has no active-date-range field — tenant scope must be derived from `transactions`, not enumerated from the `Tenant` model.
- There is no single "the tax figure" in the result — `other_tax`, `vat_amount`, `sc_vat_exempt_sales`, `vatable_sales`, etc. all live in the same `totals`/`dailyTotals` arrays. **This command does not pick one** — see Design decision 3.

## Scope contract

```text
Allowed:
- Two new tables + two new Eloquent models (schema below).
- app/Console/Commands/SnapshotPreBackfillAggregates.php
  (transactions:snapshot-pre-backfill-aggregates).
- Tests per the plan below.
- A read-only call into SalesReportDataService::getCmsrReportData() —
  calling it, not modifying it or SalesReportFilter/SalesReportResult/
  FinanceCalculationService in any way.

Not allowed:
- Any change to SalesReportDataService.php, SalesReportFilter.php,
  SalesReportResult.php, FinanceCalculationService.php, or any other
  existing report/finance service — this slice is a caller, never an
  editor, of the rendered report path.
- Any write to transaction_taxes or transactions, under any
  circumstance. This command's only write targets are its own two new
  tables.
- Materiality computation (T047/Command 3) — this slice captures the
  `before`; deciding what counts as a material delta later is a
  separate, future command's job.
- Aggregate refresh (Command 6 in cli-contract.md is THIS slice;
  reports:refresh-daily-transaction-summaries is unrelated and already
  exists — do not touch it).
- Live --apply against real data, archive/reconcile/delete work
  (Slices 12-14, already done), rollback scripting (T052), or
  scheduled-job containment (T052a) — all separate, later work.
```

## Design decisions

### 1. Storage shape — two tables, mirroring the `tax_backfill_runs`/`tax_backfill_records` pattern

**`pre_backfill_snapshot_runs`** (one row per command invocation that actually attempts a capture):

| Column | Purpose |
|---|---|
| `id` | PK |
| `snapshot_type` | **Added per architect review** — a fixed string identifying *what this snapshot is for*, e.g. `pre_backfill_rendered_aggregate` (a class constant on the command/model, not operator-supplied). Exists so this window is never "permanently owned" by the first run that happened to capture it — a future, genuinely different snapshot purpose over the same window is a distinct row, not a collision or a forced overwrite. |
| `report_contract_version` | **Added per architect review** — a fixed string pinning *which version of `getCmsrReportData()`'s output shape/semantics* this run's calls were made under, e.g. `cmsr_v1` (also a class constant, not operator-supplied). If that report path's formula or output shape ever changes, a future run tags itself with a new version rather than silently colliding with — or being blocked by — an old run's baseline captured under different semantics. |
| `window_start`, `window_end` | The `--from`/`--to` this run captured (`--to` exclusive, matching Command 1's convention) |
| `status` | `running` \| `completed` \| `failed` — see Design decision 5 |
| `tenant_count`, `month_count` | How many (tenant, month) pairs this run targets — recorded once at start, for progress/audit, not recomputed later |
| `forced` | `true` if this run was created via `--force` despite a prior completed run for the identical `(snapshot_type, window_start, window_end, report_contract_version)` existing (Design decision 5) |
| `started_at`, `completed_at` | |

**Unique index**: `(snapshot_type, window_start, window_end, report_contract_version)` is the run-level idempotency key (not `window_start`/`window_end` alone) — see Design decision 5.

**`pre_backfill_snapshot_records`** (one row per (run, tenant, reporting month) actually captured):

| Column | Purpose |
|---|---|
| `id` | PK |
| `run_id` | FK to the run above |
| `tenant_id` | |
| `reporting_year`, `reporting_month` | Integers, not a date — a reporting month is not a calendar date |
| `source` | `daily_transaction_summaries` \| `raw_transactions` — pulled directly from `SalesReportResult::$source`, never re-derived (FR-012a) |
| `rendered_result` | `json`, **verbatim** `SalesReportResult::toArray()` output — capture the whole thing, not a cherry-picked field. This slice does not decide which field(s) later materiality math cares about; it preserves what the rendered report actually showed, completely, so that decision can be made correctly later without needing to have predicted it now |
| `captured_at` | |

**Unique constraint**: `(run_id, tenant_id, reporting_year, reporting_month)` — the mechanism both idempotency and resumability rely on (Design decision 5), mirroring `transaction_taxes_orphan_archive.original_id`'s role in Stage 1.

### 2. FR-012a's source-label pinning — what this slice does and doesn't do

This slice's job is to make pinning **possible**: capture `source` unambiguously per record, and never let a record's `source` be silently overwritten or mixed with a different capture. The unique constraint above plus "never UPDATE a `pre_backfill_snapshot_records` row, only INSERT" achieves this. **The actual refusal-to-compare-across-differing-sources logic is materiality's job (T047, a separate future command), not this slice's** — there is nothing to compare yet, only a `before` to capture. Do not build comparison logic here.

### 3. Capture the whole rendered result, not a single field

`other_tax`, `vat_amount`, `sc_vat_exempt_sales`, `vatable_sales`, `net_ex_vat`, `net_subject_to_rent`, `net_total`, plus `dailyTotals` — all of it, verbatim, in `rendered_result`. Deciding which field(s) FR-009a's materiality math actually compares is explicitly deferred (Design decision 2's boundary) — capturing everything now means that decision is never blocked on "we should have captured X but didn't."

### 4. Tenant and month enumeration

- **Tenants**: `SELECT DISTINCT tenant_id FROM transactions WHERE created_at >= :from AND created_at < :to` — the actual affected population for this window, not every `Tenant` row in the system (no active-date-range field exists on `Tenant` to filter by anyway).
- **Reporting months**: every calendar `(year, month)` from `--from`'s month through (`--to` minus one day)'s month, inclusive. (`--to` is exclusive, so if `--to=2026-08-10`, the last touched day is `2026-08-09`, and August is still a touched month.)

### 5. Idempotency and resumability — no silent overwrite, safe to re-invoke

On invocation, look up an existing run matching **all four** idempotency-key fields: `(snapshot_type, window_start, window_end, report_contract_version)` — not `window_start`/`window_end` alone (revised per architect review; a bare window key would let the first run permanently own that window, even for a future genuinely-different snapshot purpose or a future report-contract version bump). `snapshot_type` and `report_contract_version` are fixed class constants for this command (`PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE`, `::REPORT_CONTRACT_VERSION_CMSR_V1` or similar) — not CLI-configurable in this slice; bumping either is a deliberate future code change, not an operator flag:

- **A `completed` run exists for that exact key, no `--force`**: refuse outright — zero report calls, zero writes — naming the existing run's id and `completed_at` in the error. This is the "don't silently overwrite a baseline" guarantee the user asked for.
- **A `completed` run exists for that exact key, `--force` given**: create a **new** run row (`forced = true`). The prior completed run and its records are never touched, updated, or deleted — old baselines are permanent evidence, same philosophy as the orphan archive never being deleted.
- **A `running` or `failed` run exists for that exact key**: resume it — same `run_id`, skip any (tenant, month) pair that already has a record, attempt the rest. This is what makes an interrupted or partially-failed run cheap to retry: already-captured (tenant, month) pairs never re-pay the expensive `raw_transactions` cost.
- **No run exists for that key**: create a new one, `status = running`.

A different `snapshot_type` or `report_contract_version` over the identical window is, by design, a completely independent run — never a conflict, never requiring `--force`.

No kill-switch is needed here (unlike `TaxBackfillRunner::apply()`) — there is no destructive action to interrupt mid-way; every write is a small, idempotent INSERT of an audit-shaped row, and a Ctrl-C or crash simply leaves the run `running`, safely resumable by re-invocation.

### 5a. Per-(tenant, month) failure handling

Catch exceptions per (tenant, month) — do not let one tenant's report call abort the whole run. Continue attempting every remaining pair. If **any** pair failed, the run ends `status = failed` (not `completed`), even though it may have successfully captured most pairs — a snapshot that silently omits some tenants must never be mistaken for a complete baseline. Re-invoking the same command retries only the missing/failed pairs (Design decision 5's resumability), not the whole population.

### 6. `--throttle=` (optional, no enforced default)

A millisecond delay between each per-(tenant, month) report call, mirroring `TaxBackfillRunner::apply()`'s own `--throttle=` precedent (T031: opt-in, not silently defaulted) — given the cost profile above (raw path likely, primary connection, JSON-decode-per-row), spacing calls out reduces peak load on a table live ingestion also writes to.

## CLI wiring

```
transactions:snapshot-pre-backfill-aggregates
    {--from= : Window start (Y-m-d). Required.}
    {--to= : Window end, exclusive (Y-m-d). Required.}
    {--apply : Persist. Without this flag: preview only — list the tenant/
               month pairs that would be captured, which (if any) already
               have a record under an existing run for this window, and an
               estimated call count. Never calls the report path.}
    {--force : Required to start a new run when a completed run already
               exists for the identical --from/--to window.}
    {--throttle= : Milliseconds between each per-tenant/month report call.}
    {--json}
```

- Dry-run (no `--apply`, this feature's established default-safe convention): resolve the tenant/month population and the run lookup (completed/running/failed/none), report it, call `getCmsrReportData()` zero times, write nothing.
- `--apply`: perform the real capture per Design decisions 4-6.
- Single `buildResult()`/`render()` pair, this feature's established one-result-object convention.

## Test plan

- **Successful snapshot**: a fresh window with no prior run captures every (tenant, month) pair; every record's `rendered_result` matches `SalesReportDataService::getCmsrReportData()`'s own output for that same filter (called independently in the test as the oracle), and `source` matches `SalesReportResult::$source` exactly.
- **Durable persistence**: after a completed run, the records exist and are queryable independent of the command process (assert via direct `DB::table(...)` reads, this feature's established verification standard).
- **Source-label recording, not derivation**: a test where the same tenant/month resolves to `raw_transactions` (incomplete `report_refresh_states` coverage) and another where it resolves to `daily_transaction_summaries` (complete coverage) — assert the persisted `source` matches each case exactly, proving the command reads the field rather than reimplementing the detection logic.
- **Refusal/guard behavior**: a completed run refuses a second bare invocation for the identical `(snapshot_type, window_start, window_end, report_contract_version)` key (zero report calls, zero new run/records) but a `--force` invocation creates a distinct new run without touching the old one's rows.
- **Key isolation**: a completed run for a given window does NOT block a run under a different `snapshot_type` or `report_contract_version` over the identical window — assert no refusal, no `--force` needed, and both runs' records coexist independently.
- **Resumability**: simulate a `failed` run with some (tenant, month) pairs already captured; re-invoke with the identical window; assert only the missing pairs trigger a new report call (mock/spy `SalesReportDataService` call count) and the run reaches `completed` once all pairs exist.
- **Per-pair failure isolation**: force one (tenant, month) call to throw; assert every other pair in the population still gets captured, and the run ends `failed`, not `completed`, not aborted early.
- **No mutation to `transaction_taxes`/`transactions`**: assert row counts unchanged before/after, for both dry-run and `--apply`.
- **CLI-level**: `--from`/`--to` required; dry-run never calls the report path (spy/mock assertion, not just a row-count side-effect check — this feature's established rigor since the Slice 12 review finding); `--json`/human output parity.

## What's explicitly deferred

- T047 / the materiality command (Command 3) — comparing `before` vs. `after` and applying the FR-009a threshold.
- Aggregate refresh (existing, unrelated command) and any live `--apply` of the actual tax backfill.
- Archive/reconcile/delete (Slices 12-14, already complete).
- `rollback.md` (T052) and scheduled-job containment (T052a) — separate live-run-readiness-plan.md items.
