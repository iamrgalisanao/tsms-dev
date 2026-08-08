# Contract: Ingestion API Resilience Behavior

## Official Ingestion Accepted

**Endpoint**: `POST /api/v1/transactions/official`

**Status**: `202 Accepted` for newly queued durable intake records and idempotent replays of already accepted intake records.

**Healthy behavior**:

- Performs cheap overload and payload limit checks before expensive validation.
- Persists a durable intake record.
- Dispatches downstream processing after commit.
- Returns an accepted response with a stable submission reference.

**Response fields**:

- `success`: true
- `status`: accepted or queued
- `submission_uuid`
- `intake_id` or equivalent status reference
- `correlation_id`
- `retryable`: false

## Official Ingestion Backpressure Rejection

**Status**: `429 Too Many Requests` or configured rejection status (`tsms.intake.backpressure.reject_status`).

**Headers**:

- `Retry-After`: Positive integer seconds; must match body retry seconds.

**Response fields**:

- `success`: false
- `error_code`: `INGESTION_BACKPRESSURE`
- `message`
- `retry_after_seconds`
- `retry_after`
- `correlation_id`
- `backpressure.*`: either the **single-queue shape** or the **aggregate shape** below, depending on which check produced the rejection. Source: `IngestionBackpressureService::rejectionPayload()`.

**Single-queue shape** (`checkQueue()`/`checkIntake()`/`checkProcessing()` called directly, e.g. legacy single-queue call sites):

- `backpressure.queue` — resolved queue name
- `backpressure.queue_type` — `intake` or `processing`
- `backpressure.queue_depth`
- `backpressure.threshold`
- `backpressure.mode`
- `backpressure.reason` — `{queue_type}_queue_depth_exceeded` (e.g. `intake_queue_depth_exceeded`)

**Aggregate shape** (`checkAggregate()`, which is what the official ingestion path actually calls — it evaluates intake and processing queue pressure together in one decision). This is a **nested** shape, not the flat one above:

- `backpressure.intake.queue`
- `backpressure.intake.queue_type`
- `backpressure.intake.queue_depth`
- `backpressure.intake.threshold`
- `backpressure.intake.mode`
- `backpressure.intake.overloaded`
- `backpressure.intake.degraded`
- `backpressure.processing.queue`
- `backpressure.processing.queue_type`
- `backpressure.processing.queue_depth`
- `backpressure.processing.threshold`
- `backpressure.processing.mode`
- `backpressure.processing.overloaded`
- `backpressure.processing.degraded`
- `backpressure.reason` — one of `intake_queue_depth_exceeded`, `processing_queue_depth_exceeded`, or `intake_and_processing_queue_depth_exceeded`, computed from whichever sub-decision(s) are both `enforced` and `overloaded`. Source: `aggregateBackpressureContext()`/`aggregateReason()`.

## Official Ingestion Dependency Degraded

**Status**: `503 Service Unavailable` (`IngestionBackpressureService::degradedStatus()` pins this to `503`; it does not use the configurable `reject_status`).

**When**:

- Backpressure health cannot be evaluated (Redis error) while `tsms.intake.backpressure.mode` is `enforce`. In `observe` mode the same failure does not degrade the request — see `checkQueue()`'s catch branch.

**Response fields** (`IngestionBackpressureService::degradedPayload()`):

- `success`: false
- `error_code`: `INGESTION_DEGRADED`
- `message`: `"Ingestion health could not be evaluated. Retry later."`
- `retry_after_seconds`
- `retry_after`
- `correlation_id`
- `reason` — a flat string (not nested under `backpressure`), computed by `degradedReason()`:
  - Single-queue check: `{queue_type}_health_check_failed` (e.g. `intake_health_check_failed`, `processing_health_check_failed`)
  - Aggregate check (`checkAggregate()`, the path the official ingestion endpoint uses): `intake_health_check_failed`, `processing_health_check_failed`, or `intake_and_processing_health_check_failed`, using the same `degraded`-flag combination logic as the backpressure `reason` above.

Note: unlike the backpressure rejection body, the degraded body does **not** nest a `backpressure` object with the per-queue `intake`/`processing` breakdown — only the single `reason` string is included.

## Circuit Breaker Open (`503`)

**Status**: `503 Service Unavailable`, returned directly by `App\Http\Middleware\CircuitBreakerMiddleware` when `CircuitBreaker::isAvailable()` returns `false` for the route's service key (e.g. `transaction-intake`).

**Response fields** — this is a **separate, inconsistent** response shape from the two above. It was not updated to match the `success`/`error_code`/`correlation_id` contract used elsewhere in this document:

```json
{
    "error": "Service unavailable",
    "service": "transaction-intake",
    "message": "Circuit is open due to multiple failures"
}
```

- `error` (not `error_code`, not `success: false`)
- `service` — the circuit breaker's service key
- `message`

**Known inconsistency**: this response has no `success`, `error_code`, `correlation_id`, `retry_after_seconds`, or `retry_after` fields, so a client that only knows how to parse `INGESTION_BACKPRESSURE`/`INGESTION_DEGRADED` bodies cannot uniformly detect or handle a circuit-breaker rejection. This is documented as-is rather than normalized in the docs; if it needs to match the other contracts, that is a follow-up code change to `CircuitBreakerMiddleware`, not a docs fix.

## Payload Too Large

**Status**: `413 Payload Too Large`

**Response fields**:

- `success`: false
- `error_code`: `PAYLOAD_TOO_LARGE`
- `max_payload_bytes`
- `correlation_id`

## Batch Count Exceeded

**Status**: `422 Unprocessable Entity` unless existing API convention selects `400`.

**Response fields**:

- `success`: false
- `error_code`: `BATCH_LIMIT_EXCEEDED`
- `max_batch_count`
- `correlation_id`

## Idempotency Conflict

**Status**: `409 Conflict`

**When**:

- Same `submission_uuid` is replayed with a different payload checksum/hash.
- Same `submission_uuid` is claimed by a different terminal.
- Same `submission_uuid` exists in the durable intake layer with different terminal or payload identity.

**Response fields**:

- `success`: false
- `error_code`: `IDEMPOTENCY_CONFLICT`
- `submission_uuid`
- `correlation_id`

The existing receipt/date conflict remains a separate `DUPLICATE_RECEIPT_CONFLICT`, and identical resubmission of an already rejected payload remains a validation/rejection replay instead of an idempotency conflict.

## Fairness Rejection

**Status**: `429 Too Many Requests`

**Response fields**:

- `success`: false
- `error_code`: `TENANT_RATE_LIMITED`, `TERMINAL_RATE_LIMITED`, or `GLOBAL_RATE_LIMITED`
- `retry_after_seconds`
- `retry_after`
- `tenant_id`
- `terminal_id`
- `correlation_id`
