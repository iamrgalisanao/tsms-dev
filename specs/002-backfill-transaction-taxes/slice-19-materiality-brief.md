# Slice 19 Brief — T047 / Command 3: Materiality Report

**Status:** Draft — awaiting architect (user) sign-off before implementation, per this feature's established brief-first convention for high-risk slices.

## Task

T047 ([tasks.md](tasks.md#L160)):

> Compute per-(tenant, reporting month) before/after tax totals from the persisted audit records only, so the result is reproducible without re-querying mutated state (SC-006)

Command 3 ([contracts/cli-contract.md](contracts/cli-contract.md#L53)):

```
transactions:tax-backfill-materiality
    {--run= : Backfill run identifier}
    {--threshold-amount=500}
    {--threshold-percent=1}
    {--json}
```

> Read-only. Emits per-(tenant, month) before/after tax totals and flags those crossing either threshold (FR-009a). Sending notifications is **not** part of this command — it produces the defensible list only (SC-006); dispatch is a separate, human-triggered step.

Governing requirements: **FR-009a** (materiality threshold: ≥₱500 OR ≥1% of that month's tax total, whichever triggers first; `before` MUST be the FR-012 rendered snapshot, never zero), **FR-012a** (refuse to compare a `before`/`after` captured under different rendering sources), **FR-016/S2** (`transactions.tax_exempt`, a boolean summed as currency, excluded from materiality math), **SC-006** (the flagged-tenant set must be reproducible from *recorded* before/after totals, not re-derived from then-current live state).

## Grounding (verified against current code, not inherited)

**cli-contract.md predates Slice 15 and is stale on two points, not just under-specified:**

1. **`{--run=}`'s "Backfill run identifier"** was written 2026-08-10, before `PreBackfillSnapshotRun` (Slice 15, 2026-08-12) existed. The only run entity that actually enumerates a (tenant, reporting month) population — the population materiality needs — is `pre_backfill_snapshot_runs`/`_records`, not `tax_backfill_runs` (which has no `tenant_id` column at all; it's one row per whole-day-or-whole-window apply/dry-run invocation, per `database/migrations/2026_08_11_000000_create_tax_backfill_runs_table.php`'s own docblock — "one row per invocation," not per-tenant). **Proposed**: rename this option to `--snapshot-run=` and have it identify `pre_backfill_snapshot_runs.id`, defaulting to the most recently completed run for the standard `(snapshot_type, report_contract_version)` key (same `orderByDesc('id')` determinism `SnapshotPreBackfillAggregates::resolveRunDecision()` already uses) when omitted. This is a genuine reinterpretation of the contract, not a literal implementation of it — flagging for explicit sign-off before I build it this way.

2. **T047's own wording** ("from the persisted audit records only... without re-querying mutated state") reads as if `before` and `after` should both come from `tax_backfill_records`. That can't be what FR-012/FR-012a actually require: FR-012 is explicit that the **rendered** report path (`SalesReportDataService::getCmsrReportData()`) is "the only defensible `before` value," specifically *because* the audit-record path can't reproduce the report layer's own formula (`FinanceCalculationService::deriveMetrics()`'s VAT-inclusive/exclusive inference, `max()`-merged multi-source `other_tax`, etc.). T047 is superseded by FR-012/FR-012a here, the same way T038 was superseded by Architect F4/T077 — noting this so nobody re-litigates it as a contradiction later.

**`calculated_net_sales`/`net_amount` do not appear anywhere in the data this command reads.** Checked directly: `SalesReportResult::toArray()`'s `totals`/`daily_totals` come from `FinanceCalculationService::deriveMetrics()`, whose returned array uses the key `net_sales` — there is no `calculated_net_sales` or `net_amount` key at this aggregate level. Those two names are `App\Models\Transaction` **per-transaction accessors** (T088a-1, DECIDED S6), a different surface entirely (per-row API serialization, not the tenant/month CMSR aggregate this command consumes). **Flagging this explicitly rather than silently applying the naming instruction where it doesn't map**: if this brief's design is approved as-is, the materiality command's net-sales-adjacent figures (if reported at all — see "Design" below, they're not part of the flagging decision) will use the aggregate's own `net_sales` field name, since that's what actually exists on this data path. Say if you'd still like something renamed here, or if this confirms the instruction was aimed at the T088a-1 surface specifically (my read).

**`transactions.tax_exempt` contamination cancels in the delta, but not in the absolute figures — verified, not assumed.** FR-016/S2 excludes `tax_exempt` from materiality math because it's a boolean summed as currency, contaminating `other_tax`'s absolute value at both the raw-SQL and daily-summary paths. But: neither this feature's backfill, reconcile, nor delete pipeline ever writes to `transactions.tax_exempt` — confirmed via grep, zero references outside the report/finance layer. So for any given (tenant, month), the exact same set of `tax_exempt=true` rows contributes the exact same boolean-count contamination to **both** the `before` snapshot and the `after` render — it cancels out in the delta (`after − before`) even though it makes each side's *absolute* `other_tax` figure individually untrustworthy as a standalone currency value. **Design decision**: report absolute `other_tax_before`/`other_tax_after` with an explicit caveat field (`tax_exempt_contamination_note` or similar), but compute the materiality delta/flag off the difference as normal — the difference itself is not subject to the FR-016 defect, only the absolute figures are.

**`other_tax`'s deny-list vs. the model layer's future allow-list (T088a) is a known, already-scoped non-issue for this command.** `SalesReportDataService`'s SQL uses its own 13-item deny-list (confirmed at `SalesReportDataService.php:223` and `RefreshDailyTransactionSummaries.php:120`); `Transaction::otherTaxSum()`'s eventual allow-list fix (T088a-2, not yet implemented) is a *different, unmerged* code path. Per T088a-5a (already recorded in tasks.md), this asymmetry is explicitly out of scope for this feature — the materiality command reads whatever `other_tax` the report layer currently produces, as-is, without waiting on or referencing T088a.

**Population, source, and record shape precedent all come directly from Slice 15** — same enumeration (`SalesReportFilter::forTenantYearMonth`), same `source`-pinning field, same `rendered_result` JSON-of-`toArray()` persistence, same per-pair failure isolation, same `Cache::lock()`-guarded idempotent run/resume/refuse pattern. This brief reuses that shape rather than inventing a new one.

## Design

### Scope boundary with T076 (per your direction — separate slices)

This slice is the **business-impact reader**: consumes Slice 15's pinned `before` snapshot, captures and pins its own `after` render, computes deltas, applies the FR-009a threshold. It does **not** touch `pre_run_integrity_captures`, does **not** implement the post-run duplicate-check/`transaction_pk IS NULL` zero-assertion, and does **not** gate or block anything — it is read-only reporting. T076 (post-run integrity comparison, using Slice 16's `pre_run` capture) is a distinct, later slice that builds on this one only in the sense that it's part of the same overall "final evidence" phase, not by sharing code or tables.

### New tables

`tax_backfill_materiality_runs` (mirrors `pre_backfill_snapshot_runs`):
- `id`, `snapshot_run_id` (FK → `pre_backfill_snapshot_runs.id`, **not nullable** — a materiality run always has exactly one before-baseline), `report_contract_version`, `status` (`running`/`completed`/`failed`), `tenant_count`, `month_count`, `forced`, `started_at`, `completed_at`.
- **No threshold fields stored here.** See "Threshold evaluation is a read-time concern" below — this is a deliberate design choice, not an oversight.

`tax_backfill_materiality_records` (mirrors `pre_backfill_snapshot_records`):
- `id`, `run_id` (FK → `tax_backfill_materiality_runs.id`), `tenant_id`, `reporting_year`, `reporting_month`.
- `before_source`, `after_source` (both pinned; **no** `before_rendered_result` duplication — joins to `pre_backfill_snapshot_records` via `(snapshot_run_id, tenant_id, reporting_year, reporting_month)` for the full before payload, since those rows are already guaranteed immutable post-capture per FR-012a; duplicating them here would be redundant storage with no correctness benefit).
- `after_rendered_result` (json, full `SalesReportResult::toArray()` — the "whole snapshot" your brief requirement calls for, for SC-003 drift-checking and audit fidelity, even though only one field drives the threshold).
- `other_tax_before`, `other_tax_after`, `other_tax_delta_amount`, `other_tax_delta_percent` (nullable — null when `comparison_status != 'compared'`).
- `comparison_status`: `compared` | `source_mismatch`.
- `captured_at`.
- Unique index on `(run_id, tenant_id, reporting_year, reporting_month)`, matching Slice 15's constraint shape.

### Threshold evaluation is a read-time concern, not baked into a persisted flag

FR-009a's own text: *"Finance may tune either bound before rollout; doing so changes only which tenants are notified, not the correction itself."* That means the **delta** (what actually happened) must be captured once and pinned (SC-006's reproducibility requirement), but the **flag** (does this delta cross today's threshold) must be re-evaluatable against different `--threshold-amount`/`--threshold-percent` values without re-running an expensive report-rendering pass. So: `--apply` performs and persists the capture+delta computation exactly once per `(snapshot_run_id)` (idempotent, `--force` to redo); the threshold flag itself is computed fresh on **every** invocation — including read-only re-displays of an already-completed run — from whatever `--threshold-amount`/`--threshold-percent` that specific invocation passed. Re-running with a different threshold to see who it would have flagged never touches the database.

### Command signature

```
transactions:tax-backfill-materiality
    {--snapshot-run= : pre_backfill_snapshot_runs.id to use as the before baseline. Defaults to the most recently completed run for the standard key.}
    {--threshold-amount=500}
    {--threshold-percent=1}
    {--apply : Capture the "after" render and persist deltas. Without this flag: preview only — resolves the snapshot-run and population, calls the report path zero times, structurally cannot capture anything.}
    {--force : Required to start a new materiality run when a completed one already exists for the identical snapshot_run_id.}
    {--json}
```

Same dry-run-never-touches-the-report-path convention as every other command in this feature — chosen for consistency with the established mental model (dry-run = free preview, `--apply` = the one thing that costs something), even though materiality's `after` read is read-only and arguably lower-stakes than Slice 15's "unrecoverable baseline" concern. Flagging as a real (if minor) judgment call, not an obviously-forced one.

### Population and flow

1. Resolve `--snapshot-run` (or default to the latest completed one for the standard key). If none exists at all: refuse — there is no legitimate `before`, and FR-009a explicitly forbids defaulting to zero.
2. Population = every `(tenant_id, reporting_year, reporting_month)` row in that snapshot run's `pre_backfill_snapshot_records` — the before-side defines the population, exactly as Slice 15 defines its own population from live `transactions`.
3. Per pending pair (same `Cache::lock()`-guarded, per-pair-exception-isolated pattern as Slice 15): render `after` via `SalesReportDataService::getCmsrReportData()`. If `after.source !== before.source`: persist with `comparison_status = 'source_mismatch'`, delta fields null, **continue to the next pair** — a single tenant's source flip does not block visibility into every other tenant's materiality determination (this mirrors Slice 15's own per-pair failure isolation; flagging as a judgment call since a stricter "abort the whole run" reading of FR-012a is also defensible — my recommendation is per-pair, for the same reason Slice 15 chose per-pair).
4. Otherwise: `other_tax_delta_amount = after.other_tax - before.other_tax`; `other_tax_delta_percent = delta_amount / before.other_tax * 100` (guarded against divide-by-zero — if `before.other_tax == 0`, percent is null/undefined and only the amount threshold can trigger, never a spurious infinite percent).
5. Output (both `--apply` and read-only re-display of an existing run): per-pair row with `before_source`/`after_source`/`comparison_status`/`other_tax_before`/`other_tax_after`/`other_tax_delta_amount`/`other_tax_delta_percent`/`materiality_flag` (computed from the current invocation's thresholds) — plus a summary section (population count, compared count, source-mismatch count, flagged count, total `other_tax` before/after/delta across `compared` pairs only).

### Not in scope

- Notification dispatch (FR-009b) — explicitly a separate, human-triggered step per the contract.
- T076's post-run integrity comparison — separate slice, per your direction.
- Any fix to the deny-list/allow-list `other_tax` asymmetry (T088a family) — reads whatever the report layer currently produces.
- Any fix to the `tax_exempt` boolean-as-currency defect (FR-016) — pre-existing, tracked separately; this command works around it via delta-cancellation, does not fix it.

## Open decisions for sign-off

1. **`--snapshot-run=` reinterpreting the contract's `--run=`** (see Grounding #1) — confirm, or tell me to keep a literal `--run=` name.
2. **Per-pair source-mismatch handling** (continue processing other pairs) vs. whole-run refusal on any mismatch.
3. **Dry-run never calls the report path** (consistency with every other command) vs. allowing a read-only preview render since this path is lower-stakes than Slice 15's.
4. **`net_sales` is the actual field name available; `calculated_net_sales`/`net_amount` don't exist on this surface** — confirm this doesn't change the design, or clarify what you'd like renamed and where.
5. **Absolute `other_tax_before`/`other_tax_after` reported with a contamination caveat field, delta computed normally** — confirm this is the right way to honor FR-016 given the contamination cancels in the delta but not the absolute figures.

## Tests

- Pure delta/threshold-evaluation logic (no DB): amount-only trigger, percent-only trigger, both trigger, neither trigger, divide-by-zero-safe percent when `before.other_tax == 0`.
- Source-mismatch pair: persisted with null deltas, excluded from summary totals and flagging, run still completes and processes remaining pairs.
- No snapshot run available at all: refuses, zero writes.
- Idempotency: a completed materiality run for a given `snapshot_run_id` refuses a bare re-`--apply` (matching Slice 15); `--force` creates an independent new run without touching the prior one's records.
- Re-display with different `--threshold-amount`/`--threshold-percent` on an already-completed run changes which pairs are flagged without any new report-path call or DB write (assert via a spy/count on `SalesReportDataService`).
- Full rendered `after_rendered_result` is persisted verbatim (not just the `other_tax` field), for SC-003 drift-checking use later.

## Verification plan

Implement → targeted tests → full `--filter=Backfill` regression → Pint → Code Reviewer → fix findings → re-verify → Architect drift-revalidation (high-risk: new tables, new read path over financial data, FR-009a/FR-012a compliance) → update `tasks.md`/`cli-contract.md`/`live-run-readiness-plan.md` → commit.
