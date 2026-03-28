# TSMS POS Integration Addendum (V2.1 Supplemental)

This document provides critical technical clarifications for POS providers based on common integration hurdles identified during UAT and Pilot phases. Treat this as a mandatory companion to the **TSMS POS Integration Guidelines (V2.1)**.

---

## 1. Checksum Verification: The "Recursive Sorting" Rule
The most common cause of `INVALID_CHECKSUM` (422) is the failure to sort nested object keys alphabetically.

> [!WARNING]
> **Key Order Matters**: Even if the top-level keys are sorted, any objects inside arrays (like `taxes` or `adjustments`) MUST also have their keys sorted alphabetically before being serialized into the hash string.

### Example: Nested Tax Object Sorting
*   **INCORRECT** (Hashed as provided):
    `{"tax_type":"VAT","amount":"12.32"}`
*   **CORRECT** (Alphabetical: `amount` before `tax_type`):
    `{"amount":"12.32","tax_type":"VAT"}`

### Implementation Tip (PHP)
Use a recursive sort function before `json_encode`:
```php
function canonicalize(&$data) {
    if (is_array($data)) {
        ksort($data); // Sort keys at current level
        foreach ($data as &$value) {
            canonicalize($value); // Recurse into children
        }
    }
}
```

---

## 2. Idempotency & Duplicate Prevention
A frequent error is `"insert ignored but no existing transaction found"`.

*   **Cause**: The POS is sending a **Retry** for a previously submitted sale but using a **different `transaction_id`** while keeping the same `receipt_no`. 
*   **The Conflict**: TSMS enforces a unique constraint on the `(terminal_id, receipt_no, transaction_date)` combination. If you change the `transaction_id` but send the same receipt for the same day, the system rejects it as a data conflict.
*   **Solution**: Always reuse the original `transaction_id` (UUID) for any re-submission of the same sale event.

---

## 3. Mandatory Fields: `hardware_id`
*   **Issue**: Payloads missing `hardware_id` fail validation or return `ingest_failed`.
*   **Requirement**: The `hardware_id` (typically the physical Serial Number of the POS terminal) is required inside the `transaction` object for security audits and terminal mapping.

---

## 4. Operational Best Practices
### 4.1 Token Refresh Strategy
*   **Endpoint**: `POST /api/v1/auth/refresh`
*   **Auth**: Requires an active `Authorization: Bearer <TOKEN>` header.
*   **Best Practice**: Refresh your token every 12–23 hours to prevent a `401 Unauthorized` during active sales hours.

### 4.2 Status Polling Delay
TSMS uses a high-performance sharded queue for transaction processing.
*   **Initial Wait**: Poll for status **1-2 seconds** after submission.
*   **Interval**: If still `PENDING`, poll every **2-3 seconds**.
*   **Target**: Most transactions are finalized within 5 seconds of receipt.

---

## 5. Metadata for Troubleshooting
When requesting support, **always provide the `submission_uuid`**. This unique identifier allows TSMS engineers to trace the exact validation path and database events triggered by your payload.
