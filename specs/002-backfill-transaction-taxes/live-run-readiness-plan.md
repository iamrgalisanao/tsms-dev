# Live-Run Readiness Plan — Backfill Transaction Taxes

**Date**: 2026-08-12 · **Status**: Planning only — no implementation, no rehearsal, no live run authorized by this document

## Purpose and scope

This plan defines what must be true, in what order, with what evidence, before this feature's real `--apply` sequence ever runs against staging's live data (this feature's only "production" — see spec.md Assumptions). It covers T052-T057 (rollback/containment/rehearsal), T073-T077 (pre-backfill baselines and connection-identity assertion), and T099-T102 (operational controls), plus a final operator-safety review scope. **It is a planning artifact, not a new implementation slice** — where a task below has no corresponding code yet, that is stated plainly, not silently assumed away.

This plan does not authorize running anything. Per this feature's standing rule, reiterated at every prior slice: **no live `--apply` or destructive command runs against real data without the user's separate, explicit authorization**, and that authorization is a distinct act from approving this plan.

## 1. What already exists vs. what this plan depends on being built first

Being honest about this now is cheaper than discovering it mid-rehearsal.

### Already implemented, tested, reviewed, and architect-revalidated

| Capability | Where | Task(s) |
|---|---|---|
| Dry-run / `--apply` reconstruction, day-scoped, chunked | `TaxBackfillRunner`, `transactions:backfill-taxes` | T016-T029, T034-T035 |
| Throttle (`--throttle=`, chunk-granularity sleep) | `TaxBackfillRunner::apply()` | T031 |
| Kill-switch (`--kill-switch-path=`, sentinel file, checked before each chunk, `STATUS_STOPPED`) | `TaxBackfillRunner::apply()` | T033 |
| Resume-by-reinvocation (idempotent by construction, no separate "resume" flag) | `TaxBackfillRunner::apply()` | T032, T034 |
| Schema pre-flight (required columns, index/FK, `ON DELETE`, nullability) | `TaxBackfillPreflightChecker` | T097, T018 |
| Reconstruction-correctness oracle, stratified by day and tenant | `transactions:verify-tax-reconstruction` | T023-T025, T101 |
| Orphan archive (byte-faithful, idempotent, whole-population) | `OrphanTaxArchiver`, `--phase=archive` | T066-T068, T072 |
| Orphan reconcile (day-scoped, `[0,10]s` tolerance, three-way classification) | `OrphanTaxReconciler`, `--phase=reconcile` | T068a, T069, T071 (reconcile-side) |
| Orphan delete (two preconditions + day-bound sha256 token) | `OrphanTaxDeleter`, `--phase=delete` | T070, T070a, T071 (delete-side), T079 |
| Transaction-child PK-integrity report (pre-existing, reusable) | `php artisan txn:pk-integrity` | referenced by T099 |
| Idle-transaction watchdog, low session lock-wait timeout, before/after processlist evidence | `IdleTransactionWatchdog`, wired into `TaxBackfillRunner::apply()` and `ArchiveOrphanTaxRows`'s three `--apply` branches | T100 |

**Update 2026-08-12 (Slice 17)**: `IdleTransactionWatchdog` (T100) is now built, tested, code-reviewed, and architect-revalidated — this closes §7/§9's remaining gap. Review caught and fixed a serious transaction-safety bug in the accompanying retry-coverage extension (`OrphanTaxReconciler::persist()` risked a silent partial write on a genuine deadlock — fixed via Laravel's native whole-closure transaction retry instead of per-chunk retry; see `tasks.md` T100 for the full account). Two operational notes for whoever runs this for real: confirm the DB user has `information_schema.INNODB_TRX`/`PROCESSLIST` read access before relying on the gate (a permission error surfaces as an uncaught exception, not a clean refusal); confirm this connection isn't multiplexed through a proxy that could route the session-scoped lock-wait-timeout setting and the subsequent mutation to different backend sessions.

