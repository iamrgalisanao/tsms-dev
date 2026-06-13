# TSMS POS Provider Error Catalog

## Rate Limiting

### HTTP 429 Too Many Requests

POS ingestion, status, auth, and heartbeat endpoints are protected by production rate limits.

Example response:

```json
{
  "success": false,
  "message": "Too many requests.",
  "retry_after": 42
}
```

Expected headers:

```http
Retry-After: 42
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1781348292
```

Provider action:

- Wait for the `Retry-After` duration before retrying.
- Do not generate a new `submission_uuid` for the same payload solely because of a `429`.
- Retry the identical payload with the same `submission_uuid` to preserve idempotency.

## Common Responses

| HTTP | Meaning | Provider Action |
| --- | --- | --- |
| 401 | Missing, invalid, or expired token | Re-authenticate or request a valid terminal token. |
| 403 | Token lacks ability, terminal mismatch, or inactive terminal | Check terminal token ownership and status. |
| 409 | Same submission UUID with different payload details | Use a new `submission_uuid` only after correcting the payload. |
| 422 | Validation or checksum failure | Correct the payload or checksum before retrying. |
| 429 | Rate limit exceeded | Back off using `Retry-After`; retry the same payload. |
