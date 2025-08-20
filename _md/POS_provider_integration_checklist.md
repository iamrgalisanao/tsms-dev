# TSMS API Integration Checklist for POS Providers

This checklist outlines all the tests and requirements a POS provider must pass for successful integration with TSMS, using API authentication.

## 1. Authentication & Security
- Authenticate using API key/token via HTTPS (TLS 1.2+)
- Handle token expiry and refresh logic

## 2. Payload Format
- Send valid JSON payloads matching TSMS schema
- Support plain text format if JSON is not possible

## 3. Required Fields
- Include all mandatory fields: tenant_id, hardware_id, transaction_id, transaction_timestamp, sales, discounts, charges, VAT, payload_checksum, validation_status, error_code

## 4. API Communication
- POST transactions to `/api/v1/transactions` with correct headers
- Use API authentication (e.g., `Authorization: Api-Key <API_KEY>`)
- Handle all HTTP response codes (200, 400, 401, 409, 500) appropriately

## 5. Validation & Retry Logic
- Implement local queuing and retry logic for failed transactions
- Use exponential backoff for retries (2s, 4s, 8s, 16s, 32s)
- Ensure FIFO order and persistence of retry queue
- Retain original payload_checksum for retries
- Retry all queued transactions within 48 hours

## 6. Duplicate Handling
- Detect and handle duplicate transaction_id (409 response)

## 7. Webhook/Notification Handling
- Respond to TSMS webhook notifications with HTTP 200
- Retry webhook acknowledgement up to 3 times if needed
- Alternatively, support polling for transaction status updates

## 8. Health Check
- Call `/api/v1/healthcheck` to verify server availability

## 9. Audit & Logging
- Log all integration attempts, errors, and responses for audit trail

## 10. Edge Cases
- Handle offline scenarios and resume transmission when connectivity is restored
- Flag transactions for manual intervention if not sent within 48 hours

---

**Note:** Replace any JWT authentication with API key authentication for all requests.
