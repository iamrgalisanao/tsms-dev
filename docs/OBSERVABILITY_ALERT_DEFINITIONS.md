# Observability Alert Definitions

## 0. Naming Honesty — Read This First

This document defines **alert thresholds and operational checks** — it does
**not** describe an implemented alerting system. There is no live evaluator
in this codebase that polls these thresholds, no notification path (no
Slack/PagerDuty/email integration), and no paging. `config/tsms.php`'s
`db_pressure` block (WU8, the one genuinely new config entry this work unit
adds) is read by nothing except this document's manual/operator checks —
grep the codebase and you will not find a scheduled job or listener that
evaluates it.

Every entry below is either:

- **A number an operator (or a future dashboard/monitoring integration, not
  built here) should compare against a value visible in one of WU7's
  read-only observability endpoints** (`docs/OBSERVABILITY_DASHBOARD.md`), or
- **A signal to watch for with no single numeric threshold** (e.g. Redis
  unavailability), documented honestly as such rather than assigned an
  arbitrary number it doesn't need.

Where a threshold already exists in configuration for an unrelated purpose
(backpressure, circuit breaker, fairness), this document references that
existing value — it does not invent a second, competing number. The one
threshold genuinely new to this work unit is DB lock-wait/deadlock-retry
pressure (§3.2), because no config existed for it before WU8 even though
WU3's instrumentation has produced the underlying signal since it landed.

This document does not implement anything. Building a real evaluator/paging
system is future work, out of scope for this repository's current feature
plan (`specs/001-100-tenant-resilience/plan.md`, WU8).

## 1. Entry Format

Every alert/check below specifies, in this fixed order:

| Field | Meaning |
|---|---|
| **Signal** | What is being observed, and where (endpoint/config/command). |
| **Threshold** | The concrete number or condition, and its config source. |
| **Evaluation window** | How often/over what span this should be checked. |
| **Severity** | Rough operational weight (`info` / `warning` / `critical`) — a suggested triage level for a human reading this, not a value read or enforced by any code. |
| **Recovery threshold** | The condition under which the situation is considered resolved. |
| **Data-freshness requirement** | How current the underlying data must be for the check to be meaningful. |
| **False-positive caveat** | A known, honest reason this check can fire (or fail to fire) without the underlying problem it's meant to catch. |
| **Operator response** | What a human should actually do. |
| **Ownership** | Which work unit owns the underlying signal/config. |
| **Manual or automated** | Always **manual** in this document, per §0 — restated per entry so no single entry can be read out of context as "this is automated." |

## 2. Queue Age Breach

- **Signal**: `GET /api/v1/observability/ingestion/queue-age`
  (`ObservabilityController::ingestionQueueAge()`, WU7) — per-shard oldest
  ready-job age (`age_seconds`) for every `transaction-intake:s{N}` and
  `transaction-processing:s{N}` queue. Secondary signal: Horizon's own
  dashboard "long wait" indicator, driven by `config/horizon.php`'s `waits`
  block.
- **Threshold**: `config/horizon.php`'s per-queue `waits` values —
  `redis:transaction-intake` = 1s, `redis:transaction-processing[:s0-s7]` =
  5s each, `redis:low` = 15s, `redis:notifications` = 5s. These are
  Horizon's own "this queue's oldest job has waited longer than expected"
  thresholds (surfaced in the Horizon dashboard UI itself, when reachable);
  this document does not add a second, different number for the same
  queues, per WU8's reference-don't-invent instruction. No listener is
  registered on Horizon's `LongWaitDetected` event in this codebase — the
  `waits` config only drives Horizon's own dashboard display, confirmed by
  searching the codebase for `LongWaitDetected` and finding no subscriber.
- **Evaluation window**: instantaneous per read (`freshness_seconds: 0`,
  live synchronous pull) — check at whatever cadence an operator/dashboard
  polls the endpoint or views the Horizon dashboard; no batching window
  applies to age itself.
- **Severity**: `warning` when any shard's `age_seconds` exceeds its
  `waits` threshold; `critical` if age is climbing across multiple shards
  simultaneously or continues climbing over consecutive checks (suggests
  workers have stalled, not just a transient burst).
- **Recovery threshold**: `age_seconds` back under the relevant `waits`
  threshold for the shard, sustained across at least two consecutive
  operator checks (to avoid treating a single fast-draining moment as
  "resolved" mid-incident).
