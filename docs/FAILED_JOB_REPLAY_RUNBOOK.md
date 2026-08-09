# Failed Job Replay Runbook

## 1. Purpose and Scope

This runbook documents `php artisan tsms:reconcile-intake`
(`App\Console\Commands\ReconcileStrandedIntake`) — the one command in this
codebase that repairs stranded/incomplete transaction intake, and the only
operational tool for the "failed job replay" scenario named in
`specs/001-100-tenant-resilience/plan.md`'s Phase 8 acceptance criteria.

This command does two unrelated things, selected by which flags are passed.
It is not two separate commands because they share the same
`TransactionIntake` model, the same `TransactionIngestService`/
`IngestionQueueRouter` dependencies, and the same operational moment (an
ingestion pipeline that stopped fully draining) — but they diagnose and
repair different failure shapes, and this runbook treats them as two modes
throughout.

This document does not cover circuit-breaker or backpressure recovery (see
`docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md`) or shard-topology changes
(see `docs/SHARD_COUNT_CHANGE_RUNBOOK.md`). It assumes those mechanisms are
either healthy or already being worked separately — re-dispatching stranded
work while the pipeline underneath is still broken just re-strands it (see
§4).

## 2. The Command and Its Two Modes

Full signature (`app/Console/Commands/ReconcileStrandedIntake.php:17-28`):

```
tsms:reconcile-intake
    {--from= : Received-at start date/time for missing processed intake scan}
    {--to= : Received-at end date/time for missing processed intake scan}
    {--tenant= : Limit missing processed intake scan to one tenant ID}
    {--terminal= : Limit missing processed intake scan to one terminal ID}
    {--limit=100 : Maximum processed intake records to inspect}
    {--accepted-stale-minutes=2 : Minutes before ACCEPTED intake is considered stranded}
    {--queued-stale-minutes=10 : Minutes before QUEUED intake that never started processing is considered stranded}
    {--processing-stale-minutes=10 : Minutes before PROCESSING intake is considered stranded}
    {--retryable-stale-minutes=10 : Minutes before FAILED_RETRYABLE intake is considered stranded}
    {--dry-run : Report processed intakes without matching transactions, without repairing}
    {--repair-missing : Re-ingest processed intake records that have no matching transaction row}
```

**Mode selection** (`handle()`, line 34): if **either** `--dry-run` or
`--repair-missing` is present, the command runs Mode B (§4) exclusively —
none of the `*-stale-minutes` options apply, and no stranded-record
re-dispatch happens. If **neither** is present, it runs Mode A (§3)
exclusively — `--from`/`--to`/`--tenant`/`--terminal`/`--limit` are ignored.
There is no combined invocation; you cannot ask for both in one run.

**Important nuance on `--dry-run` vs `--repair-missing` together**: passing
both flags at once does **not** make `--dry-run` win. The only thing
`--dry-run` does is satisfy the `dry-run || repair-missing` check that
selects Mode B at all (line 34); whether repair actually happens is decided
solely by `(bool) $this->option('repair-missing')` (line 136). If you pass
`--dry-run --repair-missing` together, it repairs — `--dry-run` is not a
"preview only, even if repair is also requested" safety switch. To preview
without repairing, pass `--dry-run` **alone**.

## 3. Mode A (Default) — Re-dispatch Stranded Intake

**When neither `--dry-run` nor `--repair-missing` is passed.** Finds intake
records stuck in an in-progress state past a configurable staleness window
and re-dispatches them into the ingestion pipeline.

Four independent staleness queries, unioned and de-duplicated by intake ID
(`strandedAcceptedQuery`/`staleQueuedPendingQuery`/`staleQueuedProcessingQuery`/
`staleQueuedRetryableQuery`, lines 87–130):

| Flag (default) | Matches | Meaning |
|---|---|---|
| `--accepted-stale-minutes` (2) | `intake_status = ACCEPTED` and `received_at <= now - N min` | Accepted but never even enqueued. |
| `--queued-stale-minutes` (10) | `intake_status = QUEUED`, `processing_status` null, and `queued_at` (or `received_at` if `queued_at` is null) `<= now - N min` | Enqueued but a worker never picked it up. |
| `--processing-stale-minutes` (10) | `intake_status = QUEUED`, `processing_status = PROCESSING`, and `updated_at` (or `queued_at` if null) `<= now - N min` | Picked up but a worker died/crashed mid-processing. |
| `--retryable-stale-minutes` (10) | `intake_status = QUEUED`, `processing_status = FAILED_RETRYABLE`, same timestamp fallback | Failed once, marked retryable, but never actually retried. |

For each matched record, the command:

