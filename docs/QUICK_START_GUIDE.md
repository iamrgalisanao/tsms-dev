# TSMS Quick Start Guide

Goal: Send your first valid transaction fast.

## 1. Prerequisites
- Terminal credentials (tenant_id + serial_number)
- Base URL
- Ability to run curl / Postman

## 2. Authenticate
`POST /api/v1/auth/terminal`
Response contains `token` like `1|abcdef...`.
Add header to all future calls:
`Authorization: Bearer 1|abcdef...`

## 3. Send Single Transaction
```
POST /api/v1/transactions
{
  "tenant_id": "123",
  "serial_number": "TERM12345",
  "transaction_id": "TEST-001",
  "hardware_id": "HW001",
  "transaction_timestamp": "2025-08-11T10:00:00Z",
  "base_amount": 500.00
}
```
Expect: `success: true`, `status: queued`.

## 4. Check Status
`GET /api/v1/transactions/{id}/status`
Look for lifecycle: queued → processing → VALID / FAILED.

## 5. Void (Optional)
```
POST /api/v1/transactions/{id}/void
{
  "transaction_id": "TEST-001",
  "void_reason": "Customer cancelled",
  "payload_checksum": "sha256-hash"
}
```

## 6. Batch & Official Format
Scale up only after single works. Compute checksum over exact final JSON.

## 7. Verify Token Active
`GET /api/v1/tokens/introspect` → `active: true` expected.

## 8. Common Errors
| Code | Fix |
|------|-----|
| invalid_token | Re-authenticate |
| checksum_failed | Recompute hash |
| terminal_inactive | Reactivate terminal |
| rate_limited | Back off per Retry-After |

## 9. Success Checklist
- [ ] Auth works
- [ ] Single transaction accepted
- [ ] Status transitions observed
- [ ] Void tested (optional)
- [ ] No recurring 4xx errors

## 10. Next Steps
- Implement idempotent retry logic
- Add heartbeat (`POST /api/v1/heartbeat`)
- Enable callback handling
