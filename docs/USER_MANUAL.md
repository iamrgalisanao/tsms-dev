# TSMS User Manual

Friendly guide for POS terminal providers, operations staff, and support users.

## 1. What Is TSMS?
TSMS collects sales transactions from POS terminals, checks them, queues them, and (optionally) forwards them to another system. You talk to it using HTTPS API calls with a Bearer token.

## 2. What You Can Do
- Submit a single transaction
- Submit multiple (batch)
- Use official structured format (with submission_uuid)
- Check transaction status
- Void a previous transaction
- Send a heartbeat (keep-alive)
- Manage terminal tokens (regenerate / revoke)
- Inspect (introspect) a token

## 3. Key Words (Plain)
| Term | Simple Meaning |
|------|----------------|
| Terminal | Your POS device record |
| Token | Secure access pass for API calls |
| Transaction | One sale you send |
| Batch | Several sales in one request |
| Official Format | Enhanced format with summary + checksum |
| Void | Cancel a transaction after sending |
| Heartbeat | "I'm alive" ping from terminal |
| Callback | TSMS notifying your system |

## 4. Authentication Flow
1. POST `/api/v1/auth/terminal` with your terminal identifiers.
2. Receive a token like `1|abcdef...` plus abilities.
3. Add header to every request: `Authorization: Bearer 1|abcdef...`.

## 5. Main API Endpoints
| Purpose | Endpoint | Method | Ability |
|---------|----------|--------|---------|
| Single submit | `/api/v1/transactions` | POST | transaction:create |
| Batch submit | `/api/v1/transactions/batch` | POST | transaction:create |
| Official submit | `/api/v1/transactions/official` | POST | transaction:create |
| Status check | `/api/v1/transactions/{id}/status` | GET | transaction:read |
| Void | `/api/v1/transactions/{id}/void` | POST | transaction:create |
| Heartbeat | `/api/v1/heartbeat` | POST | heartbeat:send |
| Token introspection | `/api/v1/tokens/introspect` | GET | any auth |
| Health | `/api/v1/health` | GET | none |

## 6. Example Single Transaction
Request body:
```
{
  "tenant_id": "123",
  "serial_number": "TERM12345",
  "transaction_id": "TXN-2025-001",
  "hardware_id": "HW-001",
  "transaction_timestamp": "2025-08-11T10:00:00Z",
  "base_amount": 1500.00
}
```
Typical success:
```
{
  "success": true,
  "data": { "transaction_id": "TXN-2025-001", "status": "queued" }
}
```

## 7. Official Format Essentials
Add:
- `submission_uuid`
- `submission_timestamp`
- `transaction_count`
- `payload_checksum` (SHA-256 of full JSON)

## 8. Voiding
Send POST with:
```
{
  "transaction_id": "TXN-2025-001",
  "void_reason": "Customer request",
  "payload_checksum": "hash"
}
```
If already voided you receive code `already_voided`.

## 9. Error Codes (Readable)
| Code | Meaning |
|------|---------|
| invalid_token | Token missing / expired / wrong |
| schema_validation_failed | JSON shape incorrect |
| checksum_failed | Hash mismatch |
| terminal_not_found | Unknown terminal |
| terminal_inactive | Terminal disabled |
| idempotency_key_payload_mismatch | Same key, different body |
| already_voided | Void done already |
| rate_limited | Too many requests |

## 10. Good Habits
- Retry safely: reuse idempotency key only with identical body.
- Keep logs of your `transaction_id` and `submission_uuid`.
- Use `/api/v1/tokens/introspect` to verify an access token.
- Respect `Retry-After` when rate limited.

## 11. When To Ask For Help
- Constant `invalid_token` right after re-auth.
- Many `checksum_failed` (likely hash calculation error).
- Transactions stuck in `queued` unusually long.

## 12. Dashboard Labels (Reference)
Terminal Tokens table columns:
- Terminal ID | Token | Status | Expires | Last Used | Guard | Actions
Actions include: copy token, revoke, regenerate.

## 13. Quick Troubleshooting Table
| Symptom | Likely Cause | Action |
|---------|--------------|--------|
| 401 invalid_token | Wrong / truncated token | Re-authenticate |
| 422 checksum_failed | Hash not over exact JSON | Recompute after final serialization |
| 409 idempotency_key_payload_mismatch | Body changed with same key | Use new key or revert body |
| Slow status updates | Queue backlog | Monitor queue / contact ops |

## 14. Support Data To Provide
- transaction_id(s)
- timestamps (UTC)
- your terminal serial_number
- sample request + response (redact secrets)

## 15. Safety Reminders
- Never log full token after first display.
- Protect callback endpoint (HTTPS, auth if possible).
- Validate all responses before assuming success.