- **Data-freshness requirement**: the read itself is always live
  (`freshness_seconds: 0`); no additional freshness concern beyond the
  request round-trip.
- **False-positive caveat**: `age_seconds` reflects only the **ready-queue
  head** (per `IngestionBackpressureService::oldestReadyJobAge()`'s
  documented scope, not delayed/reserved jobs) — a queue with zero ready
  jobs reports no age at all, which can look "healthy" even while delayed
  jobs are piling up. Conversely, `available: false` with `reason:
  pushed_at_unavailable` or `redis_unavailable` means age could not be
  honestly determined for that shard at all — treat this the same as "no
  data," never as "age is zero."
- **Operator response**: pair with queue **depth**
  (`/ingestion/queue-depth`, §3.1 of `OBSERVABILITY_DASHBOARD.md`) — high
  depth with low age suggests a recent burst still draining normally; high
  depth **and** high age suggests Horizon workers have stalled or fallen
  behind. Check `php artisan horizon:status` /
  `php artisan horizon:supervisors` for the affected supervisor next, per
  `docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md` §8's processing/intake
  queue overload procedure.
- **Ownership**: WU4 (metric), WU7 (endpoint), pre-existing Horizon config
  (`waits` thresholds) — WU8 only documents the check, it owns none of
  these underlying values.
- **Manual or automated**: manual.

## 3. DB Lock/Deadlock Spikes (WU8-owned threshold)

- **Signal**: `GET /api/v1/observability/ingestion/db-pressure`
  (`ObservabilityController::dbPressure()`, WU7) — `counters.exhausted` and
  the `total_recovery_ms` percentile block, both sourced from WU3's
  `DeadlockRetryService` instrumentation.
- **Threshold**: `config('tsms.db_pressure.exhausted_count_threshold')`
  (default `1`) — any new `exhausted` occurrence within the evaluation
  window is alert-worthy; and/or
  `config('tsms.db_pressure.p95_total_recovery_ms_threshold')` (default
  `2000` ms) — p95 of `total_recovery_ms` exceeding this value. **This is
  the one config entry genuinely new to WU8** — no DB-pressure alert
  threshold existed anywhere before this work unit. See the doc-comment on
  `config/tsms.php`'s `db_pressure` block for the full reasoning (grounded
  in `DeadlockRetryService`'s actual backoff formula:
  `random_int(50000, 150000) * $attempt` microseconds per attempt).
