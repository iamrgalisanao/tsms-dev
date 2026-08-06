# Data Model: 100 Tenant Ingestion Resilience

## Ingestion Request

Represents a durable official ingestion attempt before downstream transaction processing.

**Fields**:

- `id`: Internal primary key.
- `submission_uuid`: Provider submission identity.
- `tenant_id`: Tenant resolved from authenticated terminal or payload context.
- `terminal_id`: POS terminal identity.
- `payload_checksum`: Hash/checksum used for idempotency conflict detection.
- `payload`: Raw submitted payload.
- `payload_size_bytes`: Request byte size.
- `source_ip`: Source IP captured for intake auditing.
- `intake_status`: Received, rejected, accepted, or queued.
- `processing_status`: Processing, processed, duplicate, failed retryable, failed permanent, or dead lettered.
- `attempt_count`: Processing attempt count.
- `last_error_code`: Machine-readable processing or rejection error.
- `last_error_message`: Human-readable processing or rejection detail.
- `duplicate_of_intake_id`: Original intake reference for duplicates.
- `trace_id`: Trace identifier stored on the current `transaction_intake` table.
- `received_at`, `queued_at`, `processed_at`: Lifecycle timestamps.
- `created_at`, `updated_at`: Standard timestamps.

**Current schema note**: This entity maps to the existing `transaction_intake` table and `TransactionIntake` model. Do not create a parallel ingestion request table/model unless a later architecture decision explicitly replaces `transaction_intake`.

**Future-schema candidates**: `source` and `accepted_at` may be added later if official, batch, replay, and reconciliation attempts need first-class source attribution or accepted timing separate from received/queued timing.

**Header divergence to resolve**: `AttachCorrelationId` uses `X-Request-Id` and stores request attribute `correlation_id`, while `TransactionIntakeService` currently reads `X-Correlation-ID` for `trace_id`. The implementation must choose one canonical inbound header and propagation path before changing persisted trace semantics.

**Relationships**:

- Belongs to tenant.
- Belongs to terminal.
- May produce one or more transactions.
- May have many processing attempts or failure events.

**Validation Rules**:

- `submission_uuid` is required and unique for idempotent official intake.
- Same `submission_uuid` and same `payload_checksum` returns existing outcome.
- Same `submission_uuid` and different `payload_checksum` returns conflict.
- `payload_size_bytes` must not exceed configured limit.
- Submitted transaction count must not exceed configured limit before intake persistence.

## Backpressure Decision

Represents the system's accept/reject/degraded decision for an ingestion request.

**Fields**:

- `decision`: accept, observe, reject, degraded.
- `reason`: queue_backpressure, db_pressure, redis_unavailable, worker_lag, tenant_rate_limited, terminal_rate_limited, payload_limit, batch_limit.
- `retry_after_seconds`: Clamped positive retry value.
- `queue`: Queue evaluated.
- `shard`: Shard evaluated.
- `queue_depth`: Current queue depth when available.
- `oldest_job_age_seconds`: Queue age/drain signal when available.
- `db_health`: Healthy, degraded, unavailable, unknown.
- `redis_health`: Healthy, degraded, unavailable, unknown.
- `mode`: disabled, observe, enforce.

**Relationships**:

- Associated with an ingestion request or rejected request attempt.
- Feeds metrics, logs, and dashboards.

## Circuit Breaker State

Shared Redis state for protected ingestion dependencies.

**Fields**:

- `name`: Breaker identifier.
- `state`: closed, open, half_open.
- `consecutive_failures`: Count.
- `rolling_failure_rate`: Recent failure percentage.
- `opened_at`: Timestamp.
- `half_open_probe_count`: Probe count.
- `last_success_at`: Timestamp.
- `last_failure_at`: Timestamp.
- `expires_at`: Optional TTL.

**Rules**:

- Infrastructure failures count toward breaker state.
- Client validation failures do not count.
- State is shared across app instances.

## Fairness Bucket

Redis-backed rate or concurrency budget.

**Fields**:

- `scope`: global, tenant, terminal.
- `scope_id`: Tenant or terminal identifier when scoped.
- `limit`: Configured allowance.
- `remaining`: Current allowance.
- `reset_at`: Window reset timestamp.
- `mode`: observe, enforce.

**Rules**:

- Fairness checks run before enqueue.
- Rejections include stable reason and retry metadata.

## Queue Route

Deterministic mapping from tenant to queue.

**Fields**:

- `tenant_id`: Tenant identifier.
- `queue_type`: intake or processing.
- `queue_name`: Selected queue.
- `shard_count`: Configured shard count.
- `algorithm`: Current routing algorithm.

**Rules**:

- All dispatch paths use shared router.
- Changing shard count may remap tenants and requires rollout documentation.

## Operational Signal

Metric/log/alert event used for load-test and release gates.

**Fields**:

- `name`: Metric or event name.
- `tenant_id`: Optional tenant context.
- `terminal_id`: Optional terminal context.
- `route`: API route.
- `queue`: Queue name.
- `shard`: Shard.
- `value`: Numeric or state value.
- `reason`: Rejection/failure reason.
- `correlation_id`: Trace identifier.
- `recorded_at`: Timestamp.
