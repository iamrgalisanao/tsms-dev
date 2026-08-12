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

### NOT yet built — this plan's dependencies, not this plan's content

| Missing piece | Blocks | Task(s) |
|---|---|---|
| `SnapshotPreBackfillAggregates` command (per-tenant/month **rendered** aggregate baseline) | Materiality (FR-009a), SC-003's "components already correct MUST be unchanged" check, §5 below | T073, T074 |
| `transactions:tax-backfill-materiality` command | Producing the reproducible before/after list (SC-006) | Command 3, cli-contract.md |
| `transactions:tax-backfill-show` command (correction/quarantine inspection) | US3 auditability, reviewing the 216 quarantined rows | Command 4, T042 |
| `rollback.md` (documented + scripted two-part rollback) | §8 below | T052 |
| Idle-transaction watchdog + low `innodb_lock_wait_timeout` operational controls | §7/§9 below, direct mitigation for the 2026-08-10 incident's failure mode | T100 |
| Duplicate-check pre-run baseline capture | T076's post-run comparison (compares against a captured baseline, not zero) | T075 |
| Documented, drilled backup/restore procedure | §6 below | T054 |
| Scheduled-job pause/inventory runbook entry | §7 below | T052a |
| Mid-run "zero rows so far" containment note | §9 below | T102 |

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

### 3.2 — Pre-backfill snapshot (BLOCKED on T073/T074)

```bash
php artisan transactions:snapshot-pre-backfill-aggregates --from=2026-06-13 --to=2026-08-10 --json
```

**This command does not exist yet.** Until T073/T074 land, this step cannot run, and every downstream materiality/no-unintended-drift check (§5, §9) has no legitimate `before` value. **Do not substitute `before = 0`** — spec.md FR-009a is explicit that doing so would flag nearly every tenant. If rehearsal must proceed before T073/T074 land, this step is a documented gap, not a step to skip silently.

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

### 3.7 — Aggregate refresh (only after connection-identity assertion — BLOCKED on T077)

```bash
php artisan reports:refresh-daily-transaction-summaries --from=2026-06-13 --to=2026-08-10 --tenant=<ID>
```

