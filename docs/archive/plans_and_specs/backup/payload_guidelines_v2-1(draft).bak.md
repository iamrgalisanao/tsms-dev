# TSMS POS Integration Guidelines (V2.1 Draft)

> [!IMPORTANT]
> **PHASE 1 RESTRICTION**: To ensure system stability and mitigate database deadlock issues during peak hours, TSMS currently only accepts **single-transaction submissions**. Batch transaction submissions (using the `transactions` array) are temporarily disabled and reserved for **Phase 2** of the project.

## 1. Document Control

| Item | Value |
| :--- | :--- |
| **Document Title** | TSMS POS Integration Guidelines |
| **Version** | 2.1 Draft |
| **Effective Date** | March 17, 2026 |
| **Applies To** | All POS providers integrating with TSMS |
| **Purpose** | Standard technical guide for secure and valid sales data submission to TSMS |

### Change Summary from V2.0
- **Clarified Structure**: Detailed single vs batch submission structures.
- **Field Matrix**: Added a comprehensive field rules matrix.
- **Reference Values**: Added enum/reference values for all relevant fields.
- **Response Examples**: Included standard API response examples for better integration testing.
- **Enhanced Rules**: Expanded retry, idempotency, and timestamp handling rules.
- **Support Correlation**: Integrated notes on using `submission_uuid` for cross-system troubleshooting.
- **Checksum Clarity**: Improved checksum explanation with a concrete canonicalization example and nested dependency rules.
- **Added Operational Specs**: Included Void, Refund, Status, and Rate Limiting documentation.

---

## 2. Objective

This document defines the official technical standards for POS providers integrating with the Tenant Sales Management System (TSMS). Compliance with these standards is required to ensure:
- Successful API connectivity and secure authentication.
- Consistent payload formatting and checksum integrity validation.
- Duplicate prevention and reliable retry handling.
- Successful automated ingestion of sales data into TSMS.

### 2.1 Transaction Lifecycle Overview
To ensure high-volume performance, TSMS uses a **Synchronous Handshake** followed by an **Asynchronous Completion** model.

1.  **Handshake (POST)**: The POS submits the transaction. The server performs security and integrity checks (Bearer token + Checksums). If valid, the server returns `201 Accepted`.
2.  **Processing (Background)**: The transaction enters a background queue for **Computation Validation** (checking tax math, etc.).
3.  **Completion (Polling)**: The transaction moves to `COMPLETED` (Success) or `FAILED` (Data mismatch). POS systems can verify this final state via the Status endpoint (Section 17.3).

---

## 3. API Connectivity

| Aspect | Specification |
| :--- | :--- |
| **Method** | `POST` |
| **Endpoint** | `https://stagingtsms.pitx.com.test/api/v1/transactions/official` |
| **Content-Type** | `application/json` |
| **Authentication** | `Authorization: Bearer <BEARER_TOKEN>` |

### 3.1 Environment Base URLs
The primary environment for initial integration and UAT is the **Sanctum / Staging** environment.

| Environment | Base URL | Note |
| :--- | :--- | :--- |
| **Sandbox / Staging**| `https://stagingtsms.pitx.com.test` | Primary integration endpoint |
| **Production** | *Available upon request* | For certified providers only |

### 3.2 Authentication Details
- **Type**: Bearer Token (Personal Access Token).
- **Format**: `Bearer <ID>|<HASH>` (e.g., `Bearer 225|J2rKX99g...`).
- **Issuance**: Tokens are provided via the TSMS Administrative Dashboard or assigned during terminal provisioning.
- **Expiration**: Standard tokens expire every 24 hours.
- **Refresh**: POS clients should implement a pro-active "Silent Refresh" before expiration to avoid 401 interruptions.

---

## 4. Submission Model

### 4.1 Deadlock Prevention Strategy (Phase 1)
To ensure system stability during peak hours and prevent database row-locking contention (deadlocks), TSMS enforces a **Single-Transaction-per-Request** rule.

- **Why?**: Processing an array of transactions in a single database transaction can hold locks on multiple rows for an extended period, creating bottlenecks.
- **Rule**: Every API submission must contain exactly **one** transaction.
- **Future**: Batch submissions using the `transactions` array are reserved for **Phase 2** and are currently disabled.