**Update 2026-08-12 (Slice 15)**: `SnapshotPreBackfillAggregates` (T073/T074) is now built, tested, code-reviewed, and architect-revalidated — §3.2 below is no longer blocked. One operational note carried over from that slice's review: the command's concurrency guard requires `CACHE_DRIVER` to be a distributed-lock-capable driver (redis/database/memcached/dynamodb) in whatever environment runs it for real — confirm this before rehearsal, not just in production.

**Update 2026-08-12 (Slice 16)**: `CaptureIntegrityEvidence` (T075/T099) is now built, tested, code-reviewed, and architect-revalidated — §5 below is no longer blocked for the pre-run half. It found and fixed a real correctness trap: the T062 duplicate-check query, applied naively, would have let MySQL's NULL-grouping behavior collapse the 3.24M-row orphan population into meaningless phantom "duplicate" groups — `WHERE transaction_pk IS NOT NULL` is now mandatory and regression-tested. T076's post-run half of this evidence (the `post_run` phase) remains unbuilt — the schema already supports it, but nothing writes it yet.

**Update 2026-08-13 (T054 drill)**: executed and passed (local dev environment) — see `rollback.md`'s "Drill executed" section for the full evidence log. Restore into an isolated database (`tsms_restore_drill`, never the working DB) reproduced every table's row count exactly and matched the pre-backup duplicate-check/`txn:pk-integrity` figures exactly. Environment caveat carried forward: source and restore target were two databases on the same local MySQL server, not genuinely separate hosts — a real staging/production drill should repeat this against a separate host before Mechanism 2 is trusted for actual cross-host disaster recovery, though this is not required to close T054 itself (the runbook only requires the drill "once per backup-tooling setup").

**Update 2026-08-13 (Slice 19, T047/Command 3 — materiality report)**: `transactions:tax-backfill-materiality` (T046-T051) is now built, tested, code-reviewed, and architect-revalidated — SC-006's "reproducible before/after list" dependency is closed. Compares Slice 15's pinned `before` snapshot against a freshly captured, equally pinned `after` render (same `SalesReportDataService::getCmsrReportData()` path), per (tenant, reporting month), on the `other_tax` component specifically. Threshold evaluation (FR-009a) is read-time only — the pass/fail flag is never persisted, so finance can retune `--threshold-amount`/`--threshold-percent` later against the same immutably-captured deltas without any new report-path call. Per-pair source-mismatch isolation (FR-012a): one tenant's source flip is quarantined (`comparison_status = source_mismatch`) without blocking visibility into every other tenant. Two non-blocking notes from the architect drift-revalidation: (a) a narrow, benign race exists when `--snapshot-run` is omitted — two concurrent default-resolution invocations could pick different (both valid, both correctly labeled) snapshot-run baselines if a new Slice 15 snapshot completes in between; pass `--snapshot-run` explicitly to eliminate it, or accept it as documented; (b) same `CACHE_DRIVER` requirement as Slice 15 (redis/database/memcached for real cross-process locking, not `file`/`array`). T076 (post-run integrity comparison, using Slice 16's `pre_run` capture) and the materiality-list-informed finance handoff note (T063) remain separately scoped, unbuilt work.

### NOT yet built — this plan's dependencies, not this plan's content

| Missing piece | Blocks | Task(s) |
|---|---|---|
| `transactions:tax-backfill-show` command (correction/quarantine inspection) | US3 auditability, reviewing the 216 quarantined rows | Command 4, T042 |
| T076's post-run comparison logic (reads the `pre_run` capture Slice 16 now produces) | Full pre/post integrity validation | T076 |
| `tsms:reconcile-intake`'s pause mechanism — no config toggle exists (`transactions-prune`/`transactions-watchdog` both have one); pausing it during a maintenance window requires a temporary code change, documented as an open tradeoff in `containment-plan.md` §2, not built here | §7 below | (no task id — a documented gap, candidate for a future small enhancement) |

**Consequence for sequencing**: the rehearsal in §3 cannot run end-to-end exactly as written until the "not yet built" column is closed. Where a step below depends on a missing command, it says so and names the fallback (a manual query, or "blocked until T0xx lands") rather than pretending the automation exists.

