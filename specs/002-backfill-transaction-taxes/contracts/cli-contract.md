# Interface Contract: Backfill CLI

**Date**: 2026-08-10

The only external interface this feature exposes is operator-facing console commands. There is no HTTP surface, no tenant-facing UI, and no new queue-consumer contract.

Ergonomics deliberately mirror `app/Console/Commands/LicenseBindingBackfillCommand.php` (research R7) so operators encounter no new conventions.

## Command 1 — Reconstruct tax rows

```
transactions:backfill-taxes
    {--from= : Window start (Y-m-d). Required unless --day given.}
    {--to= : Window end, exclusive (Y-m-d). Required unless --day given.}
    {--day= : Single day (Y-m-d). REQUIRED for --apply runs (FR-014a).}
    {--tenant= : Restrict to one tenant id. Repeatable.}
    {--apply : Persist changes. WITHOUT THIS FLAG THE COMMAND IS DRY-RUN ONLY.}
    {--chunk=500 : Transactions per chunk.}
    {--limit= : Stop after N transactions (piloting).}
    {--json : Machine-readable output.}
```

**Contract guarantees**

| Guarantee | Requirement |
|-----------|-------------|
| Dry-run by default | Omitting `--apply` MUST perform zero writes to `transaction_taxes`. |
| Idempotent | Re-running over the same window MUST NOT create duplicate rows (FR-004). |
| Non-destructive | MUST NOT update or delete any **linked** `transaction_taxes` row; inserts only where the transaction has zero linked rows (FR-003). NULL-keyed orphan deletion is Command 5's job, never this command's. |
| Resumable | Interruption MUST NOT corrupt state; re-invocation continues safely (R6). |
| Bounded | MUST NOT hold a long-lived transaction or table-wide lock on `transactions`/`transaction_taxes` (FR-005, R9). |
| Pre-flight | MUST validate required columns present (T018 — input/window validation only today; general column-existence checking remains open), and `idx_tx_taxes_pk`, `fk_tx_taxes_pk` (+ its `ON DELETE` action) and `transaction_pk` nullability (T097 — **DONE, Slice 9, 2026-08-11**: `TaxBackfillPreflightChecker` gates on index/FK presence, records `ON DELETE` action and nullability, all persisted on the run record); fail non-zero before any mutation. |
| **Day-scoped apply** | `--apply` MUST require `--day` (FR-014a). A whole-window `--apply` MUST be rejected — it would write ~3.24M rows before any reconciliation could run. Dry-run may span the full window. |
| Fail-safe | A transaction whose payload is missing, unparseable, or inconsistent with the R3 cross-check MUST be recorded as `quarantined`, never guessed at. |

**Exit codes**: `0` success (including a clean dry-run); non-zero on pre-flight failure, or when any transaction ended `failed`.

**Output** (both modes): counts of scanned / reconstructable / already-present / quarantined / failed, plus per-tenant totals. `--json` emits the same data structurally.

## Command 2 — Verify reconstruction against known-good data

```
transactions:verify-tax-reconstruction
    {--from= : Window start (Y-m-d), default: post-fix period}
    {--sample=500 : Transactions to check}
    {--json}
```

Read-only. Runs the reconstruction logic against **post-fix** transactions (2026-08-10 ~10:00 onward) that already have correct tax rows, and reports any divergence in `tax_type`/`amount` between reconstructed and actual (research R4, FR-008).

**This command must report zero divergences before Command 1 is ever run with `--apply`.** It is the feature's primary safety gate.

## Command 3 — Materiality report

```
transactions:tax-backfill-materiality
    {--run= : Backfill run identifier}
    {--threshold-amount=500}
    {--threshold-percent=1}
    {--json}
```

Read-only. Emits per-(tenant, month) before/after tax totals and flags those crossing either threshold (FR-009a). Sending notifications is **not** part of this command — it produces the defensible list only (SC-006); dispatch is a separate, human-triggered step.

## Command 4 — Inspect corrections and quarantined rows

