# Shard Count Change Runbook

Operational procedure for changing `TSMS_PROCESSING_SHARD_COUNT` (and, if
intake sharding is also being resized, `TSMS_INTAKE_SHARD_COUNT`) safely,
using `php artisan tsms:shard-topology-verify` (T047) as the drain/verification
tool. That command is **read-only and report-only** — it never writes to
Redis and never calls any Horizon control command — so it is always safe to
run, at any point in the procedures below, including in production.

## Background

- Shard routing lives entirely in `App\Services\IngestionQueueRouter`
  (`app/Services/IngestionQueueRouter.php:18-23,40-43`):
  `crc32((string) $tenantId) % $shardCount`, producing queue names
  `transaction-processing:s{0..shardCount-1}`.
- `config('tsms.processing.shard_count')` (env `TSMS_PROCESSING_SHARD_COUNT`,
  falling back to `TSMS_INTAKE_SHARD_COUNT`, default `8`) is the single
  source of truth for the processing shard count. Both producers (anything
  that calls `IngestionQueueRouter::processingQueueForTenant()`) and
  `config/horizon.php`'s processing supervisor(s) derive from the same env
  var, so there is no separate manual edit needed to keep them in sync — the
  risk is purely a **restart-ordering** one (see "App/Horizon agreement"
  below), not a dual-config-source one.
- The processing supervisor's queue array lives under a different config key
  per environment: `high-supervisor` in production, `processing-supervisor`
  in staging, `default` (folded in with everything else) in local — see
  `config/horizon.php`. The verify command discovers whichever supervisor(s)
  actually own the `transaction-processing:s{N}` queues dynamically, so
  operators do not need to remember which key applies to which environment.

## The core risk this runbook protects against

Because `crc32() % shardCount` produces no error for an index with no live
consumer, **reducing `shard_count` while jobs are already enqueued under the
old, larger count can silently and permanently orphan jobs in Redis** —
they sit in a `transaction-processing:s{N}` queue that no Horizon worker is
listening to anymore, with no exception, no failed-job entry, nothing. The
verify command exists specifically to make that condition visible and
provable-safe (or provable-unsafe) before the config change goes out.

## Increase procedure

Increasing `shard_count` is lower risk than decreasing it — no queue is ever
retired — but it is **not purely additive**. Follow this order:

1. **Report remap scope first.** Run the verify command with `--to` set to
   the larger proposed count:

   ```
   php artisan tsms:shard-topology-verify --to=<new_larger_count>
   ```

   This confirms current app/Horizon agreement and lists (a) the existing
   shard indices that remain live, and (b) the new shard indices that will
   be introduced.
2. **Deploy the new shard-count env var to both producers and Horizon config
   in the same deploy.** Since `config/horizon.php`'s processing queue array
   already derives from the same `TSMS_PROCESSING_SHARD_COUNT` env var (see
   Background above), there is no separate manual Horizon config edit
   required — one env var change, one deploy.
3. **Restart Horizon** so the new, larger queue array takes effect:

   ```
   php artisan horizon:terminate --wait
   ```

   (Your process supervisor — systemd/supervisord/etc. — must be configured
   to relaunch `php artisan horizon` automatically after `horizon:terminate`;
   this command only signals the master supervisor to exit gracefully.)
4. **Re-run the verify command post-deploy** (no `--to` needed, or repeat
   with `--to` at the new count) to confirm app config and Horizon topology
   agree again.

### Increases may remap tenants even though no queue is retired

`crc32(tenantId) % shardCount`'s result depends on the divisor. When the
divisor changes from, say, 8 to 12, a tenant that used to land on
`transaction-processing:s3` under the old count may now compute to a
*different* existing shard (e.g. `s7`) under the new count — even though
shard `s3` itself is never removed and stays fully consumed. This is a
**rebalance blip**, not orphaning: every old shard index continues to exist
and have a live Horizon consumer throughout. But it is a real behavior
change worth calling out explicitly to whoever is watching per-shard
metrics or logs during the cutover — do not assume "increase" means "purely
additive, nothing else changes." Expect a brief redistribution of in-flight
tenant traffic across the shard set immediately after the restart.

## Decrease procedure

Decreasing `shard_count` is the risky direction, because it retires queues
that may still have jobs in them. Follow this order **exactly**:

1. **Run the verify command with `--to` set to the smaller proposed count**
   to see exactly which queues will be retired:

   ```
   php artisan tsms:shard-topology-verify --to=<new_smaller_count>
   ```

   This lists the exact retired queue names (`transaction-processing:s{N}`
   for `N` in `[new_count .. old_count - 1]`) and their current
   ready/reserved/delayed Redis depths.
