# Containment Plan — Scheduled-Job Interaction and Mid-Run States

**Date**: 2026-08-12 · **Status**: Documentation/procedure only — no code, no rehearsal, no live execution authorized by this document (T052a, T102; substantially closes T053)

## Purpose

Two things this feature needs before any live `--apply` sequence, beyond the rollback procedure (`rollback.md`) and the pre-run evidence capture (Slices 15/16): (1) an inventory of every scheduled job that can interact with a live run, with the exact pause/resume action for each — not just "be aware of this" (T052a); (2) naming the mid-run states an operator will actually observe, so a normal, expected state isn't mistaken for a failure (T102).

This closes most of T053's own scope too ("containment plan: kill-switch procedure, how to identify a partially-completed run, how to resume vs. roll back") without duplicating it — the kill-switch/resume mechanics are already documented in `live-run-readiness-plan.md` §9, and the resume-vs-rollback decision is already in `rollback.md` §8's decision points. This document adds what neither of those cover: scheduled-job interaction and mid-run state interpretation.

## 1. Scheduled-job inventory (verified against `routes/console.php`, not assumed from prior notes)

| Job | Schedule | What it does | Risk to this feature |
|---|---|---|---|
| `reports:refresh-daily-transaction-summaries --days=2` | Every 15 min | Refreshes the last 2 days' rows in `daily_transaction_summaries`, writes `report_refresh_states` | Low direct risk (only touches the last 2 days — the defect window is historical and outside that range for nearly the entire run) — but its writes to `report_refresh_states` are exactly what `SalesReportDataService`'s source-detection logic (`daily_transaction_summaries` vs `raw_transactions`) reads, which FR-012a's source-label pinning (Slice 15) depends on being stable between the pre-backfill snapshot and any later materiality computation. Not a mutation risk to backfill data itself. |
| `transactions-prune` (inline `Schedule::call()`, not a dedicated command) | Hourly | Deletes `validation_status = 'FAILED'` transactions older than `prune_failed_after_days` (default 14) **and** stale `validation_status = 'PENDING'` transactions older than `prune_pending_after_minutes` (default 180 min / 3 hours) | **Real, verified risk.** `fk_tx_taxes_pk` (`transaction_taxes.transaction_pk` → `transactions.id`) is confirmed `ON DELETE CASCADE` (`database/migrations/2025_08_13_000012_add_transaction_pk_foreign_keys.php:95`) — deleting a `transactions` row would silently cascade-delete its linked `transaction_taxes` rows, including ones this backfill just inserted. **`tax_backfill_records.transaction_pk` → `transactions.id` is a separate, RESTRICT foreign key with no cascade** (`2026_08_11_000001_create_tax_backfill_records_table.php`) — this is what actually protects the backfill: if this prune job's batch includes a transaction that already has a `tax_backfill_records` row, the **entire bulk `DELETE` statement fails on the FK violation**, not just that one row (mechanical protection, not a graceful per-row skip). Defect-window transactions are expected to already be `VALID`, not `PENDING`/`FAILED`, so real overlap should be rare — but "rare" is not "impossible," and a failed prune batch is itself an operational surprise worth avoiding during a maintenance window. |
| `transactions-watchdog` (inline `Schedule::call()`) | Every 5 min | Requeues stuck `PENDING`+`QUEUED` transactions after `requeue_after_minutes` (default 10 min); flips `PENDING` → `FAILED` after `max_pending_minutes` (default 60 min) | Feeds `transactions-prune`'s `FAILED` population — pausing the watchdog reduces how many transactions the pruner could ever match, independent of pausing the pruner itself. No direct write to `transaction_taxes`. |
| `tsms:reconcile-intake` (dedicated command) | **Every minute**, no flag | The highest-frequency job touching the intake↔transactions relationship — ~1,400 invocations across a full 59-day backfill window | Verified: `app/Console/Commands/ReconcileStrandedIntake.php` has **no config-driven enable/disable toggle** (unlike the two jobs above). Its ordinary per-minute behavior is unrelated to historical (already-settled) defect-window transactions; the risk is narrow, not a correctness threat to already-committed data. |
| `tsms:reconcile-intake --repair-missing` | Daily at 23:00 | Can **recreate** a transaction row (fresh id) if intake accepted it but no `transactions` row exists | If this ever fires for a defect-window transaction during the run, the recreated row would get a fresh id with no relationship to any existing orphan (orphans were never linked to any id at all, so this isn't an id-collision risk) — but it would appear in `transactions` **after** any evidence already captured (Slices 15/16's snapshots), meaning that evidence would slightly undercount. `TaxBackfillRunner::apply()` scans `transactions` live at run time, so the transaction would still get backfilled correctly if it falls within a day not yet processed — the risk is evidence-consistency (a snapshot slightly stale relative to a very-late-arriving row), not data corruption. |
| `tenant-inactivity-alerts` | Every 15 min | Read-only notification check against **current** tenant activity | No interaction — checked and confirmed unrelated; not included as a containment risk. |
| `reporting:refresh transactions_hourly --hours=6` | Daily at 02:30 | Refreshes the separate `transactions_hourly`/`transactions_daily` rollup (a different table/pipeline than `daily_transaction_summaries`) | Low risk — `transactions_hourly` derives tax from `transactions` columns directly, not from `transaction_taxes` (already established elsewhere in this feature, Architect F3), so it's structurally unaffected by anything this backfill inserts. Already gated by an existing toggle: `config('tsms.reporting.enabled')` (`->when(...)` in `routes/console.php`). Included here for inventory completeness, not because it needs pausing. |
| `reporting:dispatch --minutes=15 --chunk=5` | Every 5 min | Dispatches incremental reporting jobs | **Confirmed dead code** — `ReportingDispatchCommand::handle()` is a documented no-op (already noted elsewhere in this feature, Architect F3: "`RefreshHourlyWindowJob` has been removed"). Listed for inventory completeness; genuinely zero risk since it does nothing. |