### 4.2 Supported Structures
Currently, only the **Single Transaction Object** format is active.

> [!WARNING]
> **Batch Submissions Disabled**: Submitting an array will result in a `422 Unprocessable Entity` error with code `BATCH_DISABLED`.

---

## 5. Standard Payload Structure

### 5.1 Single Transaction Object Format (Required)
This is the required format for all integrations.

```json
{
  "submission_uuid": "0cb8dd21-57af-4a74-bdf6-8f566c82933d",
  "tenant_id": 40,
  "terminal_id": 55,
  "submission_timestamp": "2026-03-16T13:14:17Z",
  "transaction_count": 1,
  "payload_checksum": "ab56c0e1012f50a5b21ce4aa2dec0ed9060253fea6e02253b8d119ec3f3a442b",
  "transaction": {
    "transaction_id": "e5ffbee7-2270-425d-95a2-f0f5d099fb11",
    "hardware_id": "8600025",
    "receipt_no": "R001-0000456",
    "transaction_timestamp": "2025-11-05T08:41:23Z",
    "gross_sales": "1499.00",
    "net_sales": "1499.00",
    "promo_status": "WITH_APPROVAL",
    "customer_code": "C-F1001",
    "payload_checksum": "5dec908e74e1bd19b988d7d4e1c1a6a04077b081778954594e15fa247f28852c",
    "adjustments": [
      { "adjustment_type": "promo_discount", "amount": "0.00" },
      { "adjustment_type": "senior_discount", "amount": "0.00" },
      { "adjustment_type": "pwd_discount", "amount": "0.00" },
      { "adjustment_type": "vip_card_discount", "amount": "0.00" },
      { "adjustment_type": "service_charge_distributed_to_employees", "amount": "0.00" },
      { "adjustment_type": "service_charge_retained_by_management", "amount": "0.00" },
      { "adjustment_type": "employee_discount", "amount": "0.00" }
    ],
    "taxes": [
      { "tax_type": "VAT", "amount": "160.61" },
      { "tax_type": "VATABLE_SALES", "amount": "1338.39" },
      { "tax_type": "SC_VAT_EXEMPT_SALES", "amount": "0.00" },
      { "tax_type": "OTHER_TAX", "amount": "0.00" }
    ]
  }
}
```

### 5.2 Batch Format (Reserved for Phase 2)
The following format is currently **NOT ACCEPTED** by the TSMS API.
```json
{
  "submission_uuid": "...",
  "transactions": [ ... ]
}
```

### 5.3 Legacy Single-Transaction Format
*Only if explicitly approved by TSMS.*
```json
{
  "submission_uuid": "0cb8dd21-57af-4a74-bdf6-8f566c82933d",
  "tenant_id": 40,
  "terminal_id": 55,
  "submission_timestamp": "2026-03-16T13:14:17Z",
  "transaction_count": 1,
  "payload_checksum": "b3422591e2c36015e8bd24ca6b6df66a03bf0283f98ea15cff1cfda2ebce14f4",
  "transaction": {
    "transaction_id": "e5ffbee7-2270-425d-95a2-f0f5d099fb11",
    "hardware_id": "8600025",
    "receipt_no": "R001-0000456",
    "transaction_timestamp": "2025-11-05T08:41:23Z",
    "gross_sales": "1499.00",
    "net_sales": "1499.00",
    "promo_status": "WITH_APPROVAL",
    "customer_code": "C-F1001",
    "payload_checksum": "5dec908e74e1bd19b988d7d4e1c1a6a04077b081778954594e15fa247f28852c",
    "adjustments": [
      { "adjustment_type": "promo_discount", "amount": "0.00" }
    ],
    "taxes": [
      { "tax_type": "VAT", "amount": "160.61" }
    ]
  }
}
```

> [!IMPORTANT]
> - `transaction_count` must be exactly `1` for all Phase 1 submissions.
> - A mismatch or attempt to send an array will result in a `422 Unprocessable Entity` response.

---

## 6. Field Rules Matrix

