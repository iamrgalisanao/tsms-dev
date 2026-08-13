# Release/Readiness Review — 002-backfill-transaction-taxes

**Date**: 2026-08-13 · **Status**: Review packet and staging rehearsal checklist — planning artifact, not an implementation slice. No code changes. Authorizes nothing by itself; see §5.

**Purpose**: everything a reviewer needs to decide whether this branch is ready to attempt a **staging rehearsal**, and everything an operator needs to run that rehearsal end to end. Live authorization is explicitly a separate, later decision (§5) — this document does not make it and does not request it.

---

## 1. Reviewer/Operator Packet

### 1.1 What changed, in one paragraph

This branch recovers ~3.24M `transaction_taxes` rows silently dropped for 808,891 transactions (216 unrecoverable) across 87 tenants during a 2026-06-13 → 2026-08-10 defect window (`research.md` V1a/V4 — the confirmed source-of-truth figures, restated in §2 below). The defect itself has been fixed and confirmed resolved (100% capture on new transactions since 2026-08-10 ~10:00) — this branch is the **historical backfill**, not a bug fix. It adds: a chunked, resumable, idempotent tax-row reconstruction path from `original_payload`; a three-stage archive→reconcile→delete pipeline for the 3.24M orphaned (`transaction_pk IS NULL`) rows; pre-backfill and post-refresh rendered-aggregate snapshots for materiality; a connection-identity gate on the one aggregate-refresh command genuinely affected; operational safety controls (idle-transaction watchdog, lock-wait-timeout); a documented and drilled (locally) backup/restore procedure; and a final read-only readiness-verdict command. 20 implementation slices (Slices 6-20 each independently briefed before implementation; Slices 1-5 predate that brief-first convention), every slice code-reviewed, and — for high-risk slices — architect-revalidated. Full commit history: `git log --oneline main..HEAD` (64 commits on this branch).

### 1.2 Commands that now exist

All under `php artisan`, all documented in [contracts/cli-contract.md](contracts/cli-contract.md) (Commands 1-8):

| Command | Purpose | Mutates? |
|---|---|---|
| `transactions:backfill-taxes` | Reconstruct tax rows from `original_payload`, day-scoped `--apply` | Yes, `transaction_taxes` inserts only |
| `transactions:verify-tax-reconstruction` | Oracle check against known-good post-fix transactions | No |
| `transactions:tax-backfill-materiality` | Before/after `other_tax` comparison, FR-009a threshold flagging | Yes, its own tables only |
| `transactions:archive-orphan-taxes` | Archive → reconcile → delete the 3.24M orphan rows, per `--phase` | Yes, per phase (see cli-contract.md Command 5) |
| `transactions:snapshot-pre-backfill-aggregates` | Pre-backfill rendered-aggregate baseline (FR-012) | Yes, its own tables only |
| `transactions:capture-integrity-evidence` | Pre/post-run duplicate-check + null-count + `txn:pk-integrity` capture | Yes, its own table only |
| `transactions:tax-backfill-readiness-verdict` | **Pure reader** — final pass/warn/fail evidence rollup | **Never** |
| `txn:pk-integrity` (pre-existing) | Read-only `transaction_pk` integrity report across child tables | No |
| `reports:refresh-daily-transaction-summaries` (pre-existing, extended) | Aggregate refresh; now gated on connection identity (T077) | Yes, `daily_transaction_summaries`/`report_refresh_states` |

### 1.3 What remains manual or unbuilt

- **`transactions:tax-backfill-show` (Command 4, T042) — not built.** No command exists to inspect a specific transaction's correction history or list the 216 quarantined rows. Workaround for rehearsal: direct queries against `tax_backfill_records`/`transaction_taxes_orphan_archive` (both fully populated audit tables). This does not block a rehearsal, but blocks US3's stated auditability UX.
- **T058-T061 (coverage/tenant/tax-type/totals post-run validation) — not built.** T076's readiness verdict covers structural integrity (null count, duplicate-count regression) and materiality, but not per-day coverage completeness, per-tenant zero-total detection, tax-type distribution sanity, or VAT-total reconciliation against `transactions.vat_amount`. These remain manual spot-checks during rehearsal review, or a future slice.
- **`tsms:reconcile-intake`'s pause mechanism — no config toggle exists.** Documented as an accepted tradeoff in `containment-plan.md` §2, not built. Pausing it during the rehearsal/live window requires a temporary code change if pausing is judged necessary.
- **T063 (finance/compliance handoff note) and the T088a `other_tax` semantics family (allow-list fix, `net_amount`/`calculated_net_sales` alignment) — decided (S1-S6) but not implemented.** Tracked separately in `tasks.md`, out of scope for the backfill pipeline itself; do not block rehearsal, but should be resolved before any tenant-facing communication about corrected figures.
- **Scheduler-level failure alerting** for the connection-identity-gated `reports:refresh-daily-transaction-summaries` cron entry — flagged non-blocking by Slice 18's architect revalidation, not built anywhere in this codebase for any scheduled command.

