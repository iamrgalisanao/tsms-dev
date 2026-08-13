# Rollback and Backup Runbook — Backfill Transaction Taxes

**Date**: 2026-08-12, drill executed 2026-08-13 · **Status**: T052 documentation/procedure only, no live execution authorized by this document. **T054 closed** — the Mechanism 2 backup/restore drill has been executed once (local dev environment, see "Drill executed" below) and passed; Mechanism 1 (the primary rollback path) remains procedure-only, never executed against real data.

## Purpose

This is the operator runbook for two things this feature explicitly requires before any live `--apply` run: (1) how to reverse this backfill's own mutations if something goes wrong mid-run or is discovered wrong after (T052), and (2) what a "verified" backup actually means, precisely, not just "the command exited zero" (T054). It complements `live-run-readiness-plan.md`'s §6 (backup) and §8 (rollback decision points) with the actual commands and procedure those sections point to.

**Two independent rollback mechanisms exist, and they are not interchangeable — read this before doing anything.**

## Mechanism 1 (primary): targeted, audit-record-driven undo

This is what you use for essentially every real rollback scenario — a specific day's or run's mutation needs reversing, while live ingestion has kept moving in the background. It never touches a row this backfill didn't itself create or delete, and stays correct no matter how much time has passed since the run.

### Part A — undo inserted rows

**Why this is safe without a stored list of inserted row ids**: `tax_backfill_records.outcome = 'applied'` only ever happens for a transaction whose `had_linked_rows_before = false` — i.e., a transaction that had **zero** linked `transaction_taxes` rows before this run touched it (`TaxBackfillRunner::processTransactionForApply()`, confirmed against the actual insert precondition — FR-003 forbids inserting when any linked row already exists). This means: for any `transaction_pk` with `outcome = 'applied'` under a given run, **every currently-linked row for that transaction_pk is exactly what this run inserted** — nothing else could have put it there. You don't need a stored row-id list; the audit trail already proves the full set.

```sql
-- 1. Identify the run(s) to roll back. Each --day=$D --apply invocation
--    creates its own TaxBackfillRun row (mode='apply'), so rolling back
--    day D means rolling back exactly that run.
SELECT id, window_start, window_end, status
FROM tax_backfill_runs
WHERE mode = 'apply' AND window_start = '<day D 00:00:00>';
-- -> note the run id(s), call it <RUN_ID>

-- 2. tax_backfill_records.id is a GLOBAL auto-increment shared across every
--    run/tenant/day -- an arbitrary BETWEEN window does not bound a batch to
--    THIS run's rows. Query this run's own id range first:
SELECT MIN(id), MAX(id) FROM tax_backfill_records
WHERE run_id = <RUN_ID> AND outcome = 'applied';
-- -> chunk WITHIN that [min_id, max_id] range below, never across it.

-- 3. Delete, in bounded chunks (never one bulk statement, matching this
--    feature's chunking discipline everywhere else), still filtered by
--    run_id and outcome so a chunk boundary landing on another run's ids
--    can never affect a row outside this run.
DELETE tt FROM transaction_taxes tt
JOIN tax_backfill_records r ON r.transaction_pk = tt.transaction_pk
WHERE r.run_id = <RUN_ID>
  AND r.outcome = 'applied'
  AND r.id BETWEEN <chunk_start_id> AND <chunk_end_id>;
```

Operator note: this JOIN-DELETE is a template, not a copy-paste-ready script — whoever executes a real rollback should wrap the chunk bounds in a loop (mirroring `OrphanTaxDeleter`'s own chunked-delete shape) and log each chunk's affected-row count. **Do not write this as a single unbounded `DELETE`** — the 2026-08-10 incident's failure mode (a long-running statement queuing behind live traffic) applies here exactly as much as it did to the forward run.

**Concurrency assumption, explicit**: like the rest of this feature (Architect Q9 — synchronous, single-operator command, no queue), this rollback procedure assumes no other `--apply` invocation is running concurrently against the same day/transactions while a rollback executes. Confirm the target day's run is not still active (Part A's own `SELECT` on `tax_backfill_runs.status` should show a terminal status, never `running`) before proceeding — this is the same operational model the forward run already relies on, not a new gap this runbook introduces.

**Do not delete `tax_backfill_records` rows themselves.** They are the audit trail proving what happened and are what makes this whole rollback procedure possible in the first place — deleting them alongside their `transaction_taxes` rows would destroy the evidence of what needs undoing.