T077 (recording, not just asserting, the aggregating connection's `@@server_id`/`DATABASE()`) is not yet implemented. Until it lands, this step has no recorded proof the refresh ran against the primary — proceed only with a manual, logged confirmation in rehearsal, and treat T077 as a hard blocker for the live run itself.

### 3.8 — Post-run validation

Coverage (Step 6), tenant/tax-type/totals validation (T058-T061), duplicate check (T062 — compared against T075's pre-run baseline, once that exists). Confirm `transaction_pk IS NULL` count in `transaction_taxes` is **zero** (T076, revised: FR-015b means zero is the correct end state for the whole window, not just reconciled days).

### 3.9 — Record rehearsal timings

Per-chunk and per-day duration, peak `SHOW PROCESSLIST` depth, total wall-clock time. This is T055/T056's actual deliverable — feeds §4.

## 4. Required operator commands, in order (consolidated)

1. `php artisan txn:pk-integrity` (baseline)
2. `php artisan transactions:snapshot-pre-backfill-aggregates` *(T073/T074 — not yet built)*
3. `php artisan transactions:verify-tax-reconstruction --sample=<N>`
4. `php artisan transactions:backfill-taxes --from=... --to=...` (dry run)
5. `php artisan transactions:backfill-taxes --day=... --tenant=<pilot> --apply` (×2, idempotency proof)
6. `php artisan transactions:archive-orphan-taxes --phase=archive --apply` (once)
7. Per day: `transactions:backfill-taxes --day=$D --apply` → `archive-orphan-taxes --phase=reconcile --day=$D --apply` → `archive-orphan-taxes --phase=delete --day=$D` (dry-run, get token) → `archive-orphan-taxes --phase=delete --day=$D --apply --token=$TOKEN`
8. `php artisan reports:refresh-daily-transaction-summaries --from=... --to=... --tenant=<ID>` (per tenant/day, after T077's connection-identity recording)
9. `php artisan transactions:tax-backfill-materiality --run=<RUN_ID>` *(not yet built)*
10. `php artisan txn:pk-integrity` (post-run, compare against step 1)

## 5. Pre-run evidence to capture

- `txn:pk-integrity` output, before and after (T099) — currently plain-text console output only; capture verbatim with a timestamp, no `--json` exists yet.
- Pre-backfill rendered aggregate snapshot, per (tenant, month) (T073/T074) — **irreversible if skipped**; once rows are inserted, this baseline cannot be reconstructed.
- Duplicate-check baseline (T075) — the pre-run `GROUP BY (transaction_pk, tax_type)` result, so T076's post-run comparison has a real baseline instead of an assumed zero.
- Every `TaxBackfillRun`/`tax_backfill_records` row this run produces — already durable via existing audit tables, no new capture step needed.
- Every orphan-archive/reconcile/delete verdict per day — already durable via `transaction_taxes_orphan_archive`'s persisted `reconciled_status`/`reason_code`, no new capture step needed.
- `SHOW PROCESSLIST` samples during the run, captured to the run record rather than only watched live by a human (T100 — not yet built; until it exists, this is a manual watch-and-log responsibility, not automated).
- Connection identity (`@@server_id`, `DATABASE()`) for the aggregating connection at refresh time (T077 — not yet built).

## 6. Backup and restore requirements (T054)

- A **verified** `transaction_taxes` backup is required before any `--apply` run over the full window — "verified" means a restore of that specific backup has been drilled at least once in the rehearsal environment, not merely that the backup command exited zero.
- Backup scope: `transaction_taxes` at minimum; recommend also backing up `transactions`, `tax_backfill_runs`, `tax_backfill_records`, `transaction_taxes_orphan_archive` as a consistent set, taken in the same snapshot window, so a restore doesn't have to reconcile inconsistent points in time across tables.
- Document the **exact** backup and restore commands used (tool, flags, target location, retention) — this plan does not prescribe a specific tool (e.g. `mysqldump` vs. a snapshot-based approach); T054's job is to pick one, test it, and write it down precisely enough that a different operator could execute the restore without guessing.
- The orphan archive table (`transaction_taxes_orphan_archive`) is itself a form of backup for the rows Stage 3 deletes — but it does **not** substitute for a full `transaction_taxes` backup, since it only covers rows with `transaction_pk IS NULL`, not the newly-inserted linked rows a bad `--apply` could also get wrong.

## 7. Pause/containment plan for scheduled jobs (T052a)

Inventory (already captured in tasks.md, consolidated here as the authoritative pre-run checklist):

| Job | Frequency | Risk if left running during the backfill |
|---|---|---|
| `reports:refresh-daily-transaction-summaries --days=2` | Every 15 min | Writes `report_refresh_states`, which FR-012a's source-label pin depends on being stable between snapshot and materiality computation |
| `transactions-prune` | Hourly | Deletes `FAILED` transactions older than 14 days, cascading to linked tax rows via `ON DELETE CASCADE`; its bulk `DELETE` can fail entirely on an FK violation if its batch includes a transaction already holding a `TaxBackfillRecord` row (RESTRICT FK) |
| `transactions-watchdog` | Every 5 min | Flips `PENDING → FAILED`, continuously re-feeding `transactions-prune`'s candidate pool |
| `tsms:reconcile-intake` | Every minute | Highest-frequency job touching the intake↔transactions relationship (~1,400 runs across a full-window backfill) |
| `tsms:reconcile-intake --repair-missing` | Daily 23:00 | Could recreate a transaction row with a fresh id and no orphan correspondence |

**Action**: pause the pruner/watchdog pair (and ideally `reconcile-intake`) for the entire maintenance window, not just the mutation phase. Capture a transaction-count census at run start and end regardless of whether pausing succeeds cleanly, as a corroborating signal.

## 8. Rollback and decision points (T052)

Two-part rollback, both required (`rollback.md` does not exist yet — write it as part of closing T052, not improvised live):

1. **Undo inserts**: delete rows attributable to the run via `tax_backfill_records`' row-level audit trail (run-scoped, not a blind delete).
2. **Restore orphans**: re-insert from `transaction_taxes_orphan_archive` — this is *why* archive-before-delete is mandatory; without it, a delete cannot be undone.

**Caveat that must be in the written runbook**: `daily_transaction_summaries` merges with `max()` (Architect F11), so aggregates are **monotonic** — deleting rows alone does not lower a previously-reported figure. A rollback is not complete, and must not be reported as complete, until a post-rollback aggregate refresh runs.

**Decision points** (when to invoke rollback vs. continue):

- A day's reconcile halts (`orphan_content_mismatch`) → per FR-014a, this **already stops the per-day loop by design** (not a rollback trigger by itself) — investigate before deciding whether to roll back completed days or fix and resume forward. Completed days are not automatically wrong just because a later day halted.
- The reconstruction oracle (§3.3) shows any divergence *after* the live run started (should be structurally impossible if run before `--apply`, but if discovered mid-run) → **stop immediately**, full rollback of the run.
- A live-ingestion disruption is observed (SC-004 violated) → kill-switch first (§9), assess, decide rollback vs. resume based on whether already-written data is suspect.
- Post-run validation (T058-T062) fails any check → do not proceed to materiality/notification; decide rollback scope based on which check failed and how many days it implicates.

## 9. Kill-switch and resume behavior (already implemented — this section documents it, doesn't design it)

- **Kill-switch**: a sentinel file path, checked via `file_exists()` immediately before each chunk starts inside `TaxBackfillRunner::apply()` (T033). Triggering it produces `TaxBackfillRun::STATUS_STOPPED` — distinct from `STATUS_INTERRUPTED` (e.g. a crash) and `STATUS_FAILED` (an unrecoverable error), so a deliberate operator stop is never misreported as a crash.
- **Resume**: there is no separate "resume" flag or command. Re-invoking the identical `--day=$D --apply` command is safe and idempotent by construction (`taxes()->exists()` ordering, T032/T034) — already-applied transactions report `skipped_existing`, not a duplicate insert. This applies whether the prior run was stopped (kill-switch) or interrupted (crash).
- **Mid-run "zero tax rows found" state** (T102, not yet written into a runbook): if an operator observes zero rows written partway through a day, this is **not necessarily a failure** — a day whose transactions were mostly already reconstructed (or already had linked rows) can legitimately show near-zero new inserts. The containment runbook must name this explicitly so an operator doesn't panic-abort a healthy run.
- **Token flow (Stage 3 delete)**: no separate resume concept is needed — the day-bound sha256 token is stable for as long as that day's archived verdict is unchanged (Slice 14), so re-running `--phase=delete --day=$D` with the same previously-captured token after an interruption is safe and correctly idempotent (a chunk that already ran affects zero rows on retry, per design, not an error).

## 10. Success / failure gates

- **Per-transaction**: FR-008's oracle must show zero divergence before *any* `--apply`.
- **Per-day** (structural, already enforced in code): `orphan_content_mismatch` or a failed precondition halts that day and blocks its delete — the loop does not advance.
- **Whole-run** (SC-001 through SC-006, see spec.md): 100% coverage for taxable transactions in-window; zero duplicates and zero `transaction_pk IS NULL` rows remaining; SC-003's stated tolerance on aggregate exactness (excluding `other_tax` per FR-016); zero measurable live-ingestion disruption (SC-004); a traceable audit entry per correction (SC-005); a reproducible materiality set (SC-006, blocked on T073/T074 + the materiality command).

## 11. Who must authorize what

- **Finance sign-off on the correction itself**: already obtained (S1, re-confirmed 2026-08-11, spec.md) — a business decision, already closed, not re-litigated by this plan.
- **Explicit user authorization for the live run** (T056): a *separate*, still-open gate — "obtain explicit user authorization before the live run — it mutates real tenant financial data." This is a human sign-off distinct from, and in addition to, Stage 3's per-day cryptographic token (T079); the token proves a specific day's data is safe to delete, it does not substitute for an operator/business decision to run the whole thing live.
- **Rehearsal itself** does not require the same authorization as the live run (it targets a restored snapshot, not live data) — but per this plan's opening line, running even the rehearsal is not authorized by this document alone; it still needs an explicit go-ahead once §1's blocking gaps are closed enough to attempt it.

## 12. Summary checklist before rehearsal can be attempted

- [ ] T073/T074 — pre-backfill snapshot command exists and is tested
- [ ] T075 — duplicate-check baseline capture exists
- [ ] T077 — connection-identity recording (not just assertion) exists
- [ ] T052 — `rollback.md` written and its restore-from-archive path tested at least once
- [ ] T054 — backup procedure documented and a restore drilled successfully
- [ ] T052a — scheduled-job pause procedure documented and tested (can the jobs actually be paused cleanly?)
- [ ] T100 — idle-transaction watchdog / lock-wait-timeout controls exist, or an explicit manual-monitoring substitute is written down
- [ ] T102 — mid-run "zero rows so far" note added to the containment runbook
- [ ] A restored staging snapshot is available and its orphan/transaction counts are confirmed against V4/V1a's figures

Only once this checklist is closed does §3's rehearsal sequence become fully executable as written, rather than partially manual. Closing it is implementation work for a future slice, not part of this plan.