## 2. Rehearsal environment

- **A restored snapshot of staging, never the live staging DB.** Staging carries real pilot-tenant financial data (spec.md Assumptions); rehearsal must not be able to mutate it.
- The snapshot must be restored from a backup taken *at or after* the point where the ingestion fix (`4db2a063` ancestor) was deployed and the orphan population census (V4: 3,238,180 rows) was measured — an older snapshot would rehearse against a different, stale orphan population and produce misleading timings/counts.
- The four dashboard endpoints (`dashboard/{metrics,charts,notifications,terminal-performance}`) must remain stubbed to JSON 404 in the rehearsal environment too — re-enabling them, even in rehearsal, defeats the point of proving the 2026-08-10 pile-up conditions can't recur (research R9).
- Rehearsal runs on the **same chunk/throttle defaults** the live run would use until §4's timing data justifies changing them — don't rehearse with looser settings than production will use.

## 3. Exact rehearsal sequence

Per-day loop steps (3.6 onward) mirror the live-run loop exactly — rehearsal *is* a dry run of the live sequence, not a different, lighter procedure.

### 3.1 — Pre-flight environment checks

```bash
php artisan txn:pk-integrity          # baseline: capture full output, timestamped
```

Confirm the restored snapshot's orphan count matches V4's 3,238,180 (or the snapshot's own known-current figure, if taken later) before proceeding — a mismatch means the snapshot is stale or was restored incorrectly.

### 3.2 — Pre-backfill snapshot (built, Slice 15 — 2026-08-12)

```bash
php artisan transactions:snapshot-pre-backfill-aggregates --from=2026-06-13 --to=2026-08-10 --json          # preview, zero report calls
php artisan transactions:snapshot-pre-backfill-aggregates --from=2026-06-13 --to=2026-08-10 --apply --json  # real capture
```

A completed run for this exact window refuses a bare re-invocation (requires `--force` to start an independent new run) — this is deliberate, not a bug, per FR-012's "unrecoverable once the run begins." **Do not substitute `before = 0`** — spec.md FR-009a is explicit that doing so would flag nearly every tenant. Confirm `CACHE_DRIVER` is redis/database/memcached/dynamodb before running this for real — the command's concurrency guard depends on it (see the command's own docblock).

### 3.3 — Reconstruction oracle (must be zero divergence)

```bash
php artisan transactions:verify-tax-reconstruction --sample=500 --json
```

Inspect `checked_count`/`candidate_pool_size` and the `coverage` block (T101), not just the exit code — a defaults-only run early in a fresh post-fix window can exit 0 having checked very few transactions. **Any divergence is a hard stop.**

### 3.4 — Dry run, full window

```bash
php artisan transactions:backfill-taxes --from=2026-06-13 --to=2026-08-10 --json
```

Sanity-check: `already-present` ≈ 0 inside the window; `quarantined` ≈ 216 (small, all 2026-06-13). A large `quarantined` count means the payload-retention assumption (research V1a) is weaker in this snapshot than measured.

### 3.5 — Pilot tenant, then idempotency re-run

```bash
php artisan transactions:backfill-taxes --day=2026-06-13 --tenant=<PILOT_ID> --apply --json
php artisan transactions:backfill-taxes --day=2026-06-13 --tenant=<PILOT_ID> --apply --json   # re-run
```

Second run: every transaction reports `skipped_existing`, **zero** new rows. This is the FR-004 idempotency proof and is mandatory before the full per-day loop.

### 3.6 — Per-day loop (the real sequence, once pilot passes)

```bash
for D in 2026-06-13 .. 2026-08-09; do
  php artisan transactions:backfill-taxes --day=$D --apply --throttle=<T> --kill-switch-path=<PATH>

  php artisan transactions:archive-orphan-taxes --phase=reconcile --day=$D --apply --json

  TOKEN=$(php artisan transactions:archive-orphan-taxes --phase=delete --day=$D --json | jq -r '.authorization_token')
  php artisan transactions:archive-orphan-taxes --phase=delete --day=$D --apply --token="$TOKEN" --json

  # halt the whole loop on any non-zero exit above — do not advance to D+1
done
```

Note: `--phase=archive` runs once, up front, over the whole orphan population — **not inside this per-day loop** (Stage 1 is whole-population by design; see slice-12-orphan-archive-brief.md). Run it once before the loop starts:

```bash
php artisan transactions:archive-orphan-taxes --phase=archive --apply --json
```

**Halt-on-mismatch is structural, not procedural**: `--phase=reconcile --apply` and `--phase=delete --apply` both already refuse to proceed/persist on failure (verified in Slice 13/14's own tests) — the rehearsal script's job is to *stop the loop* on a non-zero exit, not to re-implement the safety check.

### 3.7 — Aggregate refresh (connection-identity gate built, Slice 18, 2026-08-13)

```bash
php artisan reports:refresh-daily-transaction-summaries --from=2026-06-13 --to=2026-08-10 --tenant=<ID>
```

T077 is built: before any aggregation query, the command resolves `@@server_id`/`DATABASE()` for both `config('database.default')` and the literal `mysql` connection and refuses (zero writes, exit 1) on any mismatch, logging both identity tuples. On a match it persists the resolved identity into `report_refresh_states` for every affected date — the recorded proof this step previously lacked. Fail-closed by design (no automated `MASTER_POS_WAIT`/replication-wait mechanism) — a mismatch here means misconfiguration, not ordinary lag, and the safe response is a human investigating, not an automated retry loop.

**Interaction with §3.2's pre-backfill snapshot (Slice 15) worth knowing, not a blocker**: if this gate ever trips during the live-run window, `SalesReportDataService::hasCompleteDailySummaryRefresh()` will find no `completed` row for the affected dates and every rendered-report consumer (CMSR dashboards, and `SnapshotPreBackfillAggregates` itself) automatically falls back to computing the same figures live from `raw_transactions` instead of from `daily_transaction_summaries` — correct numbers, just a different, slower source, and it's auditable via `SalesReportResult`'s own `source` field. Not a correctness or data-loss risk; flagged here so a mixed-source snapshot mid-window isn't a surprise.

Non-blocking operational note from Slice 18's architect drift-revalidation: this codebase has no scheduled-command failure-alerting pattern anywhere (checked `routes/console.php` in full), so a persistent mismatch on the 15-minute cron entry for this same command would only be visible via `Log::error` — worth adding alerting for this specific schedule entry before relying on it operationally, though this is a pre-existing gap this slice did not introduce.

### 3.8 — Post-run validation

Coverage (Step 6), tenant/tax-type/totals validation (T058-T061), duplicate check (T062 — compared against T075's pre-run baseline, captured via Slice 16's `transactions:capture-integrity-evidence`). Confirm `transaction_pk IS NULL` count in `transaction_taxes` is **zero** (T076, revised: FR-015b means zero is the correct end state for the whole window, not just reconciled days).

### 3.9 — Record rehearsal timings

Per-chunk and per-day duration, peak `SHOW PROCESSLIST` depth, total wall-clock time. This is T055/T056's actual deliverable — feeds §4.

## 4. Required operator commands, in order (consolidated)

1. `php artisan txn:pk-integrity` (baseline)
2. `php artisan transactions:snapshot-pre-backfill-aggregates --apply` (T073/T074 — built, Slice 15)
3. `php artisan transactions:verify-tax-reconstruction --sample=<N>`
4. `php artisan transactions:backfill-taxes --from=... --to=...` (dry run)
5. `php artisan transactions:backfill-taxes --day=... --tenant=<pilot> --apply` (×2, idempotency proof)
6. `php artisan transactions:archive-orphan-taxes --phase=archive --apply` (once)
7. Per day: `transactions:backfill-taxes --day=$D --apply` → `archive-orphan-taxes --phase=reconcile --day=$D --apply` → `archive-orphan-taxes --phase=delete --day=$D` (dry-run, get token) → `archive-orphan-taxes --phase=delete --day=$D --apply --token=$TOKEN`
8. `php artisan reports:refresh-daily-transaction-summaries --from=... --to=... --tenant=<ID>` (per tenant/day — T077's connection-identity gate runs automatically, built Slice 18)
9. `php artisan transactions:tax-backfill-materiality --snapshot-run=<SNAPSHOT_RUN_ID> --apply` (built, Slice 19) — re-run bare (no `--apply`) with different `--threshold-amount`/`--threshold-percent` any time afterward to retune without re-capturing
10. `php artisan txn:pk-integrity` (post-run, compare against step 1)

## 5. Pre-run evidence to capture

- `txn:pk-integrity` output, before and after (T099) — captured verbatim (plain text) via `php artisan transactions:capture-integrity-evidence --from=... --to=... --apply` (Slice 16, built 2026-08-12). Run it once now for the `pre_run` phase; the `post_run` phase still has no writer (T076).
- Pre-backfill rendered aggregate snapshot, per (tenant, month) (T073/T074) — **irreversible if skipped**; once rows are inserted, this baseline cannot be reconstructed.
- Duplicate-check baseline (T075) — built (Slice 16): the same command above also persists the corrected (`WHERE transaction_pk IS NOT NULL`) `GROUP BY (transaction_pk, tax_type)` result, so T076's post-run comparison has a real baseline instead of an assumed zero.
- Every `TaxBackfillRun`/`tax_backfill_records` row this run produces — already durable via existing audit tables, no new capture step needed.
- Every orphan-archive/reconcile/delete verdict per day — already durable via `transaction_taxes_orphan_archive`'s persisted `reconciled_status`/`reason_code`, no new capture step needed.
- `SHOW PROCESSLIST` samples during the run, captured to the run record rather than only watched live by a human — built (Slice 17): `IdleTransactionWatchdog::sampleProcesslist()`, taken once before and once after each mutating phase, persisted into `TaxBackfillRun.preflight_checks.operational_safety` for `--apply` runs and into `ArchiveOrphanTaxRows`'s own result output for the three orphan-pipeline phases.
- Connection identity (`@@server_id`, `DATABASE()`) for the aggregating connection at refresh time — built (Slice 18, 2026-08-13): persisted to `report_refresh_states.server_id`/`database_name` on every successful refresh; on a mismatch the run refuses and both identity tuples are logged instead.

## 6. Backup and restore requirements (T054 — procedure documented 2026-08-12, drill executed and passed 2026-08-13, see [rollback.md](rollback.md))

- A **verified** `transaction_taxes` backup is required before any `--apply` run over the full window — "verified" means a restore of that specific backup has been drilled at least once, not merely that the backup command exited zero. `rollback.md` specifies the exact drill (restore into an isolated database, then cross-check via `transactions:capture-integrity-evidence`, Slice 16) — running that drill for real is still outstanding.
- Backup scope: all eight tables this feature touches (`transactions`, `transaction_taxes`, `tax_backfill_runs`, `tax_backfill_records`, `transaction_taxes_orphan_archive`, `pre_backfill_snapshot_runs`/`_records`, `pre_run_integrity_captures`), taken together in one `mysqldump` invocation so a restore reconstructs one mutually consistent point in time.
- Exact commands are in `rollback.md`: `mysqldump --single-transaction --quick --no-tablespaces` (the `--single-transaction` flag is mandatory — without it, the dump takes InnoDB-unsafe table locks, exactly the lock contention this feature exists to avoid).
- The orphan archive table (`transaction_taxes_orphan_archive`) is itself a form of backup for the rows Stage 3 deletes — but it does **not** substitute for a full `transaction_taxes` backup, since it only covers rows with `transaction_pk IS NULL`, not the newly-inserted linked rows a bad `--apply` could also get wrong.
- **The full backup/restore mechanism is last-resort disaster recovery, not the primary rollback path** — `rollback.md`'s Mechanism 1 (targeted, audit-record-driven undo) is what a normal rollback uses, since a full restore discards any legitimate activity that occurred after the backup was taken.

## 7. Pause/containment plan for scheduled jobs (T052a — built 2026-08-12, see [containment-plan.md](containment-plan.md))

Full inventory (verified exhaustively against `routes/console.php`, including two jobs an earlier pass here missed — `reporting:refresh transactions_hourly` and the dead-code `reporting:dispatch`), exact pause/resume actions, and the transaction-count census procedure are all in `containment-plan.md` — not duplicated here. Summary: `transactions-prune` and `transactions-watchdog` both have existing config toggles (`TX_ENABLE_PRUNING`, `TX_WATCHDOG_ENABLED` — no code change needed); `tsms:reconcile-intake` (both variants) has **no such toggle**, a genuine documented gap, not a false claim of one.

## 8. Rollback and decision points (T052 — built 2026-08-12, see [rollback.md](rollback.md))

Two-part rollback, both required, with the exact commands in `rollback.md`:

1. **Undo inserts**: delete rows attributable to the run via `tax_backfill_records`' row-level audit trail (run-scoped, not a blind delete).
2. **Restore orphans**: re-insert from `transaction_taxes_orphan_archive` — this is *why* archive-before-delete is mandatory; without it, a delete cannot be undone.

**Corrects this plan's own earlier wording** (verified against current code while writing `rollback.md`): `daily_transaction_summaries` is not a monotonic `max()` merge across refreshes — `RefreshDailyTransactionSummaries::handle()` deletes the affected date range and rebuilds it entirely from current source data every run; its `max()` expressions combine multiple sources computed *within* that same refresh call, never a previously-persisted summary value. A post-rollback refresh should reflect the restored, lower values directly — it remains mandatory (nothing recomputes this table until a refresh is run), just not for the "values only go up" reason previously stated here (which traced to an "Architect F11" citation that does not hold against the current implementation).

**Decision points** (when to invoke rollback vs. continue):

- A day's reconcile halts (`orphan_content_mismatch`) → per FR-014a, this **already stops the per-day loop by design** (not a rollback trigger by itself) — investigate before deciding whether to roll back completed days or fix and resume forward. Completed days are not automatically wrong just because a later day halted.
- The reconstruction oracle (§3.3) shows any divergence *after* the live run started (should be structurally impossible if run before `--apply`, but if discovered mid-run) → **stop immediately**, full rollback of the run.
- A live-ingestion disruption is observed (SC-004 violated) → kill-switch first (§9), assess, decide rollback vs. resume based on whether already-written data is suspect.
- Post-run validation (T058-T062) fails any check → do not proceed to materiality/notification; decide rollback scope based on which check failed and how many days it implicates.

## 9. Kill-switch and resume behavior (already implemented — this section documents it, doesn't design it)

- **Kill-switch**: a sentinel file path, checked via `file_exists()` immediately before each chunk starts inside `TaxBackfillRunner::apply()` (T033). Triggering it produces `TaxBackfillRun::STATUS_STOPPED` — distinct from `STATUS_INTERRUPTED` (e.g. a crash) and `STATUS_FAILED` (an unrecoverable error), so a deliberate operator stop is never misreported as a crash.
- **Resume**: there is no separate "resume" flag or command. Re-invoking the identical `--day=$D --apply` command is safe and idempotent by construction (`taxes()->exists()` ordering, T032/T034) — already-applied transactions report `skipped_existing`, not a duplicate insert. This applies whether the prior run was stopped (kill-switch) or interrupted (crash).
- **Mid-run "zero rows written" state** (T102 — built 2026-08-12, see `containment-plan.md` §4): if an operator observes zero/near-zero new inserts partway through a day, this is **not necessarily a failure** — check the outcome breakdown (`applied`/`skipped_existing`/`quarantined`/`failed`), not just the raw insert count. `containment-plan.md` also corrects a stronger, inaccurate claim from an earlier draft of this note (that external reports show "zero tax figures" broadly pre-backfill) — only `other_tax` genuinely shows that; VAT/vatable/SC-VAT-exempt were already correct throughout the defect window (spec.md's Business Case Re-baseline) and any movement there is a real anomaly, not a benign mid-run state.
- **Token flow (Stage 3 delete)**: no separate resume concept is needed — the day-bound sha256 token is stable for as long as that day's archived verdict is unchanged (Slice 14), so re-running `--phase=delete --day=$D` with the same previously-captured token after an interruption is safe and correctly idempotent (a chunk that already ran affects zero rows on retry, per design, not an error).

## 10. Success / failure gates

- **Per-transaction**: FR-008's oracle must show zero divergence before *any* `--apply`.
- **Per-day** (structural, already enforced in code): `orphan_content_mismatch` or a failed precondition halts that day and blocks its delete — the loop does not advance.
- **Whole-run** (SC-001 through SC-006, see spec.md): 100% coverage for taxable transactions in-window; zero duplicates and zero `transaction_pk IS NULL` rows remaining; SC-003's stated tolerance on aggregate exactness (excluding `other_tax` per FR-016); zero measurable live-ingestion disruption (SC-004); a traceable audit entry per correction (SC-005); a reproducible materiality set (SC-006 — built, Slice 19, `transactions:tax-backfill-materiality`).

## 11. Who must authorize what

- **Finance sign-off on the correction itself**: already obtained (S1, re-confirmed 2026-08-11, spec.md) — a business decision, already closed, not re-litigated by this plan.
- **Explicit user authorization for the live run** (T056): a *separate*, still-open gate — "obtain explicit user authorization before the live run — it mutates real tenant financial data." This is a human sign-off distinct from, and in addition to, Stage 3's per-day cryptographic token (T079); the token proves a specific day's data is safe to delete, it does not substitute for an operator/business decision to run the whole thing live.
- **Rehearsal itself** does not require the same authorization as the live run (it targets a restored snapshot, not live data) — but per this plan's opening line, running even the rehearsal is not authorized by this document alone; it still needs an explicit go-ahead once §1's blocking gaps are closed enough to attempt it.

## 12. Summary checklist before rehearsal can be attempted

- [x] T073/T074 — pre-backfill snapshot command exists and is tested (Slice 15, 2026-08-12)
- [x] T075/T099 — duplicate-check baseline + `txn:pk-integrity` evidence capture exists and is tested (Slice 16, 2026-08-12)
- [x] T077 — connection-identity recording (not just assertion) exists (Slice 18, 2026-08-13); scheduler-level failure alerting for the 15-minute cron entry remains an open, non-blocking operational recommendation (not part of this feature's scope)
- [x] T052 — `rollback.md` written (2026-08-12); its restore-from-archive path is a documented, schema-verified procedure, not yet executed against real data (no rehearsal has run to produce something to roll back)
- [x] T054 — backup procedure documented (2026-08-12) and verification drill executed and passed (2026-08-13, local dev environment) — see `rollback.md`'s "Drill executed" section; a genuinely separate-host repeat is recommended before real cross-host disaster recovery is trusted, not required to close this item
- [x] T052a — scheduled-job inventory and pause procedure documented (2026-08-12, `containment-plan.md`); `transactions-prune`/`transactions-watchdog` pause via existing config toggles (not yet drilled live); `tsms:reconcile-intake` has no toggle at all — documented as an accepted tradeoff, not a false claim of a clean pause
- [x] T100 — idle-transaction watchdog / lock-wait-timeout controls exist and are tested (Slice 17, 2026-08-12) — confirm the DB user's `information_schema` read privileges and connection-multiplexing status in the actual rehearsal/production environment before relying on it (see the Slice 17 update above)
- [x] T102 — mid-run "zero rows written" note added to the containment runbook (2026-08-12, `containment-plan.md` §4, correctly scoped to the backfill's own insert progress and to `other_tax` specifically — not a broad "reports show zero" claim)
- [ ] A restored staging snapshot is available and its orphan/transaction counts are confirmed against V4/V1a's figures

Only once this checklist is closed does §3's rehearsal sequence become fully executable as written, rather than partially manual. Closing it is implementation work for a future slice, not part of this plan.