### Part B — restore archived orphans

Only relevant for a day whose orphans have already been deleted (Stage 3, `OrphanTaxDeleter`). If Stage 3 hasn't run for that day yet, there's nothing to restore — the orphans are still live.

```sql
INSERT IGNORE INTO transaction_taxes (id, transaction_pk, tax_type, amount, created_at, updated_at)
SELECT original_id, transaction_pk, tax_type, amount, created_at, updated_at
FROM transaction_taxes_orphan_archive
WHERE created_at >= '<day D 00:00:00>' AND created_at < '<day D+1 00:00:00>';
-- Identical [dayStart, dayEnd) boundary convention as OrphanTaxReconciler/
-- OrphanTaxDeleter (no buffer) -- see slice-13/14 briefs. Do not invent a
-- different day-boundary definition here.
```

- **`INSERT IGNORE` on the explicit primary-key `id` is the idempotency mechanism** — a row still present in `transaction_taxes` (never deleted, or already restored) is silently skipped via the primary-key collision; only genuinely-missing rows get re-inserted. Safe to re-run.
- **Explicitly inserting a value into an `AUTO_INCREMENT` primary key column is standard, supported MySQL behavior** — flagging it only so whoever runs this doesn't second-guess seeing a non-sequential id appear; MySQL's auto-increment counter simply continues from the highest value it has seen afterward.
- **The archive rows themselves are never modified by this step.** `transaction_taxes_orphan_archive` remains the permanent evidence record regardless of what happens in the live table afterward — this restore only copies from it, never writes to it.

### Part C — re-run the aggregate refresh (mandatory, corrects an earlier mischaracterization)

**This corrects the earlier Architect F11 wording** ("`daily_transaction_summaries` merges with `max()`, so aggregates are monotonic — deleting rows alone does not lower a previously-reported figure"), which was verified against the current code (2026-08-12) and does not hold: `RefreshDailyTransactionSummaries::handle()` **deletes the affected date range and rebuilds it entirely from current source data** on every run (`DB::table('daily_transaction_summaries')->whereBetween(...)->delete()`, then fresh `insert()`s). Its `max()` expressions (`other_tax`, `promo_with_approval`, etc.) combine multiple sources **computed within that same refresh call** — a raw SQL sum from `transactions.tax_exempt`, a raw SQL sum from a `transaction_taxes` JOIN, and a JSON-payload-derived value — never a previously-persisted summary value. There is no cross-refresh ratchet.