### 6.1 Top-Level Submission Fields
| Field | Required | Type | Role / Notes |
| :--- | :--- | :--- | :--- |
| `submission_uuid` | Yes | UUID v4 | **Envelope ID**: Unique per API call attempt. |
| `tenant_id` | Yes | Integer | Provided by TSMS during onboarding. |
| `terminal_id` | Yes | Integer | Registered TSMS terminal identifier. |
| `submission_timestamp` | Yes | ISO-8601 UTC (`YYYY-MM-DDTHH:MM:SSZ`) | Time the API call was initiated. Fractional seconds are not accepted. |
| `transaction_count` | Yes | Integer | **Must be 1** (Enforced for Phase 1). |
| `payload_checksum` | Yes | SHA-256 hex | Integrity hash of the entire submission. |
| `transaction` | Yes | Object | The business record being submitted. |
| `transactions` | No | Array | **Reserved for Phase 2** (Disabled). |

### 6.2 Transaction Fields
| Field | Required | Type | Role / Notes |
| :--- | :--- | :--- | :--- |
| `transaction_id` | Yes | String | **Business ID**: Unique per sale event (UUID).|
| `hardware_id` | Yes | String | **Internal Ingestion Key**: Physical device identifier. Used for audit trails and uniquely identifying POS hardware. Failing to provide this will result in `ingest_failed`. |
| `receipt_no` | Yes | String | **Invoice/Receipt Number**: Human-readable ID from POS. |
| `transaction_timestamp` | Yes | ISO-8601 UTC (`YYYY-MM-DDTHH:MM:SSZ`) | Time of sale completion. Fractional seconds are not accepted. |
| `gross_sales` | Yes | String | Strict 2-decimal format (e.g., `"1499.00"`) |
| `net_sales` | Yes | String | Strict 2-decimal format (e.g., `"1499.00"`) |
| `promo_status` | Yes | String | See Accepted Reference Values |
| `customer_code` | Optional | String | Use `null` if not available |
| `payload_checksum` | Yes | SHA-256 hex | Transaction-level checksum |
| `adjustments` | Yes | Array | Send empty array `[]` if none |
| `taxes` | Yes | Array | Send empty array `[]` if none |

### 6.3 Adjustment & Tax Fields
| Field | Required | Type | Rules / Notes |
| :--- | :--- | :--- | :--- |
| `adjustment_type` / `tax_type`| Yes | String | See Accepted Reference Values |
| `amount` | Yes | String | Strict 2-decimal format (e.g., `"0.00"`) |

### 6.4 Field Constraints (Formatting)
| Field | Restriction | Example Pattern |
| :--- | :--- | :--- |
| `transaction_id` | Alphanumeric, max 64 chars | `TX-2023-A91` |
| `hardware_id` | Alphanumeric/Dashes, max 32 chars | `POS-01-HW` |
| `receipt_no` | Alphanumeric/Dashes, max 128 chars | `R001-00123` |
| `customer_code` | Alphanumeric, max 24 chars | `VIP-10023` |

---

## 7. Accepted Reference Values

### 7.1 Promo Status Values
- `NONE`
- `WITH_APPROVAL`
- `WITHOUT_APPROVAL`

### 7.2 Adjustment Type Values
- `promo_discount`
- `senior_discount`
- `pwd_discount`
- `vip_card_discount`
- `service_charge_distributed_to_employees`
- `service_charge_retained_by_management`
- `employee_discount`

### 7.3 Tax Type Values
- `VAT`
- `VATABLE_SALES`
- `SC_VAT_EXEMPT_SALES`
- `OTHER_TAX`

---

## 8. Timestamp Rules

All timestamps must follow the production TSMS **ISO-8601 UTC** format: `YYYY-MM-DDTHH:MM:SSZ`.
- **Allowed Example**: `2026-03-16T13:14:17Z`.
- **Not Accepted**: fractional seconds / milliseconds such as `2026-03-16T13:14:17.681Z`.
- UTC format with the `Z` suffix is required.
- `transaction_timestamp` may be earlier than `submission_timestamp` (e.g., during offline retries).

---

## 9. Integrity Verification (Checksums)

TSMS uses a dual-layer SHA-256 checksum strategy to ensure the integrity of both individual transactions and the entire submission.