## 2. Pause / resume actions (exact, verified — not "be careful")

### `transactions-prune` — clean, no-deploy pause

```bash
# In .env:
TX_ENABLE_PRUNING=false
```
Then `php artisan config:clear` (or `config:cache` if this environment caches config) for the change to take effect. Confirmed via `config/tsms.php:15` (`'enable_pruning' => (bool) env('TX_ENABLE_PRUNING', true)`) and the scheduled closure's own guard (`if (!($cfg['enable_pruning'] ?? true)) { return; }`, `routes/console.php`).

**Resume**: set `TX_ENABLE_PRUNING=true` (or remove the override), `config:clear`/`config:cache` again.

### `transactions-watchdog` — clean, no-deploy pause

```bash
# In .env:
TX_WATCHDOG_ENABLED=false
```
Same `config:clear`/`config:cache` requirement. Confirmed via `config/tsms.php:19` and the watchdog closure's own guard.

**Resume**: set `TX_WATCHDOG_ENABLED=true`, clear/cache config again.

### `tsms:reconcile-intake` (both variants) — **no existing toggle; documented gap, not a false claim of one**

Unlike the two jobs above, this command has no config-driven enable flag. Two real options, with an honest tradeoff between them:

1. **Temporarily remove or comment out** the two `Schedule::command('tsms:reconcile-intake'...)` entries in `routes/console.php` for the maintenance window — requires a code change and a deploy, and must be reverted (another deploy) once the window ends. This is the only way to guarantee zero invocations during the run.
2. **Accept the risk and leave it running** — per the inventory above, the per-minute job's ordinary behavior doesn't threaten already-settled historical data, and the daily `--repair-missing` variant's worst case is a narrow evidence-staleness issue, not corruption. If choosing this option, note the exact time `--repair-missing` last ran (23:00 the day before) relative to when pre-run evidence (Slices 15/16) was captured, so a late-arriving row can be explained rather than mistaken for an anomaly.