1. Dispatches `ProcessTransactionIntakeJob::dispatch($intake->id, $intake->trace_id)`
   onto `IngestionQueueRouter::intakeQueueForTenant($intake->tenant_id)`
   (line 61 — the same shard-routed `transaction-intake:s{N}` queue normal
   traffic uses; this is a direct Redis enqueue, **not** an HTTP request, so
   it never passes through `circuit.breaker:transaction-intake`,
   `ingestion.backpressure:processing`, or fairness middleware — see the
   caution in §4).
2. Sets `intake_status = QUEUED` and `queued_at = now()`.
3. If the record's `processing_status` was `PROCESSING`, clears it to `null`
   so the re-dispatched job starts clean.

**Per-record failures do not fail the command.** If an individual
`dispatch()`/`update()` throws, it is logged
(`ReconcileStrandedIntake: failed to re-dispatch stranded intake`, with
`intake_id`/`submission_uuid`/`error`) and the loop continues to the next
record (lines 75–81). **The command always returns `SUCCESS` in this mode**,
even if every single re-dispatch failed — the exit code alone cannot tell
you whether any record actually got re-dispatched successfully. Always
check the logs, not just the exit code, after a Mode A run (see §5).

## 4. Mode B — `--dry-run` / `--repair-missing`: Processed Intake Missing a Transaction Row

**When `--dry-run` and/or `--repair-missing` is passed.** Finds intake
records already marked `PROCESSING_STATUS_PROCESSED` that have **no**
matching row in the `transactions` table — i.e., intake believes the work
finished, but the transaction it should have produced doesn't exist.

- `processedIntakeQuery()` (lines 227–249): `processing_status = PROCESSED`,
  optionally filtered by `--from`/`--to` (against `received_at`), `--tenant`,
  `--terminal`; ordered by `id`, capped at `--limit` (default 100).
- For each matched intake, `transactionPayloads()` reads the transaction
  payload(s) out of the intake's own stored `payload` JSON (`transactions`
  array for a batch, or `transaction` for a single submission).
- For each payload's `transaction_id`, `transactionExists()` checks the
  `transactions` table for a row matching `tenant_id` + `terminal_id` +
  `transaction_id`. If none exists, it's added to the `$missing` list.
- **`--dry-run` (without `--repair-missing`)**: reports the `$missing` list
  as a table (Intake ID, Submission UUID, Tenant, Terminal, Transaction ID,
  Receipt, Received, Processed) and stops. Read-only — no mutation, no
  ingest call, no job dispatch.
- **`--repair-missing`**: for each missing row, `repairProcessedIntake()`
  (lines 260–315) reconstructs a payload from the transaction payload plus
  the intake's own `submission_uuid`, `submission_timestamp`, `tenant_id`,
  `terminal_id`, and **the intake's already-stored `payload_checksum`**
  (reused as-is, not recomputed — the repair path trusts the originally
  accepted payload as authoritative), then calls
  `TransactionIngestService::ingest($payload)`. If the result status is
  `accepted`/`success`/`already_processed` and an `id` is present, it counts
  as `repaired`; if the status was specifically `accepted`, it also
  dispatches `ProcessTransactionJob::dispatch($result['id'])` onto
  `IngestionQueueRouter::processingQueueForTenant()`. Anything else counts
  as `failed`, with the exception/result logged.

Output: the missing-rows table, a count line, and — in repair mode —
`Repair complete. Repaired: X. Skipped: Y. Failed: Z.` (or, in dry-run,
`Dry run only. Re-run with --repair-missing to re-ingest and queue
transaction processing.`). **Unlike Mode A, this mode's exit code reflects
outcome**: `FAILURE` if `$failed > 0`, `SUCCESS` otherwise (line 224).

## 5. This Command Is Already Scheduled — Know What "Manual" Adds

Both modes already run automatically (`routes/console.php:25-39`):

- `tsms:reconcile-intake` (Mode A, all default staleness thresholds) —
  **every minute**, `withoutOverlapping`, `onOneServer`.
- `tsms:reconcile-intake --repair-missing` (Mode B, repair, all defaults —
  no `--from`/`--to`/`--tenant`/`--terminal`, `--limit=100`) — **daily at
  23:00**, `withoutOverlapping`, `onOneServer`.

A manual invocation during an incident is for one of:

- **Not waiting for the next tick.** Mode A already runs every minute, so a
  manual run only matters if you need confirmation *right now* rather than
  within the next 60 seconds, or workers were down long enough that you
  want an immediate wave of re-dispatches the moment they're back up.
- **Different thresholds than the schedule uses.** All four
  `*-stale-minutes` flags are at their defaults in the scheduled call — pass
  tighter values during an incident to catch records that haven't yet
  crossed the default 2/10/10/10-minute windows, or looser values to avoid
  flagging records that are merely slow, not stuck.
- **Repairing before 23:00, or scoped to one tenant/terminal/time range.**
  The scheduled repair run has no `--tenant`/`--terminal`/`--from`/`--to` —
  a manual `--dry-run` or `--repair-missing` invocation with those flags set
  is the only way to inspect or fix a specific tenant's missing rows without
  waiting for (or being buried in) the full nightly scan.

