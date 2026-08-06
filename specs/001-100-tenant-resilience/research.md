# Research: 100 Tenant Ingestion Resilience

## Decision: Prefer async official ingestion boundary

**Rationale**: The live official route currently performs FormRequest validation and large synchronous DB work. An async intake boundary moves heavy validation and transaction writes behind a durable queue and lets the request path shed load quickly.

**Alternatives considered**:

- Move backpressure middleware before FormRequest only: useful compatibility step, but leaves synchronous write path in place.
- Keep current controller and tune workers: insufficient because request and DB capacity can still be exhausted before queue processing.

## Decision: Enforce payload and batch limits before expensive work

**Rationale**: Queue-depth backpressure alone cannot prevent a single oversized request from consuming PHP memory, validation time, or DB transaction duration.

**Alternatives considered**:

- Rely on PHP/web-server limits only: too coarse and not domain-aware.
- Validate after parsing full payload: still allows memory/CPU pressure.

## Decision: Use Redis for breaker, fairness, and enforce-mode health state

**Rationale**: Redis is already part of the queue architecture and provides shared state across app instances. Local filesystem breaker state is not safe in horizontally scaled deployments.

**Alternatives considered**:

- Local cache/filesystem: inconsistent across app nodes.
- Database-backed counters: can worsen DB pressure during overload.

## Decision: Fail closed or bounded-degraded in enforce mode

**Rationale**: During overload enforcement, inability to evaluate backpressure is itself a high-risk condition. Fail-open behavior can allow unbounded traffic into a degraded system.

**Alternatives considered**:

- Always fail open: preserves availability but defeats overload protection.
- Always fail closed, including observe mode: too disruptive for rollout and client discovery.

## Decision: Keep `IngestionQueueRouter` as the single routing authority

**Rationale**: Current work introduced deterministic `crc32` routing. Remaining `% 8` code paths can send the same tenant to different queues and undermine locality/fairness assumptions.

**Alternatives considered**:

- Keep `% 8` for utility/test paths: creates drift and future production risk.
- Adopt consistent hashing now: valuable if shard count changes often, but larger than the immediate release-blocker scope.

## Decision: Separate Horizon ingestion workers from low/notification work

**Rationale**: Shared workers allow non-ingestion jobs to consume capacity during transaction bursts. Dedicated supervisors make capacity and queue age easier to reason about.

**Alternatives considered**:

- Increase shared worker count: may improve throughput but does not prevent starvation and can overload DB.

## Decision: Add fairness before enqueue

**Rationale**: Queue sharding alone does not prevent one hot tenant or terminal from monopolizing request, Redis, worker, or DB capacity.

**Alternatives considered**:

- Per-shard depth only: protects queues but not tenant fairness.
- Post-processing throttles only: too late to protect request and intake capacity.

## Decision: Treat observability as a release gate

**Rationale**: A controlled 100-tenant load test is only useful if the team can see queue age, DB pressure, worker drain, rejection reasons, and tenant skew in real time.

**Alternatives considered**:

- Rely on Horizon only: useful for queues, insufficient for DB locks, API latency, breaker state, and per-tenant skew.

## Decision: Check both intake and processing depth for official ingestion

**Rationale**: Async official intake introduces two distinct overload risks. Intake queue depth protects the request-path buffer and raw ingestion durability; processing queue depth protects downstream DB write throughput. The official ingestion gate should evaluate both and aggregate the result so the system does not accept raw intake indefinitely when processing is already saturated.

**Policy**:

- In observe mode, record both intake and processing decisions without rejecting solely because of either decision.
- In enforce mode, reject if either queue is overloaded.
- In enforce mode, return a degraded response if either required intake or processing health check cannot be evaluated.
- Disabled-mode decisions are excluded from degraded aggregation because disabled backpressure is an intentional bypass, not a failed health evaluation.
- Include both `backpressure.intake` and `backpressure.processing` sub-decisions in response/log context.

**Alternatives considered**:

- Intake-only check: protects request buffering but can overrun DB processing backlog.
- Processing-only check: protects DB throughput but misses a saturated intake buffer.
- Whichever queue trips first only: simpler response shape, but hides the second signal from operators.

**Trade-offs**:

- More Redis/health checks per request.
- Response aggregation must be explicit and stable.
- Operators get clearer diagnosis for whether request buffering or downstream processing is the bottleneck.
