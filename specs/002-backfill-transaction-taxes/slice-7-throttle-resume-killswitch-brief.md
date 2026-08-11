# Slice 7 Architecture Brief — Throttling, Resume, Kill-Switch (T030-T033)

**Date**: 2026-08-11 · **Status**: Approved to implement against this brief

## Why this brief exists

Slice 6 (`c81e1e44`, `f616a0e2`) gave `TaxBackfillRunner::apply()` a reviewed, retry-safe, idempotent-by-construction insert path — but no operational controls around it: no throttling to protect a live database from a large run, no explicit proof that an interrupted run resumes cleanly, and no way for an operator to stop a run in progress short of killing the process. This slice builds those controls into the runner. **`--apply` still does not become CLI-accessible in this slice** — that remains a deliberately separate follow-up, so operators still cannot trigger a real write from the command line after this slice lands either.

## Scope contract

```text
Allowed:
- Configurable inter-chunk throttle (sleep/delay) on TaxBackfillRunner::apply().
- A kill-switch mechanism, checked between chunks, that lets an operator
  stop a running apply() call without kill -9.
- Tests proving an interrupted/stopped run, re-invoked over the same day,
  resumes correctly and completes.
- Whatever minimal plumbing (new apply() parameters, a new TaxBackfillRun
  status if needed) these three things require.

Not allowed:
- Wiring --apply into app/Console/Commands/BackfillTransactionTaxes.php.
  That file stays exactly as Slice 4 left it.
- Orphan archive/reconcile/delete (T066-T072).
- Aggregate refresh, materiality (T039, T046-T051).
- Cross-transaction batch inserts (T078).
- Any change to dryRun(), TaxReconstructionService, or the audit-persistence
  migrations/models beyond what a new TaxBackfillRun status (if used)
  requires.
```

## Design decisions this brief makes

1. **T030 (bounded chunks) is already satisfied by Slice 6 — no new work.** `apply()`'s per-transaction (not merely per-chunk) `DeadlockRetryService`/`DB::transaction()` boundary, fixed in `f616a0e2`, is strictly tighter than "a short transaction per chunk, never across chunks." Nothing to add here; the implementer should confirm this and move on rather than rework it.

2. **T031 (throttle)**: add a `?int $throttleMs = null` parameter to `apply()` (default `null` = no throttle, matching `dryRun()`'s existing lack of one — throttling only matters for the real write path). When set, sleep for that many milliseconds **after each chunk completes**, not after each transaction — per-transaction throttling would be far too granular against a 500-per-chunk default and defeats the point of chunking for throughput. Use `usleep($throttleMs * 1000)`.

3. **T032 (resume)**: resume is **already correct by construction** from Slice 6's idempotency invariant (`taxes()->exists()` checked first, proven by T034's twice-run test) — re-invoking `apply()` over the same day, after any kind of interruption, naturally converges every already-applied transaction to `skipped_existing` and processes the rest fresh. **This slice's job is to prove that explicitly for the interrupted/killed case specifically** (not just the "ran to completion twice" case T034 already covers), via a dedicated test: start a run, force it to stop partway (via the kill-switch or a forced failure), then re-invoke `apply()` over the same day and confirm it completes with the correct final state. No new production code is needed for resume itself — only the test, plus whatever the kill-switch requires.

4. **T033 (kill-switch)**: no existing convention for this in the codebase (checked — no sentinel-file or kill-switch pattern exists elsewhere to reuse). Design decision: a **sentinel file**, checked for existence between chunks (not between individual transactions — same chunk-granularity reasoning as the throttle). `apply()` gains a `?string $killSwitchPath = null` parameter (default `null` = disabled). Before starting each new chunk, if a path was given and `file_exists($killSwitchPath)` is true, stop processing further chunks — do not process the chunk that was about to start. The run must not be marked `failed` or `interrupted` for a deliberate operator stop; those statuses are for genuine errors and unanticipated escapes respectively. **Add a new status, `TaxBackfillRun::STATUS_STOPPED`**, distinct from both, so a deliberate kill-switch stop is honestly distinguishable in the audit trail from a crash. This requires no migration change (`status` is a plain string column) — just a new class constant and a widened set of valid values wherever `status` is checked/reported.

## Test plan

- Throttle: `apply()` with a non-null `$throttleMs` measurably delays between chunks (e.g. assert elapsed wall-clock time is at least `(chunk_count - 1) * throttleMs`, with a small chunk size and small throttle value to keep the test fast). `apply()` with `$throttleMs = null` (the default) has no such delay — a regression here would silently slow down every future caller that doesn't ask for throttling.
- Kill-switch: create the sentinel file before or during a multi-chunk run; confirm `apply()` stops before processing the chunk that would run after the file appears, the run ends `STATUS_STOPPED` (not `failed`/`interrupted`/`completed`), and transactions in chunks already processed before the stop are correctly `applied`/audited — nothing already committed is lost or reprocessed.
- Kill-switch absent (`$killSwitchPath = null`, the default) or file absent: run proceeds to completion exactly as before this slice — no behavior change for existing callers/tests.
- Resume-after-stop: stop a run via the kill-switch partway through a day's transactions, then invoke `apply()` again over the same day (no kill-switch path this time) and confirm it completes, with every transaction — both those applied before the stop and those applied after resuming — ending in the correct final state and exactly one `applied`/audit record each across the two runs combined.
- Resume-after-crash: reuse the existing "escapes both containment layers" technique from Slice 6's tests to force a run to end `interrupted`, then confirm a fresh `apply()` invocation over the same day still completes correctly.

## What's explicitly deferred

- CLI wiring for `--apply`, `--throttle`, or a kill-switch flag/option — a later slice's job.
- Orphan archive/delete, aggregate refresh, materiality — unrelated, already-tracked later work.