## 6. Before You Run It Manually

**Check Horizon worker health first — always.** Mode A's re-dispatch and
Mode B's repair-dispatch both push jobs onto the same
`transaction-intake:s{N}` / `transaction-processing:s{N}` queues that
normal traffic uses. If the reason records are stranded is that Horizon
workers are down, crashed, or stalled, re-dispatching into that same broken
pipeline does nothing but re-strand the same records a few minutes later —
worse, it can also **inflate queue depth** past
`tsms.intake.backpressure.max_queue_depth` (default 5000,
`IngestionBackpressureService::checkQueue()`), which then affects *new*
incoming HTTP traffic through `ingestion.backpressure:processing` /
`TransactionIntakeService::handleOfficialIntake()`'s aggregate check — an
unrelated-looking side effect of running this command into an unhealthy
pipeline. See `docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md` §5–§7 for
those mechanics.

Before running either mode manually:

1. `php artisan horizon:status` and `php artisan horizon:supervisors` —
   confirm the relevant supervisor (`intake-supervisor` for Mode A
   re-dispatch, `high-supervisor`/`processing-supervisor` for Mode B's
   `ProcessTransactionJob` dispatch — see `docs/SHARD_COUNT_CHANGE_RUNBOOK.md`
   Background for the per-environment supervisor names) is actually running
   and not paused/stalled.
2. Check current queue depth for the affected shard(s) before adding more
   work to it: `redis-cli LLEN queues:transaction-intake:s{N}` /
   `queues:transaction-processing:s{N}`, or the observability endpoint
   equivalents in `docs/OBSERVABILITY_DASHBOARD.md`. If depth is already
   near `max_queue_depth`, fix the worker/capacity problem first — don't
   re-dispatch into it.
3. If the stranding was caused by a Redis outage rather than a worker crash,
   confirm Redis is actually healthy again (`redis-cli PING` against the
   connections named in `docs/OBSERVABILITY_ALERT_DEFINITIONS.md` §5)
   before running either mode — re-dispatch/repair calls will themselves
   fail (and be logged, not thrown) against an unreachable Redis-backed
   queue.

## 7. What Success Looks Like / After You Run It

**Mode A:**

- Re-run `php artisan tsms:reconcile-intake` (no flags). A fully-cleared
  incident reports `No stranded accepted or stale queued intake records
  found.`
- Check logs for `ReconcileStrandedIntake: failed to re-dispatch stranded
  intake` entries from your run — their absence, not just the command's
  `SUCCESS` exit code, is what confirms every matched record was actually
  re-dispatched (§3).
- Watch queue depth/age (`docs/OBSERVABILITY_DASHBOARD.md`) over the
  following minutes to confirm the re-dispatched jobs are actually draining,
  not just sitting newly-`QUEUED` again.

**Mode B:**

- Read the printed `Repair complete. Repaired: X. Skipped: Y. Failed: Z.`
  line and the command's exit code (non-zero means `Failed > 0`).
- For any `Failed` count, check logs for `ReconcileStrandedIntake: failed to
  repair processed intake missing transaction row` (repair attempted, ingest
  rejected it) or `ReconcileStrandedIntake: exception while repairing
  processed intake` (an exception was thrown) to find the specific
  intake/transaction IDs and root cause.
- Re-run with `--dry-run` (same `--from`/`--to`/`--tenant`/`--terminal`
  scope) to confirm the missing-rows list is now empty.

## 8. Safety Notes

- **Mode A mutates state.** It is not a read-only/report-only command like
  `tsms:shard-topology-verify` — it dispatches jobs and updates
  `intake_status`/`queued_at`/`processing_status` on every matched record.
  Running it while Horizon is still down does not corrupt data, but it does
  reset `queued_at` to "now" on every pass (including the scheduled
  every-minute run), which can mask how long a record has actually been
  stuck if you're eyeballing timestamps mid-incident — check `received_at`
  or logs for the original timeline, not `queued_at`, once this command has
  touched a record.
- **Mode B repair also mutates state** — it calls
  `TransactionIngestService::ingest()`, which can create new rows in the
  `transactions` table. `--dry-run` **alone** (without `--repair-missing`)
  is the only truly read-only invocation of Mode B.
- Re-running Mode B's repair on the same window is safe to retry — a
  transaction that has since been created (by a prior repair run, or by the
  pipeline catching up on its own) will simply stop appearing in the
  `$missing` list on the next pass, since `transactionExists()` re-checks
  live.

## 9. Validation

```
php artisan test tests/Feature/ReconcileStrandedIntakeTest.php   # expect: all pass
```

## Unknown / Not Verifiable From This Repository

- Real production incident history (how often Mode A's scheduled every-minute
  run has actually found stranded records, or how large Mode B's nightly
  missing-row counts typically run) is not present in this repository —
  **unknown**.
