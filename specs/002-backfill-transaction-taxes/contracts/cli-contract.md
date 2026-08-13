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
| Pre-flight | MUST validate required columns present (T018 — **DONE, Slice 10, 2026-08-11**: `TaxBackfillPreflightChecker::checkRequiredColumns()`, run from both dry-run and apply), and `idx_tx_taxes_pk`, `fk_tx_taxes_pk` (+ its `ON DELETE` action) and `transaction_pk` nullability (T097 — **DONE, Slice 9, 2026-08-11**: `TaxBackfillPreflightChecker::check()` gates on index/FK presence, records `ON DELETE` action and nullability, apply-only); all persisted on the run record (`preflight_checks`); fail non-zero before any mutation. |
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
    {--snapshot-run= : pre_backfill_snapshot_runs.id to use as the before baseline.
                       Defaults to the most recently completed run for the
                       standard (snapshot_type, report_contract_version) key.}
    {--threshold-amount=500}
    {--threshold-percent=1}
    {--apply : Capture the after-render and persist deltas. Without this
               flag: preview only if nothing has been captured yet, or a
               full read-only display (freshly evaluated thresholds) if a
               completed run already exists.}
    {--force : Required to start a new materiality run when a completed one
               already exists for the identical snapshot run.}
    {--json}
```

**Implemented Slice 19 (2026-08-13), see [slice-19-materiality-brief.md](../slice-19-materiality-brief.md).** `--snapshot-run=` replaces this row's originally-drafted `--run=` (written 2026-08-10, before Slice 15's `pre_backfill_snapshot_runs` existed — no other run entity actually enumerates a tenant/month population). Emits per-(tenant, reporting month) before/after `other_tax` totals (the FR-009a-affected component; not a full multi-component "tax total") and flags those crossing either threshold. Sending notifications is **not** part of this command — it produces the defensible list only (SC-006); dispatch is a separate, human-triggered step.

**Contract guarantees**

| Guarantee | Requirement |
|-----------|-------------|
| Read-only against source data | Never mutates `transactions`, `transaction_taxes`, `daily_transaction_summaries`, or Slice 15's `pre_backfill_snapshot_*` tables — writes only its own two tables. |
| Before is the FR-012 snapshot, never zero | Population is defined by a specific completed `pre_backfill_snapshot_runs` row; refuses if none exists or the referenced one isn't completed. |
| Source-pinning refusal (FR-012a) | Per-pair, not whole-run: a `before`/`after` source mismatch is recorded as `comparison_status = source_mismatch` (delta fields null, excluded from flagging and summary totals) and does not block other pairs. |
| Threshold evaluation is read-time only | The materiality flag is never persisted — only the raw before/after/delta evidence is (once per snapshot run, idempotent via `--apply`/`--force`). Re-running with different `--threshold-amount`/`--threshold-percent` against an already-completed run re-evaluates flags from stored deltas with zero new report-path calls or writes (SC-006 reproducibility). |
| Dry-run | Never calls the report path when nothing has been captured yet (preview only: population/pending counts). Re-invoking against a snapshot run with an already-*completed* materiality run is not an error — it is a full read-only display with freshly evaluated thresholds, since that is a pure DB read. |
| Idempotent | A completed materiality run for a given snapshot run refuses a bare re-`--apply` (`REFUSED`, zero writes) unless `--force`, which creates a new, independent run without touching the prior one's records. |
| `tax_exempt` caveat (FR-016) | Absolute `other_tax_before`/`other_tax_after` individually include a boolean-summed `transactions.tax_exempt` contribution (a pre-existing, out-of-scope defect) and are reported with an explicit caveat field; the delta between them is unaffected, since this feature never modifies `transactions.tax_exempt`. |

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
    {--token= : REQUIRED when --phase=delete --apply is set. Must equal the
                authorization_token a --phase=delete dry-run reports for the
                same --day (T079).}
    {--chunk=1000 : Archive phase's insert chunk size, and delete phase's
                    DELETE chunk size.}
    {--json}
```

Handles the 3,238,180 NULL-keyed rows (research.md V4). **Insert-first**: `archive` runs before the backfill; `reconcile` and `delete` run only after reconstructed rows are in place.