- **Evaluation window**: `config('tsms.db_pressure.evaluation_window_seconds')`
  (default `300`s / 5 minutes). Because `exhausted`/`attempted`/etc. are
  cumulative, never-reset counters (`window: cumulative_since_last_reset`
  in the endpoint's envelope), there is no windowed value to read directly
  — an operator must read the endpoint, wait the evaluation window, read
  again, and compare the delta.
- **Severity**: `warning` on any `exhausted` delta ≥
  `exhausted_count_threshold` within the window, or p95
  `total_recovery_ms` ≥ `p95_total_recovery_ms_threshold`; `critical` if
  both conditions hold simultaneously, or if `exhausted` continues
  incrementing across consecutive windows (sustained, not isolated,
  contention).
- **Recovery threshold**: no new `exhausted` increments and p95
  `total_recovery_ms` back under threshold for at least one full
  subsequent evaluation window.
- **Data-freshness requirement**: counters and percentiles are both live
  reads at request time (`freshness_seconds: 0`); the percentile itself is
  computed over whatever recent samples remain within
  `tsms.metrics.distribution.sample_cap`/`sample_ttl_seconds` — a metric
  that stops receiving traffic for `sample_ttl_seconds` will show a stale
  or empty percentile, not a live "zero" reading.
- **False-positive caveat**: this measures **retry-observed pressure
  only** — per `DeadlockRetryService`'s own class doc-comment, contention
  that resolves entirely at the engine level without producing one of the
  three matched exception phrases (`Deadlock found when trying to get
  lock`, `SQLSTATE[40001]`, `Lock wait timeout exceeded`) is invisible
  here. Zero counters does **not** prove zero DB contention — it proves
  zero *observed* contention through this specific instrumentation path.
  Conversely, `Metrics::percentile()` returns `{"value": null,
  "sample_count": 0}` both when there are genuinely no samples and (per
  `OBSERVABILITY_DASHBOARD.md` §2's documented limitation) when the
  underlying Redis read itself silently failed — a `null` percentile is
  not proof of "no pressure," only "no data available to this read."
- **Operator response**: rising `exhausted`/`attempted` alongside climbing
  `total_recovery_ms` indicates database lock contention independent of
  ingestion queue depth or circuit-breaker state — a different root cause
  from overload/dependency-failure conditions. Escalate to database
  investigation (long-running transactions, missing indexes, lock
  ordering) rather than treating it as an ingestion-layer capacity
  problem. This is not something a queue/worker scaling action resolves.
- **Ownership**: WU3 (instrumentation), WU7 (endpoint), WU8 (this
  threshold config and its documentation).
- **Manual or automated**: manual.

## 4. Circuit Breaker Open

- **Signal**: `GET /api/v1/observability/ingestion/circuit-breaker`
  (`ObservabilityController::circuitBreakerState()`, WU7) — `data.live.state`.
- **Threshold**: `state === 'open'` or `state === 'half-open'`, using the
  existing `tsms.circuit_breaker.failure_threshold` (default 5 consecutive
  failures trip `open`) and `tsms.circuit_breaker.reset_timeout_seconds`
  (default 60s before a half-open probe attempt) — both already defined
  for enforcement by `App\Services\CircuitBreaker`; WU8 does not redefine
  either.
- **Evaluation window**: instantaneous per read; the breaker's own state
  transitions are what changes, not a windowed aggregate.
- **Severity**: `warning` on `half-open` (recovery attempt in progress);
  `critical` on `open` (the downstream dependency is confirmed failing and
  all traffic to it is currently rejected).
- **Recovery threshold**: `state === 'closed'` — reached automatically
  once 2 of up to 3 half-open probes succeed (`half_open_successes`), per
  `adr/T028a-half-open-circuit-breaker-semantics.md`. No manual reset
  should be needed; see caveat below.
- **Data-freshness requirement**: live `HGETALL`-equivalent read
  (`freshness_seconds: 0`); reflects real-time breaker state exactly as
  `redis-cli HGETALL tsms:circuit-breaker:transaction-intake` would.
- **False-positive caveat**: `state` can flap `open` → `half-open` → `open`
  repeatedly if the downstream dependency is intermittently failing —
  a single "recovered" reading (`closed`) between two `open` readings does
  not mean the underlying issue is actually fixed. Also: two legacy/stale
  dashboard surfaces exist in this codebase
  (`App\Http\Controllers\API\V1\CircuitBreakerController` and the
  `Dashboard` variant, both reading the unrelated `App\Models\CircuitBreaker`
  Eloquent model) that must never be used to corroborate or contradict this
  reading — see `docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md` §3.
- **Operator response**: per
  `docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md` §8 — identify and fix the
  underlying downstream failure; do not manually force `state` to
  `closed` (bypasses probe verification); if the breaker cycles
  open→half-open→open repeatedly without closing, escalate to a human
  decision-maker per that runbook's escalation criteria.
- **Ownership**: pre-existing `App\Services\CircuitBreaker`
  (`tsms.circuit_breaker.*` config, already enforced), WU7 (endpoint). WU8
  only documents this check; it owns no new value here.
- **Manual or automated**: manual.

## 5. Redis Unavailable

- **Signal**: no single config threshold — this is a status-pattern check,
  not a numeric one. Watch for **any** WU7 observability endpoint
  returning `"status": "unavailable"` in its response envelope (per
  `docs/OBSERVABILITY_DASHBOARD.md` §2's `status` field definition), or
  observed fail-open/fail-closed side effects: `IngestionFairnessService`
  and WU5's tenant-override check both fail **open** (admit as `inherit`)
  on Redis error (Architecture Invariant 1), while
  `IngestionBackpressureService` fails **closed** only when
  `tsms.intake.backpressure.mode` is `enforce` (per
  `CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md` §5) — in `observe` mode (the
  default), a Redis outage produces no visible rejection at all, only log
  lines (`CircuitBreaker: failed to read state, failing open`, `Failed to
  evaluate ingestion backpressure`).
- **Threshold**: not a number — the condition itself (`status:
  "unavailable"` on any endpoint, or the log lines above appearing).
- **Evaluation window**: instantaneous — this is an availability check,
  not a rate/count.
- **Severity**: `critical` — a genuine Redis outage affects circuit
  breaker, backpressure, fairness, tenant overrides, skew ranking, and
  percentile metrics simultaneously; it is the single highest-blast-radius
  condition this document covers.
- **Recovery threshold**: affected endpoints return to `status:
  "available"`; no manual reset is needed once Redis itself is reachable
  again — every service re-derives state from Redis on its next read (per
  `CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md` §8's "Redis unavailable"
  recovery note).
- **Data-freshness requirement**: none beyond the live read itself — but
  see the false-positive caveat below for where this check cannot
  distinguish "Redis is down" from "nothing has happened yet."
- **False-positive caveat (important, asymmetric)**: three
  endpoints/services (`SkewRankingService`, `Metrics::percentile()`, and by
  extension the skew and percentile endpoints) **swallow their own Redis
  failures internally and return an empty/null result rather than
  propagating an exception** — documented in
  `docs/OBSERVABILITY_DASHBOARD.md` §2 and §3.6/§3.8. A genuine outage on
  those specific reads is indistinguishable from "no data yet" and will
  show `"status": "available"` with empty data, not `"unavailable"`. This
  means the "watch for `unavailable`" signal is reliable for circuit
  breaker, backpressure, and queue depth/age, but **not** reliable on its
  own for skew or percentile reads — corroborate with a different endpoint
  (e.g. circuit breaker or backpressure returning `unavailable`
  simultaneously) before concluding Redis is actually down from a quiet
  skew/percentile reading alone.
- **Operator response**: check Redis connectivity/health directly
  (`redis-cli PING` against the connections named by
  `tsms.circuit_breaker.redis_connection`, `tsms.fairness.redis_connection`,
  `tsms.metrics.distribution.redis_connection`, etc. — all default to
  `default`). If backpressure is in `enforce` mode, expect it to already be
  fail-closed and rejecting traffic (the intended protection); if in
  `observe` mode, be aware ingestion is currently unprotected by
  backpressure and any active WU5 tenant `blocked`/`reduced_limit`
  override has also failed open to unrestricted admission for that tenant
  — see WU9's tenant-throttling runbook for the full operator trade-off
  this implies.
- **Ownership**: cross-cutting — every Redis-backed service (WU2-WU5)
  contributes to this signal; WU8 documents the aggregate pattern, it does
  not own any single piece of it.
- **Manual or automated**: manual.

## 6. Fairness / Tenant-Skew Anomaly

- **Signal**: `GET /api/v1/observability/ingestion/skew`
  (`ObservabilityController::tenantTerminalSkew()`, WU7) for identifying
  which tenant/terminal is driving volume; `GET
  /api/v1/observability/ingestion/rejections`
  (`ObservabilityController::rejectionReasons()`, WU7) for the
  `fairness` counter specifically.
- **Threshold**: `tsms.fairness.global.limit` (default 10000/min),
  `tsms.fairness.tenant.limit` (default 200/min),
  `tsms.fairness.terminal.limit` (default 50/min) — all pre-existing,
  already enforced by `IngestionFairnessService`; WU8 references these
  values rather than adding new ones. An "anomaly" in the skew ranking is
  a single tenant/terminal's `count` (within `tsms.fairness.window_seconds`,
  default 60s) approaching or repeatedly hitting its respective limit,
  visible as `ingestion.rejected.fairness` incrementing on the rejections
  endpoint.
- **Evaluation window**: `tsms.fairness.window_seconds` (default 60s) for
  the fairness limits themselves; `tsms.metrics.skew.window_seconds`
  (default 300s) for the top-N skew ranking, which is a separate,
  longer-lived window used only for the "who is driving this" view, not
  for the enforcement decision itself.
- **Severity**: `info` if `fairness` rejections are isolated/occasional
  (a single tenant briefly bursting past its own limit, working as
  intended); `warning` if one tenant/terminal consistently dominates the
  top-N skew ranking across multiple windows; `critical` if the **global**
  limit is being approached (a system-wide capacity concern, not a single
  noisy tenant).
- **Recovery threshold**: `ingestion.rejected.fairness` delta returns to a
  baseline/occasional rate, and no single tenant/terminal dominates the
  skew ranking's top-N for multiple consecutive windows.
- **Data-freshness requirement**: skew ranking is a live read over
  `tsms.metrics.skew.window_seconds`-old data at most (`window:
  current_window`); rejection counters are live cumulative reads
  (`freshness_seconds: 0`).
- **False-positive caveat**: `SkewRankingService` swallows its own Redis
  failures and returns an empty list rather than raising an error — a
  quiet skew ranking during a genuine Redis outage looks identical to a
  genuinely quiet traffic window (see §5's caveat). Also, the skew
  ranking's `member_cap` (default 500) means that under extreme, unexpected
  fan-out, the lowest-ranked member is evicted on insert — a currently
  low-volume tenant/terminal newly entering during a busy window may not
  appear even though it is technically "in" the window.
- **Operator response**: if one tenant is dominating, consider whether a
  WU5 tenant-throttle override (`ingestion:tenant-throttle:set --tenant=
  --mode=reduced_limit|blocked --ttl= --reason=`) is warranted as an
  incident-response action — see `docs/OBSERVABILITY_DASHBOARD.md` §3.6's
  cross-reference and WU9's tenant-throttling runbook for the command's
  full usage. If the **global** limit is being approached, this is a
  capacity conversation (raise `tsms.fairness.global.limit` deliberately,
  or scale ingestion capacity), not a single-tenant throttle action.
- **Ownership**: WU4 (skew ranking + rejection counters), WU7 (endpoints),
  pre-existing `IngestionFairnessService`/`tsms.fairness.*` config
  (enforcement, unchanged by WU8).
- **Manual or automated**: manual.

## 7. Backpressure Overload

- **Signal**: `GET /api/v1/observability/ingestion/backpressure` and
  `/ingestion/queue-depth` (`ObservabilityController::backpressureState()`/
  `ingestionQueueDepth()`, WU7) — per-shard `depth` vs. `threshold`.
- **Threshold**: `tsms.intake.backpressure.max_queue_depth` (default
  5000) — pre-existing, already enforced by
  `IngestionBackpressureService::checkQueue()`; WU8 references this value,
  does not add a new one.
- **Evaluation window**: instantaneous per read (`LLEN`-based live depth);
  check trend by re-polling, there is no built-in windowing on depth
  itself.
- **Severity**: `warning` when any shard's `depth` approaches
  `threshold` (e.g. within 20%, an operator judgment call, not a second
  config value); `critical` when `depth >= threshold` on any shard,
  especially if `tsms.intake.backpressure.mode` is `enforce` (meaning
  requests are actively being rejected/degraded right now, not merely
  logged).
- **Recovery threshold**: `depth` back under `threshold` for the affected
  shard(s), sustained across at least two consecutive checks.
- **Data-freshness requirement**: live `LLEN` read at request time
  (`freshness_seconds: 0`).
- **False-positive caveat**: in `observe` mode (the default,
  `TSMS_INTAKE_BACKPRESSURE_MODE`), a queue can sit above `threshold`
  indefinitely while producing **zero** actual rejections — the
  `enforced` field distinguishes "logged only" from "actually rejecting."
  Do not assume an over-threshold depth reading means traffic is being
  shed; check `mode` on the same response. Conversely, when the Redis
  depth check itself fails, `checkQueue()` fails closed **only** in
  `enforce` mode (per `CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md` §5) — a
  `degraded`/`unavailable` reading in `observe` mode does not mean
  traffic is protected.
- **Operator response**: per
  `docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md` §8 — check Horizon worker
  health for the affected supervisor (`php artisan horizon:status` /
  `horizon:supervisors`); confirm workers are running and not stalled; if
  genuinely under-provisioned for load, this is a capacity/scaling
  decision to escalate, not a runbook action to perform unilaterally.
- **Ownership**: pre-existing `IngestionBackpressureService`/
  `tsms.intake.backpressure.*` config (enforcement, unchanged by WU8), WU7
  (endpoints).
- **Manual or automated**: manual.

## 8. Tenant-Override-in-Effect

- **Signal**: `php artisan ingestion:tenant-throttle:status --tenant=<id>`
  (WU5's `TenantThrottleStatus` console command) — `mode`, `expires_at`,
  `operator`, `reason`.
- **Threshold**: not an anomaly threshold — `tsms.tenant_throttle.max_ttl_seconds`
  (default 14400 / 4 hours, defined and enforced by WU5, **not** redefined
  here) is the hard ceiling any override's TTL is checked against at
  *creation* time; there is nothing further for this check to threshold
  against once an override is active, because every override already
  carries its own bounded, self-expiring `expires_at`.
- **Evaluation window**: ad hoc — check whenever an override is known to
  be active (e.g. immediately after `ingestion:tenant-throttle:set`, or
  periodically during an incident/drill where one was set).
- **Severity**: `info` — this documents an **intentional operator
  action**, not an anomaly. It is included here for completeness (per
  plan.md's WU8 required-checks list) even though it is not a failure
  condition by itself.
- **Recovery threshold**: not applicable in the anomaly sense — the
  relevant question is whether the override's `expires_at` has passed (it
  will, automatically, by design; WU5 introduces no scheduled sweeper or
  keyspace-notification mechanism, so nothing *executes* at expiry — a
  missing/expired key is simply treated as `inherit` on the next read).
- **Data-freshness requirement**: the status command is a live read at
  invocation time; there is no caching.
- **False-positive caveat (important, honest)**: per WU5's own documented
  expiry/absence semantics, **a missing or expired override key cannot be
  distinguished from a never-created one** — the store has no
  never-created / explicitly-cleared / TTL-expired distinction from key
  absence alone (only a retained audit log entry with a past `expires_at`
  can infer "this expired," never key absence by itself). This means this
  "check" is fundamentally **not a leak-detection mechanism** — since
  every override is TTL-bounded and self-expires by design, there is no
  scenario where an override silently persists past its intended duration
  for this check to catch. What it actually verifies is narrower and more
  useful during an incident: *did the intended incident-response action
  actually resolve* — i.e., after the incident that prompted the override
  is believed over, does `status` show `inherit` (expired/cleared as
  expected), or does it still show `reduced_limit`/`blocked` with time
  remaining (meaning the operator needs to decide whether to let it run
  out or clear it early with `ingestion:tenant-throttle:clear`)?
- **Operator response**: if `status` shows an active override
  (`reduced_limit` or `blocked`) after the triggering incident is
  believed resolved, decide whether to let the remaining TTL expire
  naturally or run `ingestion:tenant-throttle:clear --tenant=<id>`
  (idempotent; a no-op success if already absent) to restore normal
  admission immediately. If `status` unexpectedly shows `inherit` while an
  override was believed active, check whether it already expired
  (compare against the `--ttl=` originally requested and when `set` was
  run) rather than assuming it was never applied.
- **Ownership**: WU5 (override service, config, and commands, all
  pre-existing and unchanged by WU8). WU8 documents this check only; it
  introduces no new config or command here.
- **Manual or automated**: manual.

## 9. What This Document Does Not Cover

- A live alert evaluator, notification integration, or paging system — see
  §0. None exists in this repository.
- Endpoint response shapes and general usage guidance — see
  `docs/OBSERVABILITY_DASHBOARD.md`.
- Circuit breaker/backpressure state-machine semantics and recovery
  procedures in full — see `docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md`.
- Tenant-throttle command usage and the observe-mode outage risk during an
  active override — see WU9's `docs/TENANT_THROTTLING_RUNBOOK.md` (not yet
  written as of this document; WU9 is a later, separate work unit in the
  same plan).
- Shard-count topology changes — see `docs/SHARD_COUNT_CHANGE_RUNBOOK.md`.
- **Failed job spikes and ingestion-latency breach** — named in Phase 8's
  original high-level sketch (superseded by this detailed WU8 section, per
  plan.md) but deliberately not given an entry here: no failed-job-count
  endpoint and no ingestion-latency percentile distribution exist in this
  codebase today (`Metrics::sample()` currently has only WU3's
  `db.deadlock_retry.*` call sites — see `ObservabilityController::
  percentileMetrics()`'s own doc-comment). Consistent with this document's
  "reference real signals, don't invent numbers" principle, no threshold
  is defined for either until a future work unit adds the underlying
  instrumentation.

## Unknown / Not Verifiable From This Repository

- Real production alerting/paging tooling (Grafana, PagerDuty, Slack,
  etc.) that might eventually consume these thresholds is not present in
  this repository — **unknown**.
- Whether the suggested severities (`info`/`warning`/`critical`) match any
  organization-specific incident-severity taxonomy is **unknown** — they
  are offered here only as a relative-ordering suggestion for a human
  reader, not a standard this repository enforces.