### 1.4 What has never been run against real data

**Everything, with one narrow exception.** No `--apply` invocation of `transactions:backfill-taxes`, `transactions:archive-orphan-taxes` (any phase), or `reports:refresh-daily-transaction-summaries` has ever executed against the actual defect-window data, on any environment. The **only** thing that has run for real is T054's backup/restore drill (`rollback.md`, "Drill executed" section) — and that ran against **local dev data** (119 transactions, not the 808,891-transaction production population), on **one physical MySQL server** acting as both source and restore target, not a genuine staging/production topology. Every other command's correctness rests on unit/feature tests against synthetic fixtures. **A staging rehearsal against a real, representative dataset has not yet happened — this is precisely what §3 below sets up.**

---

## 2. Staging Snapshot Environment — Open Item

The live-run readiness plan's §12 checklist has exactly one unclosed item:

> A restored staging snapshot is available and its orphan/transaction counts are confirmed against V4/V1a's figures

This is an infrastructure/access task, not something closeable from this repo — it requires an actual staging database restore. **Confirmation target, restated here for whoever performs it** (source: `research.md` V1a/V4, confirmed 2026-08-10, cross-validated by two independent queries):

| Metric | Expected value |
|---|---|
| Defect window | 2026-06-13 → 2026-08-10 ~10:00 |
| Transactions in window | 811,801 |
| Missing tax rows, recoverable | 808,891 |
| Missing tax rows, unrecoverable (216, all 2026-06-13) | 216 |
| Orphan rows (`transaction_pk IS NULL`) in `transaction_taxes` | 3,238,180 |
| Orphan-to-transaction ratio | 4.002 (most days exactly 4×) |
| Tenants affected | ~87 |

**Verification method**: run `php artisan txn:pk-integrity` and a direct count query against the restored staging copy; the `transaction_taxes` orphan count and the window's transaction count should match the table above. A material mismatch means the staging snapshot does not represent the same population this branch's design was built against, and rehearsal should not proceed until reconciled or the plan re-baselined.

**This item must close before §3 can start** — everything below assumes a staging environment with this confirmed population.

---

## 3. Staging Rehearsal Checklist

Full sequence, consolidating `live-run-readiness-plan.md` §3-§4 (the authoritative detailed version — this is the execution-order summary). Each step's detailed contract/guarantees are in `contracts/cli-contract.md`.