### 9.1 Canonicalization Rules
Data must be transformed before hashing:
1.  **Recursive Key Sorting**: Sort all associative object keys alphabetically ascending.
2.  **Monetary Formatting**: `gross_sales`, `net_sales`, and all `amount` fields must be formatted as **strings with exactly 2 decimal places** (e.g., `1499.00`, `1499.50`).
3.  **JSON Normalization**: In the resulting JSON string, monetary values must be quoted strings (e.g., `"1499.00"`).
4.  **Field Removal**: When "excluding" a field for hashing, the key must be **completely removed/unset** from the object. Do not simply set it to `null` or `""`.
5.  **Encoding Flags**: Use `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.

### 9.2 Hashing Process
- **Step 1 (Transaction)**: Remove `payload_checksum` -> Canonicalize -> JSON encode -> SHA-256 -> Assign to `transaction.payload_checksum`.
- **Step 2 (Submission)**: Remove top-level `payload_checksum` -> **Include the full transaction object containing the hash from Step 1** -> Canonicalize -> JSON encode -> SHA-256 -> Assign to root `payload_checksum`.

### 9.3 Canonicalization Example
**Source Object**:
```json
{
  "gross_sales": "1499.00",
  "hardware_id": "8600025",
  "net_sales": "1499.00"
}
```
**Canonicalized JSON String for Hashing**:
`{"gross_sales":"1499.00","hardware_id":"8600025","net_sales":"1499.00"}`

> [!IMPORTANT]
> To achieve this consistently across all platforms, use a formatter like `sprintf("%.2f", $value)` or `value.toFixed(2)` before canonicalization.

### 9.4 Universal Canonicalization Algorithm
Since different languages (Java, C#, Python, etc.) handle JSON differently, follow this logic to ensure your local hash matches the TSMS server:

```text
FUNCTION Canonicalize(data):
    IF data IS Object (Associative Array / Map):
        1. Sort all keys of 'data' ALPHABETICALLY (ASCII order).
        2. FOR EACH key, value IN sorted_data:
            IF key IS "gross_sales", "net_sales", OR "amount":
                value = StringFormat(value, "%.2f") IF numeric ELSE value
            ELSE:
                value = Canonicalize(value)          // RECURSE
        RETURN sorted_data

    IF data IS Array (Indexed List):
        1. FOR EACH index, value IN data:
            value = Canonicalize(value)              // RECURSE
        RETURN data (Maintain original index order)

    RETURN data (Scalar values: strings, booleans, nulls)

FUNCTION GenerateHash(payload):
    1. Remove "payload_checksum" field from payload.
    2. processed_data = Canonicalize(payload)
    3. json_string = JSON_Encode(processed_data)
       // MUST BE COMPACT: No whitespace (space/tab/newline) between keys or values
       // MUST BE UNESCAPED: "/" stays "/", UTF-8 characters stay as-is (not \uXXXX)
    4. RETURN SHA256_Hex(json_string)
