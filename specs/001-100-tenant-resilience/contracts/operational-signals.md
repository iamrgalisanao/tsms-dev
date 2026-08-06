# Contract: Operational Signals and Alerts

## Required Log Context

Every accepted, rejected, failed, or retried ingestion attempt must include:

- `correlation_id`
- `submission_uuid` when available
- `tenant_id` when resolved
- `terminal_id` when resolved
- `route`
- `queue`, computed by the queue router for the intake or processing decision
- `shard`, computed by the queue router for the intake or processing decision
- `backpressure_decision`
- `rejection_reason` when rejected
- `circuit_breaker_state`
- `latency_ms`

`queue` and `shard` are operational context values, not current persisted columns on `transaction_intake`. If they become durable fields later, that must be done through an explicit schema migration.

## Required Metrics

- Ingestion request count by route, tenant, terminal, status, and reason.
- Ingestion p50/p95/p99 latency by route.
- Backpressure rejected count by reason.
- Retry-after seconds distribution.
- Intake queue depth per shard.
- Processing queue depth per shard.
- Oldest job age per queue.
- Worker drain rate per queue.
- Job duration and failure rate by job class and queue.
- DB transaction duration for ingestion writes.
- DB lock waits, deadlocks, slow ingestion queries, and connection saturation.
- Redis latency and error rate.
- Circuit breaker state and transition count.
- Tenant and terminal skew/top talkers.

## Required Alerts

- Queue depth or oldest job age exceeds threshold.
- Worker drain rate falls below threshold while request rate remains high.
- Failed job or retry spike.
- DB lock waits/deadlocks exceed threshold.
- DB connection saturation.
- Circuit breaker opens.
- Redis unavailable or high latency.
- API p95/p99 latency breach.
- Sustained backpressure rejection spike.
- Tenant or terminal skew exceeds threshold.

## Required Runbooks

- Enable/disable backpressure enforce mode.
- Interpret and reset circuit breaker state.
- Scale Horizon workers safely against DB connection limits.
- Replay failed ingestion jobs.
- Identify and throttle/disable abusive tenant or terminal.
- Handle Redis degradation.
- Execute rollback to fallback branch/deployment.