```
transactions:tax-backfill-show
    {--transaction= : Show correction history for one transaction (by transactions.id)}
    {--run= : Restrict to one backfill run}
    {--quarantined : List quarantined (unreconstructable) rows instead}
    {--json}
```

Read-only. Serves US3 (auditability) and makes the 216 quarantined rows reviewable rather than buried in a counter. Follows the `IngestionQuarantine{List,Show}` precedent.

**Contract guarantees**: read-only; never mutates tax rows or audit records; returns non-zero only on invalid arguments.

## Command 5 — Archive and delete orphan tax rows

```
transactions:archive-orphan-taxes
    {--phase= : archive | reconcile | delete  (required)}
    {--day= : Single day (Y-m-d). REQUIRED for reconcile and delete.}
    {--apply : Persist. WITHOUT THIS FLAG THE COMMAND IS DRY-RUN ONLY.}
    {--chunk=1000}
    {--json}
```

Handles the 3,238,180 NULL-keyed rows (research.md V4). **Insert-first**: `archive` runs before the backfill; `reconcile` and `delete` run only after reconstructed rows are in place.

| Guarantee | Requirement |
|-----------|-------------|
| Ordering | `delete` MUST refuse to run unless `archive` completed **and verified**, and `reconcile` passed **for that same `--day`**. The interlock is per-day, not per-run |
| Uniform deletion *(revised 2026-08-11)* | Every day, including 2026-06-13, is deleted once its reconciliation passes — reconciled-set rows because a proven replacement exists, residual-set rows (216 transactions, no replacement) because their archive write is independently verified. **No day-level exception remains.** A failed archive-write verification blocks deletion for that day regardless of which set is affected |
| Archive fidelity | Archive MUST preserve `id`, `tax_type`, `amount`, timestamps, plus reconciliation metadata (reconciled vs. residual, reason code where applicable); restoring MUST reproduce pre-run state (FR-013) |
| Reconcile | Inserted rows MUST reproduce the orphans' per-(`created_at` second, `tax_type`, `amount`) multiset, evaluated **per day**; mismatch halts before any other day is touched (FR-014). Inserted `created_at` is **the parent transaction's `created_at`**, never `now()` (research.md V5) — using insertion time would fail this check by construction, since orphans are dated across the defect window, not today. The residual (unmatched orphans) MUST equal exactly the day's unreconstructable-transaction count — an unexplained residual also halts. |
| Delete predicate | Strictly `transaction_pk IS NULL`. MUST NOT delete any linked row (FR-015). Applies uniformly to reconciled and residual rows alike once their respective verification passes |
| Chunking | Bounded chunks only; never a single bulk `DELETE` (2026-08-10 lock-contention precedent) |
| Authorization | `--phase=delete --apply` requires the derived token — the reconcile result hash, re-computed server-side (Architect Q4) |

## Command 6 — Snapshot pre-backfill aggregates

```
transactions:snapshot-pre-backfill-aggregates
    {--from=} {--to=} {--json}
```

Captures per-(tenant, month) **rendered** aggregate totals via the actual report path (FR-012). Read-only against reporting; writes only its own snapshot store. **Must run before any mutation — this baseline is unrecoverable afterwards** and is the sole legitimate `before` for materiality.

## Aggregate recomputation

No new command. Reuses existing entry points, scoped to the corrected window:

```
php artisan reports:refresh-daily-transaction-summaries   # daily_transaction_summaries
php artisan reporting:refresh <table> --hours=<n>          # hourly/rollup tables
```

Only `daily_transaction_summaries` is genuinely affected (`other_tax`; `sc_vat_exempt_sales` for alias variants). `transactions_hourly` derives tax from `transactions` columns and does not change; `RefreshHourlyWindowJob` is a deprecated no-op (Architect F3). Sequencing requires asserting the aggregating connection is the primary (T077) rather than waiting on replica lag (Architect F4).

## Non-contracts

- No HTTP endpoint is added.
- No change to `POST /v1/transactions/official` or any ingestion route.
- No change to the `transaction-intake:s{N}` / `transaction-processing:s{N}` queue contracts; the backfill MUST NOT dispatch onto them (R9).