```

#### 9.5 Canonicalization Rules of Thumb
- **Whitespace**: `{"a":1}` is NOT the same as `{"a": 1}`. Always use the "Compact" format (no spaces after colons/commas).
- **Arrays**: Do NOT sort the items inside an array. Only sort the **keys** of an object.
- **Nulls**: Do not skip `null` values if they are present in the source object.
- **Case Sensitivity**: Keys are sorted by ASCII value (Uppercase comes before lowercase).

---

 ## 10. Idempotency & Duplicate Handling

 - `transaction_id` must remain unique for the same sale event.
 - Retries of the same sale **must reuse** the original `transaction_id`.
 - If an ID has already been successfully ingested, the server returns **HTTP 200 OK** with `status: "already_processed"` to confirm receipt without creating a new record.

### 10.1 Retention & Cleanup
- Submissions are held in a "Deduplication Window" for **30 days**. Attempting to resubmit an ID from 6 months ago may result in a new record rather than an idempotent response.

### 10.2 Data Validation & Conflict (409)
An **HTTP 409 Conflict** is returned if a POS sends a `transaction_id` that already exists, but with **different monetary values** or metadata. TSMS will prioritize the *first* ingested record.

---

## 11. Null, Empty, and Optional Values

| Rule | Requirement |
| :--- | :--- |
| **Empty arrays** | Use `[]` |
| **Missing adjustments/taxes**| Send `[]`, do not omit. |
| **Optional strings** | Use `null` (not `""`). |
| **Numeric fields** | Must be numeric; do not send empty strings `""`. |

---

## 12. Standard Error Response & Schema

TSMS provides machine-readable error responses to help POS systems decide whether to **Stop and Fix** or **Retry Later**.

### 12.1 Error JSON Blueprint
All error responses (4xx and 5xx) follow this standard JSON structure:

```json
{
  "status": "error",
  "code": "INVALID_CHECKSUM",
  "message": "The provided transaction payload_checksum is invalid.",
  "errors": {
    "transaction_id": ["The transaction id field is required."]
  }
}
```

### 12.2 Action Matrix
Use the HTTP status code and internal `code` to drive your POS logic.

| HTTP Status | Category | POS Action | Typical Codes |
| :--- | :--- | :--- | :--- |
| **401** | Unauthorized | **Immediate Refresh**: Trigger Token Login flow. | N/A |
| **403** | Forbidden | **Terminal Locked**: Stop all sync; notify Admin. | `TERMINAL_INACTIVE` |
| **409** | Conflict | **Skip & Continue**: ID already exists on server. | `DUPLICATE_TRANSACTION` |
| **422** | Logic Error | **Stop & Fix**: Invalid checksum or missing data. | `INVALID_CHECKSUM`, `VALIDATION_FAILED` |
| **429** | Rate Limited | **Pause & Backoff**: Wait 60s before retrying. | N/A |
| **5xx** | Server Error | **Queue & Retry**: Server may be busy or offline. | `INTERNAL_ERROR` |

### 12.3 Internal Error Code Reference
| Code | Description |
| :--- | :--- |
| `BATCH_DISABLED` | Payload contains `transactions` array (Disabled in Phase 1). |
| `INVALID_CHECKSUM` | Transaction-level hash mismatch (Check rules in Section 9). |
| `SUBMISSION_CHECKSUM_MISMATCH`| Root-level hash mismatch. |
| `VALIDATION_FAILED` | Field-level issues (See `errors` object for details). |

---

## 13. Resiliency Strategy: Retries & Rate Limits

TSMS requires POS providers to implement a robust retry mechanism to handle transient network issues or rate limiting.

### 13.1 Retry & Exponential Backoff
Transient failures (HTTP 5xx or 429) **must** be queued and retried.
- **Attempt 1**: 2 seconds
- **Attempt 2**: 4 seconds
- **Attempt 3**: 8 seconds
- **Attempt 4**: 16 seconds
- **Attempt 5**: 32 seconds

### 13.2 Rate Limiting Compliance (HTTP 429)
The TSMS API enforces the following default limits:
- **Submissions**: 60 requests per minute per terminal.
- **Authentication**: 5 attempts per 15 minutes.

When a `429 Too Many Requests` is received:
1.  **Respect the `Retry-After` header** (seconds to wait).
2.  **Pause Submissions**: Stop all requests for that terminal immediately.
3.  **Backoff**: Use the strategy in 13.1 before attempting to re-sync.

### 13.3 Sequential Submission Rule (FIFO)
To maintain data integrity (especially for voids and refunds), POS terminals **must** submit transactions in the exact order they occurred.
- **Rule**: Do not attempt to sync Transaction B if Transaction A (which occurred earlier) is still failing with a retriable error.
- **Reason**: Voids or Refunds sent before the parent sale is ingested will fail with a `404 Not Found`.

### 13.4 No Payload Mutation
Once a `transaction_id` and its corresponding `payload_checksum` are generated, they are **immutable**.
- **Rule**: Do not modify any business value (amounts, timestamps, items) during retries.
- **Impact**: Changing any value will change the checksum, causing the server to reject the retry as a signature mismatch or a data conflict (409).

### 13.5 Technical Best Practices
- **TCP Connection Timeout**: 30 seconds.
- **Protocol**: HTTPS (TLS 1.2 or higher required).
- **User-Agent Header**: Include your software name (e.g., `User-Agent: SuperPOS/5.2.1`).

---

## 14. Standard API Response Formats

### 14.1 Success example
```json
{
  "success": true,
  "code": "ACCEPTED",
  "message": "Transaction submission accepted",
  "submission_uuid": "0cb8dd21-57af-4a74-bdf6-8f566c82933d",
  "transaction_count": 1
}
```

### 14.2 Validation Failure Example
```json
{
  "success": false,
  "code": "VALIDATION_FAILED",
  "message": "Payload validation failed",
  "errors": {
    "transaction.hardware_id": ["The hardware_id field is required."]
  }
}
```

---

## 15. FAQ

**Q: Why do I get “Invalid payload_checksum” even with correct logic?**
Verify three things:
1. **Monetary Formatting**: Is `gross_sales` exactly `"1499.00"` (with quotes)?
2. **Whitespace**: Does your JSON string contain any spaces after `:` or `,`? It must be compact.
3. **Escaping**: Are your forward slashes escaped (e.g., `\/`)? They must NOT be escaped (`/`).

**Q: Does key sorting apply to items inside an array?**
No. Only the **keys** of a JSON object/map are sorted. The order of items inside an array (like in `adjustments` or `taxes`) must be preserved exactly as they were created.

**Q: How do I handle empty optional fields?**
If a field is optional and has no value, it is best to **remove the key entirely** from the object before hashing. Sending `"optional_field": null` is technically valid but must be included in the hash if present.

**Q: Can I omit adjustments if there are none?**
No. You must send an empty array `"adjustments": []`.

**Q: What is the rate limit for submissions?**
The default limit is 60 requests per minute. Exceeding this will return a 429 error.

---

## 16. Implementation Checklist

- [ ] Bearer Token (Personal Access Token) authentication is functioning.
- [ ] Terminal and Tenant IDs match registration.
- [ ] Payload uses the `transaction` object structure (not `transactions` array).
- [ ] `transaction_count` is set to `1`.
- [ ] Checksum logic implements recursive `ksort` and 2-decimal string formatting.
- [ ] Whole number precision tested (e.g., `1500` -> `"1500.00"`).
- [ ] Monetary fields are sent as Strings with 2 decimals in the hash object.
- [ ] Persistent retry queue is in place.
- [ ] Timestamps are ISO-8601 compliant.

---

## 17. Void & Refund Operations

POS providers may need to cancel or refund transactions after they have been successfully ingested into TSMS. These operations are performed via dedicated endpoints and require **Bearer Token Authentication**.

### 17.1 Void Transaction
A **Void** is used to cancel a transaction typically before the business day is closed.

| Aspect | Specification |
| :--- | :--- |
| **Method** | `POST` |
| **Endpoint** | `/api/v1/transactions/{transaction_id}/void` |
| **Logic ID** | Must use the string `transaction_id` (UUID) from the original payload. |

**Request Payload**:
```json
{
  "transaction_id": "e5ffbee7-2270-425d-95a2-f0f5d099fb11",
  "void_reason": "Customer changed mind",
  "payload_checksum": "..."
}
```

**Void Checksum Calculation**:
SHA-256 hash of the canonicalized JSON object containing `transaction_id` and `void_reason`.
*   Example: `hash('sha256', '{"transaction_id":"...","void_reason":"..."}')`

**Rules**:
- The transaction must belong to the authenticated terminal.
- Transactions currently in `PROCESSING` status cannot be voided.
- Already voided transactions will return a `409 Conflict`.

### 17.2 Refund Transaction
A **Refund** is used to return funds for a previously completed transaction.

| Aspect | Specification |
| :--- | :--- |
| **Method** | `POST` |
| **Endpoint** | `/api/v1/transactions/{transaction_id}/refund` |
| **Note** | Must use the string `transaction_id` (UUID) from the original payload. |

#### 17.2.1 Sample Refund Request
```json
{
  "refund_amount": "1499.00",
  "refund_reason": "Customer returned item",
  "refund_reference": "<original_transaction_id>"
}
```

| Field | Type | Description |
| :--- | :--- | :--- |
| `refund_amount` | `string` | The total amount to refund. Must be $\le$ `net_sales`. |
| `refund_reason` | `string` | The reason for the refund (max 1000 chars). |
| `refund_reference` | `string` | **Required**: Must match the original `transaction_id`. |

**Rules**:
- **Single Refund Event**: Only one refund is permitted per `transaction_id`. Subsequent attempts will return a `409 Conflict`.
- **Amount Cap**: `refund_amount` cannot exceed the original `net_sales`.
- **Same-Day Rule**: Refunds may be restricted to the same business day depending on system configuration (Check with TSMS Admin).
- **Ownership**: Only the terminal that submitted the original transaction can initiate a refund.

#### 17.2.2 Sample Refund Response
```json
{
  "status": "success",
  "message": "Refund processed successfully",
  "data": {
    "transaction_id": "e5ffbee7-2270-425d-95a2-f0f5d099fb11",
    "is_refunded": true,
    "refund_amount": "1499.00",
    "refund_reference": "e5ffbee7-2270-425d-95a2-f0f5d099fb11"
  }
}
```

### 17.3 Transaction Status
Use this to check the processing results of a previously submitted transaction.

| Aspect | Specification |
| :--- | :--- |
| **Method** | `GET` |
| **Endpoint** | `/api/v1/transactions/{transaction_id}/status` |

**Response Example**:
```json
{
  "success": true,
  "data": {
    "transaction_id": "e5ffbee7-2270-425d-95a2-f0f5d099fb11",
    "validation_status": "VALID",
    "job_status": "COMPLETED",
    "is_voided": false,
    "is_refunded": true,
    "refund_status": "REFUNDED"
  }
}
```

### 17.4 Transaction Status Mapping
| `VALID` | `COMPLETED` | Success | No further action. |
| `INVALID` | `FAILED` | Data Error | **Stop & Fix**: Correct payload logic. |
| `VALID` | `QUEUED` | Queued | Wait 5 seconds and poll again. |
| `VALID` | `PROCESSING`| Active | Wait 5 seconds and poll again. |
| `FAILED` | `ingest_failed` | Validation/DB Error | **Fix**: Check for missing `hardware_id`. |
| `FAILED` | `inserted but not found` | Critical | **Support**: Contact TSMS Admin. |

> [!NOTE]
> **Ownership Enforcement**: Status checks are restricted to the terminal that originally submitted the transaction. Querying a `transaction_id` from another terminal will return a `403 Forbidden` or `404 Not Found`.

---

## 18. Format Comparison
The following examples illustrate the transition from the legacy multi-format support to the **Phase 1 Single-Transaction** requirement.

#### [OLD] Batch Ingestion (DEPRECATED for Phase 1)
Previously, multiple transactions could be sent in a single array. This is now disabled to prevent database deadlocks.

```json
{
  "submission_uuid": "...",
  "transactions": [
    { "transaction_id": "TX-001", ... },
    { "transaction_id": "TX-002", ... }
  ],
  "payload_checksum": "..."
}
```

#### [NEW] Single Ingestion (REQUIRED)
All submissions must now use the single `transaction` object format.

```json
{
  "submission_uuid": "e5ffbee7-2270-425d-95a2-f0f5d099fb11",
  "tenant_id": 40,
  "terminal_id": 55,
  "submission_timestamp": "2026-03-16T13:14:17Z",
  "transaction_count": 1,
  "payload_checksum": "...",
  "transaction": {
    "transaction_id": "TX-2023-9991",
    "hardware_id": "POS-TERM-01",
    "receipt_no": "R-101-0005",
    "transaction_timestamp": "2025-11-05T08:41:23Z",
    "gross_sales": "1499.00",
    "net_sales": "1338.39",
    "adjustments": [],
    "taxes": [],
    "payload_checksum": "..."
  }
}
```

> [!IMPORTANT]
> Note the shift from the `"transactions"` array to the `"transaction"` object. Sending an array will result in a **422 Unprocessable Entity** error with code `BATCH_DISABLED`.

---

## 19. Support & Troubleshooting

When contacting TSMS support regarding a failed submission, please provide the **`submission_uuid`**. This UUID is recorded in the server-side `submission_events` logs and is the fastest way for support to identify the exact cause of a rejection.
