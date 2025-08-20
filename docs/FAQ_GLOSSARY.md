# TSMS FAQ & Glossary

## FAQ

### 1. Why am I getting `invalid_token`?
Token expired, revoked, malformed, or belongs to inactive terminal. Re-authenticate.

### 2. What does `checksum_failed` mean?
The SHA-256 hash you sent doesn’t match the actual JSON body. Recompute over the exact final payload.

### 3. I retried but got `idempotency_key_payload_mismatch`.
You reused the same idempotency key with a different JSON body. Keep body identical or change key.

### 4. How do I know my token is valid?
Call `GET /api/v1/tokens/introspect`. Expect `success: true` and `active: true`.

### 5. When should I poll vs use callbacks?
Use callbacks if you can host a stable HTTPS endpoint. Poll only if you can’t reliably receive webhooks.

### 6. What happens if the queue is busy?
Status may remain `queued` longer. System will still process in order. Contact ops if delay is excessive.

### 7. Can I send the same transaction twice?
Only safely if exactly identical + same idempotency key (for retry). Different content = new key.

### 8. What is the safest order of rollout?
Single → Official format → Batch → Callbacks → Heartbeat automation.

### 9. How do I void?
POST `/api/v1/transactions/{id}/void` with `void_reason` + checksum.

### 10. What if I see many `terminal_inactive` errors?
Terminal probably revoked or deactivated. Regenerate or reactivate in management UI.

## Glossary
| Term | Definition |
|------|------------|
| Ability | Permission inside a token (e.g. transaction:create) |
| Active Token | Token not expired/revoked and terminal active |
| Batch | Multiple transactions in one request |
| Callback | Outbound POST from TSMS with result |
| Checksum | SHA-256 hash verifying payload integrity |
| Correlation ID | Request tracking header echoed in logs |
| Heartbeat | Keep-alive POST confirming terminal health |
| Idempotency Key | Unique key to safely retry same request |
| Introspection | Endpoint to validate token status |
| Official Format | Enhanced structured transaction submission |
| Queue | Background job system (Redis + Horizon) |
| Retry History | Record of job attempts and failures |
| Void | Post-send cancellation of a transaction |

## Quick Error Cheat Sheet
| Code | Action |
|------|--------|
| invalid_token | Re-authenticate |
| checksum_failed | Recompute hash |
| schema_validation_failed | Fix JSON fields |
| terminal_inactive | Reactivate terminal |
| already_voided | Stop repeat void |
| rate_limited | Back off per header |

## Support Data To Include
- transaction_id
- timestamp (UTC)
- terminal serial_number
- copy of request (no secrets) & response

---
For more depth see USER_MANUAL.md and HANDOVER_DOCUMENT.md.
