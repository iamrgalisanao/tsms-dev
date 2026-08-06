# Contract: Ingestion API Resilience Behavior

## Official Ingestion Accepted

**Endpoint**: `POST /api/v1/transactions/official`

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

**Status**: `429 Too Many Requests` or configured rejection status.

**Headers**:

- `Retry-After`: Positive integer seconds; must match body retry seconds.

**Response fields**:

- `success`: false
- `error_code`: `INGESTION_BACKPRESSURE`
- `message`
- `retry_after_seconds`
- `retry_after`
- `correlation_id`
- `backpressure.queue`
- `backpressure.queue_type`
- `backpressure.queue_depth`
- `backpressure.threshold`
- `backpressure.mode`
- `backpressure.reason`

## Official Ingestion Dependency Degraded

**Status**: `503 Service Unavailable`

**When**:

- Backpressure health cannot be evaluated in enforce mode.
- Redis/circuit breaker dependency is unavailable and no bounded safe fallback exists.

**Response fields**:

- `success`: false
- `error_code`: `INGESTION_DEGRADED`
- `message`
- `retry_after_seconds`
- `retry_after`
- `correlation_id`
- `reason`

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

**Response fields**:

- `success`: false
- `error_code`: `IDEMPOTENCY_CONFLICT`
- `submission_uuid`
- `correlation_id`

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
