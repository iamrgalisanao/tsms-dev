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

## Horizon Worker Scaling Safety Under DB Connection Limits

This section is about a related but different lever than shard **count**:
increasing how many Horizon **worker processes** run against the existing
shard topology (`config/horizon.php`'s per-supervisor `processes` value,
e.g. `HZ_HIGH_PROCESSES`/`HZ_INTAKE_PROCESSES` in production,
`processing-supervisor`'s `processes` in staging). It exists because
`specs/001-100-tenant-resilience/plan.md`'s Phase 6 plan states this
binding constraint: *"Increase processing worker capacity only after DB
transaction shortening and DB connection limits are reviewed."* This
section operationalizes that sentence — what to actually check, and what
breaks if you skip the check.

### Why this matters: workers, not shards, are what consume DB connections

Changing `shard_count` (the rest of this runbook) changes how many
**queues** exist and which tenants route to which queue — it does not by
itself change how many **worker processes** are running. Increasing a
supervisor's `processes` count, by contrast, directly increases how many
concurrent PHP processes are running jobs against the database at once.
Each running Horizon worker process is a long-lived PHP process that opens
(and typically holds open) its own connection to the application's database
via `config/database.php`'s `mysql` connection — there is no shared
connection pool across worker processes in this codebase. Adding N more
worker processes to any supervisor adds, at peak, roughly N more concurrent
database connections — on top of whatever every **other** supervisor's
workers, PHP-FPM/web request handlers, and any other service already hold
open against the same database.

This is a different failure mode from the lock/deadlock pressure documented
in `docs/OBSERVABILITY_ALERT_DEFINITIONS.md` §3 (DB Lock/Deadlock Spikes).
That signal indicates contention **between** transactions already holding
connections; the risk this section addresses is running out of connections
to hold in the first place. **Scaling worker count to relieve a lock/deadlock
pressure alert without checking connection headroom risks trading one DB
problem for a worse one** — you may reduce per-worker wait time while
simultaneously pushing total connection count over the database's ceiling.

### What to check before increasing any supervisor's `processes` count

1. **The database's actual connection ceiling:**
   ```sql
   SHOW VARIABLES LIKE 'max_connections';
   ```
2. **Current connection usage under real load — not idle usage:**
   ```sql
   SHOW STATUS LIKE 'Threads_connected';
   SELECT user, host, COUNT(*) AS connections
     FROM information_schema.processlist
     GROUP BY user, host
     ORDER BY connections DESC;
   ```
   Run this during peak traffic, not right after a deploy when workers may
   not have opened their steady-state connections yet. The `GROUP BY user,
   host` breakdown matters: it tells you how much of the current connection
   count is already Horizon workers (all supervisors, not just the one
   you're about to scale) versus PHP-FPM/web requests versus any other
   consumer (reporting connection, replication, monitoring tools, other
   applications sharing the same database instance).
3. **Compute real headroom, not assumed headroom.** Headroom is
   `max_connections` minus **every** existing consumer's peak usage — not
   just the supervisor you intend to scale. Remember that increasing one
   supervisor's `processes` does not reduce any other supervisor's
   connection usage; all of production's supervisors
   (`intake-supervisor`, `high-supervisor`, `reporting-supervisor`,
   `low-supervisor`, `notifications-supervisor`, `webhook-supervisor`) and
   staging's equivalents run concurrently against the same database.
4. **Check for already-long-running transactions**, since a longer average
   transaction duration means each connection is held longer, compounding
   the effect of adding more workers:
   ```sql
   SHOW PROCESSLIST;
   ```
   Look for `Time` values that are unexpectedly large on `Query`/`Execute`
   state rows — this is also the "DB transaction shortening" half of
   Phase 6's constraint: shortening long-held transactions reduces how long
   each connection is occupied, independent of how many workers exist.

### What the failure mode looks like if you skip this

If you increase a supervisor's `processes` count (or add a new supervisor)
without confirming headroom, and the resulting peak connection count
exceeds `max_connections`, **new connection attempts fail outright** —
typically surfacing as `SQLSTATE[HY000] [1040] Too many connections` (or
equivalent) — and this is **not scoped to the supervisor you scaled**. Every
consumer of that database (every other Horizon supervisor, every web
request, any scheduled command) competes for the same finite connection
ceiling. The result is a database-wide capacity outage caused directly by
the "resilience" change, not a resilience improvement — the exact opposite
of Phase 6's intent. This is why the plan's constraint is phrased as a
precondition ("only after... reviewed"), not a suggestion: worker count is
not free capacity until the connection budget underneath it has been
confirmed to support it.

### Checklist

- [ ] Ran `SHOW VARIABLES LIKE 'max_connections';` and recorded the ceiling.
- [ ] Ran the `information_schema.processlist` breakdown **during peak
      load** and recorded current usage by consumer (all Horizon
      supervisors, web/PHP-FPM, other services).
- [ ] Computed headroom = ceiling − current peak usage, and confirmed the
      proposed new worker count fits within it with margin (not exactly at
      the ceiling).
- [ ] Checked `SHOW PROCESSLIST` for abnormally long-running transactions
      and addressed/shortened them where feasible, per Phase 6's "DB
      transaction shortening" half of the same constraint.
- [ ] Only then changed the supervisor's `processes` value (env var, e.g.
      `HZ_HIGH_PROCESSES`) and deployed.
- [ ] Re-checked connection usage post-deploy under real load to confirm
      the new worker count did not push usage close to the ceiling.