| Guarantee | Requirement |
|-----------|-------------|
| Ordering | `delete` MUST refuse to run unless `archive` completed **and verified**, and `reconcile` passed **for that same `--day`**. The interlock is per-day, not per-run |
| Uniform deletion *(revised 2026-08-11)* | Every day, including 2026-06-13, is deleted once its reconciliation passes — reconciled-set rows because a proven replacement exists, residual-set rows (216 transactions, no replacement) because their archive write is independently verified. **No day-level exception remains.** A failed archive-write verification blocks deletion for that day regardless of which set is affected |
| Archive fidelity | Archive MUST preserve `id`, `tax_type`, `amount`, timestamps, plus reconciliation metadata (reconciled vs. residual, reason code where applicable); restoring MUST reproduce pre-run state (FR-013) |
| Reconcile *(revised 2026-08-12 — Slice 13/T069)* | An inserted row and an orphan match when their (`tax_type`, `amount`) are equal and `TIMESTAMPDIFF(SECOND, parent.created_at, orphan.created_at)` is in **[0, 10]** — a measured tolerance (research.md V5), not an exact-second match. Evaluated **per day**, per (`tax_type`, `amount`) bucket, via greedy two-pointer matching — never per-row transaction attribution (research.md V4). Every unmatched orphan is classified `no_replacement_exists`, `timestamp_out_of_tolerance`, or `orphan_content_mismatch` (FR-014); only the last halts, decided by a day-level count cross-check against the known `missing_payload` transaction count, never a per-row guess. Inserted `created_at` is **the parent transaction's `created_at`**, never `now()` — using insertion time would fail this check by construction, since orphans are dated across the defect window, not today. |
| Delete predicate | Strictly `transaction_pk IS NULL`. MUST NOT delete any linked row (FR-015). Applies uniformly to reconciled and residual rows alike once their respective verification passes |
| Chunking | Bounded chunks only; never a single bulk `DELETE` (2026-08-10 lock-contention precedent) |
| Authorization | `--phase=delete --apply` requires `--token=` matching a day-bound sha256 hash of that day's persisted, archived reconciliation verdict (`original_id`/`reconciled_status`/`reason_code`, day-prefixed) — recomputed server-side and compared with `hash_equals()`, never cached or trusted from a prior call. A token captured for one day is structurally rejected against any other day (Architect Q4, T079) |

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

## Command 7 — Capture integrity evidence

```
transactions:capture-integrity-evidence
    {--from=} {--to=} {--phase=pre_run : pre_run | post_run}
    {--apply} {--json}
```

**Documented here retroactively (2026-08-13) — built Slice 16, 2026-08-12; not previously added to this contract.** Read-only against `transaction_taxes`/`transactions`; writes only its own `pre_run_integrity_captures` table, and only with `--apply`. Persists the corrected T062 duplicate-check baseline (whole-table, `WHERE transaction_pk IS NOT NULL` mandatory), the verbatim `txn:pk-integrity` report, and — added Slice 20 (2026-08-13) — a structured `transaction_taxes_null_count` field (`COUNT(*) WHERE transaction_pk IS NULL`), so Command 8 below never has to parse the verbatim report text.

**Required operator sequencing for Command 8**: run this command with `--phase=pre_run --apply` before any mutation, and again with `--phase=post_run --apply` after the backfill/reconcile/delete/refresh sequence completes — Command 8 is a pure reader and never invokes this command itself.

## Command 8 — Readiness verdict (T076)

```
transactions:tax-backfill-readiness-verdict
    {--from=} {--to=} {--tenant=}
    {--snapshot-run=} {--materiality-run=}
    {--pre-run-capture-id=} {--post-run-capture-id=}
    {--backup-drill-confirmed}
    {--json}
```

**Built Slice 20 (2026-08-13), see [slice-20-readiness-verdict-brief.md](../slice-20-readiness-verdict-brief.md).** The feature's final evidence-gate: a pure reader over already-persisted evidence from Commands 3, 6, and 7 plus one manual attestation (T054's backup drill, which has no database representation). **No `--apply` flag — this command never writes anything, ever.** Produces a `pass`/`warn`/`fail` verdict, one block per evidence source.

**FAIL conditions** (blocking, non-zero exit): missing pre-run or post-run capture (Command 7); post-run `transaction_taxes_null_count` non-zero or absent (predates the field); post-run duplicate rows exceeding the pre-run baseline (Architect F8 — a secondary signal, not an absolute-zero assertion); no connection-identity evidence (`report_refresh_states.server_id`/`database_name`, T077/Command's own gate) for the window/tenant scope.

**WARN conditions** (non-blocking, exit 0 — a human decision point, not a command failure): `--snapshot-run` omitted or its materiality run missing/incomplete; materiality records with `comparison_status = source_mismatch`; `--backup-drill-confirmed` not passed.

## Non-contracts

- No HTTP endpoint is added.
- No change to `POST /v1/transactions/official` or any ingestion route.
- No change to the `transaction-intake:s{N}` / `transaction-processing:s{N}` queue contracts; the backfill MUST NOT dispatch onto them (R9).
