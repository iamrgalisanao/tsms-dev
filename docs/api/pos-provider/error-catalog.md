# TSMS POS Provider Error Catalog

This catalog documents provider-visible validation, authorization, and support lookup errors for TSMS API testing.

## Authentication And Authorization

| HTTP | Code / Message | Meaning | Provider Action |
| --- | --- | --- | --- |
| 401 | `Unauthenticated.` | Bearer token is missing, malformed, expired, or revoked. | Confirm `Authorization: Bearer <token>` and request a fresh token if needed. |
| 403 | `Invalid ability provided.` | Token is valid but lacks the required Sanctum ability. | Use a provider testing token with the documented abilities. |
| 403 | `TERMINAL_TOKEN_MISMATCH` | Terminal identity mismatch: payload `terminal_id` does not match the token's terminal identity. | Ensure the payload `terminal_id` exactly matches the ID of the terminal authenticated by the bearer token. |

## Submission Lookup

| HTTP | Code | Meaning | Provider Action |
| --- | --- | --- | --- |
| 404 | `SUBMISSION_NOT_FOUND` | Submission UUID does not exist or is outside the token's tenant/terminal scope. | Confirm the UUID, tenant, terminal, and token used for lookup. |
| 422 | `INVALID_SUBMISSION_UUID` | Path value is not a valid UUID. | Send the exact `submission_uuid` generated for the original submission. |

## Submission Ingestion & Rejections

These validation and conflict errors can be returned synchronously when posting to `/api/v1/transactions/official`.

| HTTP | Code | Meaning | Provider Action |
| --- | --- | --- | --- |
| 409 | `SUBMISSION_UUID_CONFLICT` | Submission UUID already exists in the database but with a different payload checksum. | Correct payload fields or generate a new unique `submission_uuid` before resending. |
| 422 | (Validation Error) | Request-level validation failed (returned with `structure_hint`). | Inspect request `errors` for missing fields. Ensure payload structure matches schema. |
| 422 | `STRUCTURAL_VALIDATION_FAILURE` | Payload failed basic schema or format validation requirements. | Inspect request `errors` detail for missing fields or incorrect types. |
| 422 | `CRYPTOGRAPHIC_INTEGRITY_FAILURE` | Cryptographic SHA-256 signature verification failed. | Re-calculate both transaction and submission checksums based on the exact V2.1 canonical sorting algorithm. |
| 422 | `SUBMISSION_ALREADY_REJECTED` | Re-submitting a UUID that was previously rejected. | Fix payload issues, generate a fresh `submission_uuid`, and submit. |
| 429 | (Rate Limit) | Ingestion queue depth exceeded backpressure thresholds. | Implement retry logic with exponential backoff. |
| 503 | (Service Unavailable) | Storage database or worker persistence layer is currently unavailable. | Retry request after a short delay. |

## Transaction Adjustments (Voids & Refunds)

These errors are returned during transaction void/refund operations.

### Void Endpoint (`POST /transactions/{transaction}/void`)

| HTTP | Code / Message | Meaning | Provider Action |
| --- | --- | --- | --- |
| 409 | `Transaction already voided` | The transaction has already been marked as void. | No action required. Verify void status or timestamp if needed. |
| 409 | `Cannot void transaction currently being processed` | The transaction is in a `PROCESSING` state and cannot be modified. | Retry the void operation after transaction processing has completed. |
| 422 | `Transaction ID mismatch` | The UUID in the path does not match the `transaction_id` in the request body. | Ensure both IDs match. |
| 422 | `Invalid payload checksum` | Cryptographic checksum validation failed. | Compute the void checksum using `transaction_id` and `void_reason`. |
| 500 | `Failed to void transaction` | Internal database or logging failure during voiding. | Contact support or retry after some time. |

### Refund Endpoint (`POST /transactions/{transaction}/refund`)

| HTTP | Code / Message | Meaning | Provider Action |
| --- | --- | --- | --- |
| 400 | `Refund failed` | The refund operation failed due to internal service issues. | Contact support or retry after checking transaction state. |
| 409 | `Refunds are only permitted on the same business day` | The transaction is older than the current business day (strict refunds enabled). | Confirm the transaction date and PITX refund policies. |
| 422 | (Validation Error) | Laravel validation failed for inputs like `refund_amount` or `refund_reason`. | Send a valid `refund_amount` (numeric) and required `refund_reason` fields. |

## Required Payload Fields

| HTTP | Code / Field | Meaning | Provider Action |
| --- | --- | --- | --- |
| 422 | `transaction.receipt_no` | Receipt number is required. | Send a non-empty receipt number and preserve leading zeroes. |
| 422 | `submission_uuid` | Submission UUID is required and must be a UUID. | Generate one UUID per submission and reuse it only for idempotent replay. |
| 422 | `transaction.transaction_id` | Transaction ID is required and must be a UUID. | Generate or send the POS transaction UUID. |
| 422 | `tenant_id` | Tenant ID is required. | Use the tenant ID assigned by PITX/TSMS. |
| 422 | `terminal_id` | Terminal ID is required. | Use the terminal ID assigned by PITX/TSMS. |

## Payload Sandbox Diagnostics

| Code | Meaning | Provider Action |
| --- | --- | --- |
| `INVALID_JSON` | Request body is not parseable JSON. | Send raw JSON with `Content-Type: application/json`. |
| `UNSUPPORTED_CONTENT_TYPE` | Content-Type header is not `application/json`. | Send requests with `Content-Type: application/json` header. |
| `INVALID_UUID_FORMAT` | `transaction.transaction_id` is not a UUID v4. | Generate a UUID for the transaction ID. It does not need to match `submission_uuid`. |
| `HARDWARE_ID_MISSING_IN_TRANSACTION` | `hardware_id` is missing inside `transaction`. | Place `hardware_id` under `transaction`. |
| `HARDWARE_ID_AT_ROOT_ONLY` | `hardware_id` was sent only at root level. | Move or duplicate it into `transaction.hardware_id` according to the contract. |
| `TRANSACTION_CHECKSUM_MISMATCH` | Transaction checksum does not match TSMS canonical JSON. | Use sandbox `include_debug=true` and recompute from canonical transaction JSON. |
| `SUBMISSION_CHECKSUM_MISMATCH` | Root submission checksum does not match TSMS canonical JSON. | Recompute root checksum after fixing transaction-level checksum and fields. |
| `CHECKSUM_CASCADE` | Root checksum mismatch may be caused by transaction checksum mismatch. | Fix transaction checksum first, then recompute root checksum. |
| `VAT_RECONCILIATION_FAILED` | Tax amounts do not reconcile with sales totals. | Review `gross_sales`, `net_sales`, VAT, VATable sales, and exempt sales. |
| `TERMINAL_TENANT_MISMATCH` | Terminal does not belong to the submitted tenant. | Confirm tenant and terminal assignment before live submission. |

## Operational Notes

- `customer_code` should be sent when available in POS data. If unavailable, confirm with PITX whether `null` is acceptable for the specific integration.
- `transaction_timestamp` must be the original POS business transaction timestamp, including during backfill.
- Duplicate `submission_uuid` replay is safe and should return the prior submission state instead of creating a new transaction.
- The Payload Sandbox does not create transactions, dispatch jobs, or affect live ingestion.
