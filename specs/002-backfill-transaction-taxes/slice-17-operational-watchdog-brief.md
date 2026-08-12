# Slice 17 Architecture Brief — Operational Watchdog Controls (T100)

**Date**: 2026-08-12 · **Status**: Approved to implement against this brief

## Why this brief exists

The 2026-08-10 incident (research.md R9): "a long-running transaction on `transactions` queued behind idle connections and then blocked every subsequent query on that table, taking the site down." Every prior slice in this pipeline has been careful about chunk size, kill-switches, and never holding a table-wide lock — but nothing yet actively checks, *before* a mutating phase starts, whether the exact precondition that caused that incident already exists. T100 closes that gap with three narrowly-scoped operational controls. **This is safety instrumentation wrapped around existing mutation paths — it changes no outcome any of those paths produces.**

## Grounding: two things found while designing this brief

1. **`DeadlockRetryService::withDeadlockRetry()` already retries on `"Lock wait timeout exceeded"`** (`app/Services/DeadlockRetryService.php`'s `isRetryableDeadlock()`), alongside genuine deadlocks. This means lowering `innodb_lock_wait_timeout` is **not useful on its own** — a low timeout without retry coverage just makes a mutating statement fail faster, not yield more gracefully. It only becomes "yield to live traffic" once paired with retry.
2. **Only `TaxBackfillRunner`'s insert path actually uses `withDeadlockRetry()` today.** `OrphanTaxReconciler::persist()` and `OrphanTaxDeleter::delete()` (Slices 13/14) both mutate via plain `DB::transaction()`/direct query calls, with zero deadlock/lock-wait retry coverage. **This brief's lock-wait-timeout piece is only safe to ship together with extending retry coverage to those two write paths** — doing one without the other would make Stage 2/3 measurably worse, not better.

No existing code anywhere sets `innodb_lock_wait_timeout` at the session level, and no existing code samples `information_schema.INNODB_TRX`/`PROCESSLIST` — both confirmed absent by search, not assumed.

## Scope contract

```text
Allowed:
- One new service, app/Services/Backfill/IdleTransactionWatchdog.php,
  with three methods (design below).
- Wiring that service's gate check into the four mutation entry points:
  TaxBackfillRunner::apply() (T097/T018's existing preflight-check
  pattern is the precedent for adding a check INSIDE this method, not
  the CLI layer -- see Design decision 1) and
  ArchiveOrphanTaxRows.php's three --apply branches (archive/reconcile/
  delete), CLI-layer, since none of OrphanTaxArchiver/OrphanTaxReconciler/
  OrphanTaxDeleter have a "run" entity of their own to extend.
- Extending DeadlockRetryService::withDeadlockRetry() coverage to
  OrphanTaxReconciler::persist()'s UPDATE loop and OrphanTaxDeleter::
  delete()'s per-chunk DELETE -- required for the lock-wait-timeout
  piece to be safe, not optional (see Grounding above).
- New config keys under config/tsms.php's existing style
  (env()-backed, mirroring the prune/watchdog precedent already there).
- Tests per the plan below.

Not allowed:
- Any change to what gets inserted, reconciled, or deleted -- this
  slice changes NOTHING about outcomes, only when/how safely a mutating
  statement is allowed to run.
- Any change to TaxReconstructionService, TaxBackfillPreflightChecker,
  BackfillTransactionTaxes.php's/ArchiveOrphanTaxRows.php's existing
  CLI options or output shapes beyond adding the new fields this brief
  specifies.
- Touching DeadlockRetryService.php itself (its retry logic already
  covers lock-wait-timeout; this slice extends WHERE it's called, never
  its own behavior) -- it is shared by live ingestion, not this
  feature's to modify.
- Any new apply/archive/delete phase, flag, or CLI command.
```

## Design

### `IdleTransactionWatchdog` — three independent methods, one class (mirrors `TaxBackfillPreflightChecker`'s "related but independent checks" precedent)

**1. `check(?int $thresholdSeconds = null): array`** — the blocking gate.

```sql
SELECT trx_id, trx_state, trx_started, trx_mysql_thread_id, trx_query,
       TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS age_seconds
FROM information_schema.INNODB_TRX
WHERE TIMESTAMPDIFF(SECOND, trx_started, NOW()) > ?
ORDER BY trx_started ASC
```

`information_schema.INNODB_TRX` (transaction start time), not `SHOW PROCESSLIST`/`information_schema.PROCESSLIST` (connection/query state) — the incident condition is specifically about an **open transaction's age**, which only `INNODB_TRX.trx_started` gives directly. Default threshold from `config('tsms.backfill.idle_transaction_threshold_seconds', 60)` — 60 is the literal incident condition (research.md R9), not an arbitrary round number. Returns `{idle_transaction_count: int, oldest_age_seconds: int|null, transactions: list<{trx_id, trx_state, age_seconds}> (capped, no trx_query text — may contain payload data), passed: bool}`. `passed = (idle_transaction_count === 0)`.

**2. `sampleProcesslist(): array`** — evidence snapshot, non-blocking, never gates anything.

A summary of `information_schema.PROCESSLIST` (connection count, longest-running query age, count of queries above a low informational threshold) — this is observability only, automating what `quickstart.md`'s Step 5 currently asks a human to watch live (`SHOW PROCESSLIST`), never a pass/fail condition.

**3. `applyLowLockWaitTimeout(?int $seconds = null): void`** — `DB::statement('SET SESSION innodb_lock_wait_timeout = ?', [$seconds ?? config('tsms.backfill.lock_wait_timeout_seconds', 3)])`. Session-scoped, called once per command invocation (not per chunk — a Laravel CLI command holds one persistent connection for its duration), immediately after `check()` passes, before any chunked mutation begins.

### Placement (Design decision 1 — confirm before implementing)

- **`TaxBackfillRunner::apply()`**: add `check()`/`applyLowLockWaitTimeout()` as a **third** preflight step, alongside T097's `check()` and T018's `checkRequiredColumns()`, persisted into the **same** `preflight_checks` envelope under a new `operational_safety` key. This follows the established precedent exactly (T097/T018 both live inside this method, not the CLI) rather than introducing an inconsistent second pattern. `sampleProcesslist()` runs once before the chunked loop and once after `apply()` completes, both captured into `preflight_checks.operational_safety` (before/after, not continuous sampling during the loop — see the note below).
- **`ArchiveOrphanTaxRows.php`**: since `OrphanTaxArchiver`/`OrphanTaxReconciler`/`OrphanTaxDeleter` have no run entity of their own, the gate check and lock-timeout setting happen in the CLI layer's three `--apply` branches (`handleArchive`/`handleReconcile`/`handleDelete`), before delegating to each service. The watchdog/processlist result is added as a new field on each phase's existing result array (`buildResult()`/`buildReconcileResult()`/`buildDeleteResult()`) — no new table, matching how these commands already report everything through one structured result.

**Why CLI-layer for three of four entry points but service-layer for the fourth**: `TaxBackfillRunner` already established the "preflight lives in the service" pattern for schema checks; extending it is the smaller diff. The orphan pipeline commands never established that pattern (no run entity to extend), so CLI-layer is both the only option and stays consistent with *their* existing convention (thin orchestration + one result object).

### Processlist sampling frequency (Design decision 2 — confirm before implementing)

**Proposed: once before mutation starts, once after it completes — not continuous sampling during the chunk loop.** Continuous in-loop sampling would mean adding a periodic side-effect inside the mutation loop itself, which starts to resemble new run-time behavior rather than a bounded safety check. A before/after snapshot satisfies "captured... rather than watched by a human" (automating exactly what quickstart.md's Step 5 asks a human to do) without adding an in-loop concern.

### Extending retry coverage (necessary, not optional — see Grounding)

- `OrphanTaxReconciler::persist()`: wrap each chunked `UPDATE` in `$this->retryService->withDeadlockRetry(...)`, matching `TaxBackfillRunner`'s own usage shape. `OrphanTaxReconciler` gains a `DeadlockRetryService` constructor dependency.
- `OrphanTaxDeleter::delete()`: wrap each chunked `DELETE` the same way. `OrphanTaxDeleter` gains the same dependency.
- **Do not change either class's own idempotency/all-or-nothing semantics** — `persist()` remains all-or-nothing per day, `delete()` remains idempotent-per-chunk (tolerates fewer-than-expected affected rows on retry). The retry wrapper only affects *how* a transient lock-wait/deadlock is handled, never what a successful chunk is allowed to do.

## Config additions (`config/tsms.php`, mirroring the existing `prune`/`watchdog` style exactly)

```php
'backfill' => [
    'idle_transaction_threshold_seconds' => (int) env('TSMS_BACKFILL_IDLE_TXN_THRESHOLD_SEC', 60),
    'lock_wait_timeout_seconds' => (int) env('TSMS_BACKFILL_LOCK_WAIT_TIMEOUT_SEC', 3),
],
```

## Test plan

- **Watchdog blocks before mutation starts** (the brief's primary requirement): open a genuinely separate DB connection/session in the test (a raw second PDO connection to the same test database, not a nested transaction on the same connection — `information_schema.INNODB_TRX` only shows genuinely distinct sessions), start an uncommitted transaction on it, then call `check(thresholdSeconds: 0)` (a near-zero threshold, so the test doesn't need to sleep 60 real seconds) and assert `passed === false`, `idle_transaction_count >= 1`. Close/rollback that connection in `tearDown()`.
- **Watchdog passes clean**: with no other open transaction, `check()` returns `passed === true`, `idle_transaction_count === 0`.
- **`TaxBackfillRunner::apply()` refuses before touching `transaction_taxes`** when the watchdog fails: assert zero rows written, `TaxBackfillRun.status` reflects the refusal (mirroring T097/T018's existing preflight-failure status handling), `preflight_checks.operational_safety.passed === false`.
- **`ArchiveOrphanTaxRows`'s three `--apply` branches all refuse** under the same simulated condition, zero DB writes beyond option parsing (this feature's established query-log verification standard).
- **Lock-wait-timeout is actually set**: assert `SELECT @@session.innodb_lock_wait_timeout` reflects the configured value after a passing gate check, on the same connection the mutation will use.
- **Retry coverage extension**: a test proving `OrphanTaxReconciler::persist()` and `OrphanTaxDeleter::delete()` now retry (not immediately fail) on a simulated lock-wait-timeout/deadlock exception — mirroring `TaxBackfillRunner`'s own existing deadlock-retry regression tests.
- **No outcome change**: existing Slice 12-14 test suites continue to pass unmodified, proving this slice changed no archive/reconcile/delete behavior — only added a gate in front of it.
- **Processlist sampling is non-blocking**: a test asserting `sampleProcesslist()`'s result never affects `passed`/exit code, under any content.

## What's explicitly deferred

- Any change to kill-switch behavior (T033, already implemented) — this brief adds a pre-mutation gate, not a mid-run interrupt mechanism.
- Any change to chunk size defaults or throttle values — orthogonal to this brief.
- A durable table for processlist samples — captured into existing result/preflight envelopes only, per Design decision 1.