1. **Environment check**: confirm §2 above is closed. Confirm `CACHE_DRIVER` is redis/database/memcached (Slice 15/19's `Cache::lock()` cross-process requirement — `file`/`array` is unsafe). Confirm the DB user has `information_schema.INNODB_TRX`/`PROCESSLIST` read access (Slice 17's watchdog requirement).
2. **Pre-run integrity evidence**: `transactions:capture-integrity-evidence --from=2026-06-13 --to=2026-08-10 --phase=pre_run --apply` — note the `capture_id`.
3. **Pre-backfill snapshot**: `transactions:snapshot-pre-backfill-aggregates --from=2026-06-13 --to=2026-08-10 --apply` — **irreversible if skipped**, note the `run_id` (this is `--snapshot-run` for later steps).
4. **Verification oracle**: `transactions:verify-tax-reconstruction --sample=<N>` — **must report zero divergences before proceeding.** This is the feature's primary safety gate.
5. **Dry run**: `transactions:backfill-taxes --from=2026-06-13 --to=2026-08-10` (whole window, no `--apply`) — review scanned/reconstructable/quarantined/failed counts.
6. **Idempotency proof**: `transactions:backfill-taxes --day=<one day> --tenant=<pilot> --apply`, run twice — second run must converge to `skipped_existing`, zero new inserts.
7. **Archive** (once, whole population): `transactions:archive-orphan-taxes --phase=archive --apply`.
8. **Per day**, in order: `transactions:backfill-taxes --day=$D --apply` → `archive-orphan-taxes --phase=reconcile --day=$D --apply` → `archive-orphan-taxes --phase=delete --day=$D` (dry-run, capture the reported `--token`) → `archive-orphan-taxes --phase=delete --day=$D --apply --token=$TOKEN`. The idle-transaction watchdog (T100) gates every mutating step automatically.
9. **Aggregate refresh**, per tenant/day: `reports:refresh-daily-transaction-summaries --from=... --to=... --tenant=<ID>` — the connection-identity gate (T077) runs automatically and refuses on mismatch; confirm no refusals in the run log.
10. **Post-run integrity evidence**: `transactions:capture-integrity-evidence --from=2026-06-13 --to=2026-08-10 --phase=post_run --apply` — note the `capture_id`. **Required, separate step — nothing in step 12 captures this automatically.**
11. **Materiality**: `transactions:tax-backfill-materiality --snapshot-run=<from step 3> --apply` — note the `materiality_run_id`. Re-run bare (no `--apply`) with different `--threshold-amount`/`--threshold-percent` any time afterward to retune without re-capturing.
12. **Readiness verdict**: `transactions:tax-backfill-readiness-verdict --from=2026-06-13 --to=2026-08-10 --snapshot-run=<step 3> --pre-run-capture-id=<step 2> --post-run-capture-id=<step 10> --backup-drill-confirmed --json` — only pass `--backup-drill-confirmed` once a staging-scoped drill (not just T054's local-dev one) has actually been reviewed as still valid; see §4.
13. **Record rehearsal timings and evidence**: per-chunk/per-day duration, peak `SHOW PROCESSLIST` depth, total wall-clock time, and the full JSON output of steps 10-12 — this is the deliverable for §4's review.

---

## 4. Review Gate (Go/No-Go on this rehearsal)

Proceed to §5 (live authorization discussion) only if **all** of the following hold. Any single failure here means: fix, re-run the affected step(s), and re-evaluate — not escalate to live.

- **T076's verdict is `pass`, or `warn`-only with every individual WARN explicitly reviewed and accepted** (not silently ignored) — a `fail` verdict is an unconditional stop.
- **Materiality is understood, not just computed.** The flagged-tenant list (`tax_backfill_materiality_records` for the rehearsal's `materiality_run_id`) has been reviewed by whoever owns tenant communication — notification dispatch is a separate, human-triggered step (FR-009b), but understanding who *would* be notified is part of this gate.
- **No unresolved `source_mismatch` records** in the materiality run — every tenant/month pair was genuinely compared, or the mismatches are explained (e.g. a known refresh-timing gap) and accepted.
- **Rollback evidence is judged acceptable for this scope.** T054's drill (`rollback.md`) proved the *procedure and tooling* against local dev data; before treating `--backup-drill-confirmed` as meaningful for a staging or live run, either repeat the drill against the staging environment itself, or explicitly accept the local-dev drill as sufficient evidence for this stage and record that decision here.
- **No unresolved operational-safety warning** — zero idle-transaction-watchdog refusals mid-run that weren't investigated, zero connection-identity refusals on the aggregate refresh step.
- **The full evidence trail (step 13's captures) is attached to this review**, not just a verbal "it passed."

---

## 5. Live Authorization — Separate, Future Step

Not decided by this document. Per `live-run-readiness-plan.md` §11 and `tasks.md` T056: explicit user authorization for a live run against real production/staging-as-production data is a distinct gate from rehearsal, required in addition to Stage 3's per-day cryptographic delete token (T079) — the token proves one day's data is safe to delete, it does not substitute for the human decision to run the whole thing for real.

**Recommendation carried over from the direction that produced this packet**: if rehearsal (§3-§4) passes, treat the first live authorization as scoped to **one controlled day**, not the whole window — matching this feature's own established per-day discipline (chunking, idempotency, kill-switch) rather than authorizing the full 59-day window in one decision.

This section exists so the next reviewer knows where the line is — not to request or imply that authorization has been given.