2. **Do NOT change producer config yet.** Leave `TSMS_PROCESSING_SHARD_COUNT`
   at its current (larger) value in every producer process. New work must
   keep landing on the full existing shard set while draining proceeds.
3. **Pause the processing supervisor** so it stops pulling new jobs but lets
   in-flight jobs finish naturally:

   ```
   php artisan horizon:pause-supervisor <supervisor-name>
   ```

   Use the real supervisor name from `config/horizon.php` for the target
   environment — `high-supervisor` in production, `processing-supervisor`
   in staging (the verify command's output also names whichever supervisor
   it found owning the processing queues, for cross-reference).
4. **Poll the verify command (or manually inspect Redis) until every
   retired queue's ready, reserved, and delayed depth is zero**, or an
   operator-set timeout is hit:

   ```
   watch -n 5 'php artisan tsms:shard-topology-verify --to=<new_smaller_count>'
   ```

   Do not proceed past this step while the command still reports `UNSAFE`
   for any retired queue — that means jobs are still sitting in (or being
   actively reserved from) a queue you are about to stop consuming.
5. **Only once verified empty**, change producer and Horizon shard count
   together (same deploy) and restart Horizon:

   ```
   php artisan horizon:terminate --wait
   ```
6. **Run the verify command again** (no `--to`, or with `--to` at the new
   count) to confirm no retired queue is still assigned to a live
   supervisor and that app/Horizon counts agree.

## Verifying application shard count matches Horizon queue topology

At any point, independent of an in-flight change, `php artisan
tsms:shard-topology-verify` (no `--to`) checks whether
`config('tsms.processing.shard_count')` agrees with the
`transaction-processing:s{N}` queues actually present in the current
environment's Horizon supervisor config. A mismatch here means Horizon
hasn't been restarted since a shard-count config change landed (or, less
commonly, that Horizon was restarted with a config the app side hasn't
picked up yet) — restart Horizon and re-run to confirm agreement. Exit code
`0` means safe/consistent; nonzero means an unsafe condition was found.

## Pre-cutover checklist (decrease)

- [ ] Ran `tsms:shard-topology-verify --to=<new_count>` and reviewed the
      exact list of retired queues.
- [ ] Confirmed producer config is still unchanged (old, larger shard
      count still active everywhere).
- [ ] Paused the correct processing supervisor
      (`horizon:pause-supervisor <name>`).
- [ ] Re-ran the verify command and confirmed every retired queue reports
      `safe` (ready/reserved/delayed all zero) — not just once, but stable
      across at least one additional poll a few seconds later.

## Post-cutover checklist (decrease or increase)

- [ ] Deployed the new shard-count env var to producers and Horizon
      together.
- [ ] Ran `horizon:terminate --wait` and confirmed the process supervisor
      relaunched Horizon.
- [ ] Ran `tsms:shard-topology-verify` (no `--to`) and confirmed app/Horizon
      agreement (exit code `0`).
- [ ] Spot-checked logs/metrics for the newly active (increase) or
      remaining (decrease) shard queues for normal throughput.

## Rollback procedure

**Case A — drain stalled before any config change was made.** If step 4 of
the decrease procedure never reaches all-zero (e.g. a stuck job, an
operator timeout), no config has changed yet — this is a clean resume, not
a revert:

```
php artisan horizon:continue-supervisor <supervisor-name>
```

The supervisor resumes pulling from the full original queue set at the old
shard count. Investigate the stuck job(s) separately before attempting the
decrease again.

**Case B — rollback needed after cutover already happened.** If a problem
is discovered after the shard-count env var was already changed and Horizon
restarted:

1. Revert the shard-count env var (producers and Horizon config) back to
   the prior value, same deploy discipline as the forward change.
2. Restart Horizon again (`horizon:terminate --wait`) to regenerate the full
   old queue array.
3. Re-run `tsms:shard-topology-verify` to confirm app/Horizon agreement at
   the reverted count.

## Honest statement on delivery guarantees

This procedure reduces the risk of **orphaning** jobs (queues with no
consumer at all), but it does not and cannot provide exactly-once
processing. A job that was already reserved by a Horizon worker when that
worker is killed mid-processing (during `horizon:terminate`, a deploy
restart, or any other worker interruption) is subject to Laravel's standard
reserved-job/`retry_after`-driven redelivery behavior
(`config/queue.php`'s `redis.retry_after`) — **this is already true today,
independent of any shard-count change.** Under those same conditions,
duplicate execution of a job is possible today and remains possible after
following this runbook. This runbook does not change that. Idempotency at
the application/business-logic layer — not this runbook, and not the verify
command — is what protects against duplicate-processing effects.
