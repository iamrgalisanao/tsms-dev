# TSMS POS Provider Backfill And Retry Policy

## Idempotency

Each submission must use a stable `submission_uuid`. If a network timeout or HTTP `429` occurs, retry the same payload with the same `submission_uuid`.

Use a new `submission_uuid` only when the payload is intentionally corrected after a validation or checksum error.

## Rate-Limited Retries

When TSMS returns `429 Too Many Requests`:

1. Read the `Retry-After` response header or `retry_after` JSON field.
2. Wait at least that many seconds.
3. Retry the same payload unchanged.
4. Use exponential backoff if repeated `429` responses occur.

## Production Limits

POS ingestion limits are evaluated per authenticated tenant and terminal, not by shared public IP. This avoids one terminal throttling another terminal behind the same network.

Current default POS ingestion profile:

```text
120 requests per terminal per minute
```

The value may be tuned per deployment without changing the API contract.