This gap (no toggle) is a reasonable candidate for a small, separate follow-up enhancement — not something to build as part of this documentation-only slice.

## 3. Transaction-count census (pre/post — a genuine correctness signal, not a wellness check)

The backfill never creates or deletes `transactions` rows — only `transaction_taxes` rows. **A `transactions` count within the defect window that differs between the start and end of the run is itself evidence something outside this feature's own control touched the window** (most likely one of the jobs above, if left unpaused).

```sql
SELECT validation_status, COUNT(*) AS cnt
FROM transactions
WHERE created_at >= '<window_start>' AND created_at < '<window_end>'
GROUP BY validation_status;
```

Run this once immediately before the run starts and once immediately after it ends (for the whole window, not per-day — a per-day re-run of this query would itself add operational noise for little benefit). Any delta in the total count, or a `validation_status` distribution shift, needs an explanation before the run's evidence is trusted — this is a secondary corroborating check alongside Slice 16's `pre_run_integrity_captures` baseline, not a replacement for it (that baseline is about `transaction_taxes` duplication/orphan integrity, not `transactions` row count stability).

## 4. The mid-run "zero rows written" state (T102) — corrected from an earlier draft of this section

**Original, precise scope (per `live-run-readiness-plan.md` §9, where T102 was first raised)**: partway through a specific day's `--apply` run, an operator watching the backfill's own progress — `TaxBackfillRun`'s `reconstructed_count`, or the `--json` output of `transactions:backfill-taxes` — may see **zero or near-zero new inserts**, even though the run is healthy. This happens legitimately when a day's transactions were mostly `skipped_existing` (already had linked rows from a prior manual correction or a prior partial run) or `quarantined` (no recoverable payload) rather than newly `applied`. **Zero new inserts is not itself evidence of a stall** — check the outcome breakdown (`applied` / `skipped_existing` / `quarantined` / `failed` counts), not just the raw insert count, before concluding anything is wrong.

**A separate, narrower claim about external reports (worth stating, but do not conflate the two)**: an earlier draft of this section claimed defect-window days show "zero tax figures" in ordinary reports/dashboards generally, before this backfill runs. **That is inaccurate and contradicts this feature's own established finding** (spec.md, "Business Case Re-baseline"): `vat_amount`, `vatable_sales`, and `sc_vat_exempt_sales` are sourced directly from `transactions` columns (not `transaction_taxes`) and were **already correct throughout the defect window** — they do not show zero, and this backfill does not change them. **Only `other_tax`** (and `sc_vat_exempt_sales` for non-standard alias variants outside the ingestion path's accepted list) genuinely shows the incomplete-looking figure pre-backfill, because it's the one component whose payload-derived fallback excludes exactly the `OTHER_TAX`/`OTHER-TAX` vocabulary the defect affected (research.md V4, spec.md N1). If an operator observes `other_tax` looking low/zero for a defect-window day before that day's backfill (and refresh) has completed, that is expected and unchanged from what's been true for weeks — but do not extend that same "expected, don't worry" framing to VAT/vatable/SC-VAT-exempt figures, which were never wrong and any unexpected movement there is a real anomaly, not a benign mid-run state.

Do not treat "day D's `other_tax` still looks low" as evidence of a stall unless the per-day loop has actually reported that day's insert step as complete (`TaxBackfillRun.status` for that day's run — see `live-run-readiness-plan.md` §9 for how to read run status), the aggregate refresh has run for that day, and a reasonable processing time has elapsed.

## Cross-references

- `live-run-readiness-plan.md` §7 (pause/containment summary — points here for detail) and §9 (kill-switch/resume mechanics, and T102's original placement note).
- `rollback.md` §8-equivalent (decision points for when to invoke rollback vs. continue) — this document does not duplicate that decision logic.
- `slice-15-pre-backfill-snapshot-brief.md` / `slice-16-integrity-evidence-capture-brief.md` — the evidence artifacts §3's census corroborates.