**Practical consequence for rollback**: a post-rollback refresh should correctly reflect the restored, lower values directly — it is not fighting a stale high-water mark. The refresh is still mandatory (Parts A and B alone don't touch `daily_transaction_summaries` at all — the stale figures simply sit there, computed from the pre-rollback data, until a refresh recomputes them), but the reason is "nothing recomputes this table until you ask it to," not "values only go up."

**A rollback is not complete until `reports:refresh-daily-transaction-summaries` has been re-run for the affected window/tenants, after the data-level rollback (Parts A and B), not before.** Report a rollback as "done" only after this refresh has run and the affected figures have been spot-checked against the pre-run snapshot (Slice 15's `pre_backfill_snapshot_records`, if one was captured for the affected tenant/month).

### Order of operations for a real rollback

1. Stop any in-progress run first (kill-switch, `TaxBackfillRunner::apply()`'s `--kill-switch-path`) — don't roll back a day that's still actively being written to.
2. Determine exactly which day(s)/run(s) are in scope. Rolling back is per-day and independent — order across days doesn't matter for correctness, but doing it methodically (one day fully verified before moving to the next) matches this feature's own per-day discipline and makes a mistake easier to isolate.
3. Part A (undo inserts) for each affected day.
4. Part B (restore archived orphans) for each affected day — safe to run even if Stage 3 never reached that day (a no-op, since `INSERT IGNORE` finds nothing missing).
5. Verify per day: `transaction_pk IS NULL` orphan count for that day is back to its pre-run figure (compare against the Slice 16 `pre_run_integrity_captures` baseline, or the archive's own count for that day, if no explicit pre-run baseline was captured for this exact scenario); zero `tax_backfill_records`/`transaction_taxes_orphan_archive` rows were deleted.
6. Part C (aggregate refresh) for the whole affected window/tenant set, once every affected day's Parts A/B are done — not per-day, to avoid re-running an expensive refresh once per rolled-back day.
7. Only then report the rollback complete.

## Mechanism 2 (last resort): full backup/restore

**This is not the primary rollback mechanism, and using it is a materially bigger decision than Mechanism 1.** A `mysqldump`-style backup captures a point-in-time snapshot; restoring it discards **everything** that happened in the live database after the backup was taken — including any legitimate, unrelated transactions that arrived during the run. Mechanism 1 never has this problem, because it only touches rows this specific run created or deleted. Reach for Mechanism 2 only if Mechanism 1 itself is somehow unsafe or insufficient (e.g. a bug corrupted data outside what the audit trail can identify) — and treat invoking it as its own escalation, requiring the same explicit authorization as the live run itself, not a routine step.

### Backup (T054)

```bash
mysqldump --single-transaction --quick --no-tablespaces \
  -h <host> -u <user> -p <database> \
  transactions transaction_taxes \
  tax_backfill_runs tax_backfill_records \
  transaction_taxes_orphan_archive \
  pre_backfill_snapshot_runs pre_backfill_snapshot_records \
  pre_run_integrity_captures \
  > backup_<window>_<timestamp>.sql
```

**`--single-transaction` is mandatory, not a performance nicety.** Without it, `mysqldump` takes table-level locks for a non-InnoDB-safe dump — exactly the kind of lock contention this whole feature exists to avoid on `transactions`/`transaction_taxes` (research R9, the 2026-08-10 incident). With it, the dump reads a consistent InnoDB snapshot without blocking concurrent DML (inserts/updates/deletes) on these tables. **Caveat**: it does not protect against concurrent DDL (a schema-changing statement, e.g. a migration) on the dumped tables while the dump is running — don't run this backup at the same moment as a migration.

All eight tables are captured together, from the same dump invocation, so a restore reconstructs one mutually consistent point in time — restoring `transaction_taxes` from one dump and `tax_backfill_records` from a different, later one would desynchronize the audit trail from the data it describes.

### Restore — verification drill (required before this backup is ever trusted)

**"Verified" means a restore has actually been performed and checked, not that the backup command exited zero.** Do this once per backup-tooling setup (and again if the tooling/environment changes) — not before every single live run.

1. Restore into an **isolated, separate database** — never the live one:
   ```bash
   mysql -h <host> -u <user> -p <new_isolated_database> < backup_<window>_<timestamp>.sql
   ```
2. Run this feature's own integrity tooling against the restored copy, reusing Slices 15/16 rather than inventing new verification:
   ```bash
   php artisan transactions:capture-integrity-evidence --from=<window_start> --to=<window_end> --json
   ```
   (Point the connection at the isolated restored database for this check — do not run it against the live database expecting it to validate the restore.)
3. Compare the restored copy's evidence against the `pre_run_integrity_captures` row captured from the **original** database at backup time — `total_duplicate_groups`/`total_duplicate_rows` and the `txn:pk-integrity` report should match exactly (same point-in-time data, no reason for them to differ).
4. Spot-check row counts for the dumped tables between the isolated restore and the original database (allowing for further-in-time drift on the *original* if this drill runs later than the backup) — a restore that produces materially different counts on the same historical window failed the drill.
5. Document the drill's outcome (pass/fail, date, backup tooling version) somewhere durable — this runbook does not mandate a specific log location, but "we assumed it worked" is not evidence it did.

### Drill executed — 2026-08-13 (T054, local dev environment)

**Outcome: PASS.** First actual execution of this drill (previously documented but not run). Recorded here as the durable evidence log this section calls for.

**Environment caveat, stated explicitly**: this drill ran against this local dev environment's single MySQL server (Herd-managed, MySQL 8.0.32, `mysqldump` 8.4.2) — source (`tsms_dev`) and restore target (`tsms_restore_drill`) are two databases on the *same* server, not genuinely separate hosts. A staging/production drill should repeat this against a real separate restore target; this local run verifies the *procedure and tooling* (dump scope, restore mechanics, cross-check via Slice 16 tooling), not cross-host restore behavior.

1. **Backup**: `mysqldump --single-transaction --quick --no-tablespaces` against `tsms_dev`, all 8 tables from Mechanism 2's list, 365-line dump, zero errors/warnings on stderr.
2. **Pre-backup evidence** (captured on `tsms_dev` immediately before the dump, via `transactions:capture-integrity-evidence --from=2026-06-13 --to=2026-08-10 --apply --json`, `capture_id=1`):
   - `duplicate_check_summary`: `total_duplicate_groups=0`, `total_duplicate_rows=0`.
   - `txn:pk-integrity` (embedded verbatim): `transaction_taxes (taxes): total=44 nulls=13 (29.55%) orphans=13 (29.55%)`; `transaction_adjustments`/`transaction_jobs`/`transaction_validations` all `total=0`; `Total child rows: 44`.
   - Connection identity: `server_id=1`, `DATABASE()=tsms_dev`.
   - Row counts: `transactions=119`, `transaction_taxes=44`, `tax_backfill_runs=58`, `tax_backfill_records=132`, `transaction_taxes_orphan_archive=13`, `pre_backfill_snapshot_runs=0`, `pre_backfill_snapshot_records=0`, `pre_run_integrity_captures=1` (the row just captured in step 2, correctly included since the dump ran after).
3. **Restore**: `CREATE DATABASE tsms_restore_drill`, then `mysql tsms_restore_drill < backup_t054-drill_20260813.sql`. Zero errors.
4. **Restored-copy identity**: `server_id=1` (matches source — same physical server, expected per the environment caveat above), `DATABASE()=tsms_restore_drill` (correctly a distinct database, proving this is genuinely a separate copy, not the live table).
5. **Restored-copy row counts**: `transactions=119`, `transaction_taxes=44`, `tax_backfill_runs=58`, `tax_backfill_records=132`, `transaction_taxes_orphan_archive=13`, `pre_backfill_snapshot_runs=0`, `pre_backfill_snapshot_records=0`, `pre_run_integrity_captures=1`. **Exact match against step 2's counts on every single table.**
6. **Restored-copy evidence** (via `transactions:capture-integrity-evidence --from=2026-06-13 --to=2026-08-10 --json`, dry-run since this is a read-only isolated check, not a second persisted baseline):
   - `duplicate_check_summary`: `total_duplicate_groups=0`, `total_duplicate_rows=0` — **exact match** against step 2.
   - `txn:pk-integrity`: `transaction_taxes (taxes): total=44 nulls=13 (29.55%) orphans=13 (29.55%)` — **exact match** against step 2. `transaction_adjustments`/`transaction_jobs`/`transaction_validations` correctly report "Skipping missing table" instead of `total=0`, because Mechanism 2's backup scope deliberately excludes those three tables (not part of the 8-table list) — this is the expected, already-documented shape of a partial-scope restore, not a discrepancy. `Total child rows: 44` — exact match.

**Verdict**: restore is usable — connection identity is distinct and correct, every in-scope table's row count matches exactly, and both integrity signals (duplicate-check baseline and `txn:pk-integrity`) reproduce identically between source and restored copy. T054 is closed for the documented Mechanism 2 procedure; a repeat drill against a genuinely separate host remains recommended before this exact tooling is trusted for a real cross-host disaster-recovery scenario, but is not required to close this task per the runbook's own scope ("once per backup-tooling setup," not gated on multi-host topology).

Local artifacts (dump file, raw command output, row-count/identity captures) were written to a scratch directory outside the repository and are not committed — they contain real (if small, local-dev-scale) transaction data, and this section is the durable record the runbook calls for.

**Isolated restore database removed after verification (2026-08-13)**: `tsms_restore_drill` was dropped once the evidence above was recorded. Deliberate operational pattern for every future run of this drill — create the isolated DB, verify it, record evidence here, drop it — so no stale restore clone is ever left behind to be mistaken for a current reference or compared against after it's aged.

### Restoring for real (not a drill)

A genuine emergency restore should **not** be a blind overwrite of the live database with the dump — doing so discards every legitimate write since the backup. Restore into an isolated database first (same as the drill), then selectively copy back only the specific rows Mechanism 1's targeted approach couldn't handle, informed by whatever investigation triggered reaching for Mechanism 2 in the first place. There is no generic script for this step — the exact copy-back scope is inherently case-specific to whatever went wrong.

## Cross-references

- `live-run-readiness-plan.md` §6 (backup) and §8 (rollback decision points) — this document is the detail those sections point to.
- `slice-12-orphan-archive-brief.md` / `slice-13-orphan-reconcile-brief.md` / `slice-14-orphan-delete-brief.md` — the day-boundary convention (`[dayStart, dayEnd)`, no buffer) reused verbatim in Part B above.
- `slice-15-pre-backfill-snapshot-brief.md` / `slice-16-integrity-evidence-capture-brief.md` — the pre-run evidence this runbook's verification steps compare against.
