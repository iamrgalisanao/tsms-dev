# TSMS POS Integration Guidelines (V2.0)

## 1. Objective
This document provides the definitive technical standards for POS providers to integrate with the Tenant Sales Management System (TSMS). Adherence to these standards ensures data integrity, security, and successful automated ingestion of sales data.

---

## 2. API Connectivity
| Aspect | Specification |
| :--- | :--- |
| **Endpoint** | `POST https://<domain>/api/v1/transactions/official` |
| **Content-Type** | `application/json` |
| **Authentication** | `Authorization: Bearer <JWT_TOKEN>` |

---

## 3. Standard Payload Structure
The TSMS API expects a hierarchical JSON submission containing a single transaction or a batch.

### Sample Structure (Single Transaction)
```json
{
  "submission_uuid": "0cb8dd21-57af-4a74-bdf6-8f566c82933d",
  "tenant_id": 40,
  "terminal_id": 55,
  "submission_timestamp": "2026-03-16T13:14:17.681Z",
  "transaction_count": 1,
  "payload_checksum": "2f33a932147701a186aa8e2457821159df47a0ec0418f88a8dbd27b0caee3722",
  "transaction": {
    "transaction_id": "e5ffbee7-2270-425d-95a2-f0f5d099fb11",
    "hardware_id": "8600025",
    "transaction_timestamp": "2025-11-05T08:41:23Z",
    "gross_sales": 1499.00,
    "net_sales": 1499.00,
    "promo_status": "WITH_APPROVAL",
    "customer_code": "C-F1001",
    "payload_checksum": "6474cfea15ecbcc0c4169798477e2f852aff32a165923de2ac7125ed9c4fd4dc",
    "adjustments": [
      { "adjustment_type": "promo_discount", "amount": 0.00 },
      { "adjustment_type": "senior_discount", "amount": 0.00 },
      { "adjustment_type": "pwd_discount", "amount": 0.00 },
      { "adjustment_type": "vip_card_discount", "amount": 0.00 },
      { "adjustment_type": "service_charge_distributed_to_employees", "amount": 0.00 },
      { "adjustment_type": "service_charge_retained_by_management", "amount": 0.00 },
      { "adjustment_type": "employee_discount", "amount": 0.00 }
    ],
    "taxes": [
      { "tax_type": "VAT", "amount": 160.61 },
      { "tax_type": "VATABLE_SALES", "amount": 1338.39 },
      { "tax_type": "SC_VAT_EXEMPT_SALES", "amount": 0.00 },
      { "tax_type": "OTHER_TAX", "amount": 0.00 }
    ]
  }
}
```

---

## 4. Integrity Verification (Checksums)
TSMS uses a **dual-layer SHA-256** strategy. The `PayloadChecksumService` is the absolute source of truth.

### 4.1 Canonicalization Rules
Before hashing, data MUST be transformed:
1.  **Recursive Key Sorting**: Sort all associative array keys alphabetically (`ksort`).
2.  **Monetary Casting**: Cast `gross_sales`, `net_sales`, and `amount` to **floats**.
3.  **JSON Normalization**: Trailing zeros in whole number floats must be omitted (e.g., `1499.00` becomes `1499`).

### 4.2 Hashing Process
- **Step 1 (Transaction)**: Canonicalize the `transaction` object (exclude `payload_checksum`) -> JSON Encode -> SHA-256 -> Assign to `transaction.payload_checksum`.
- **Step 2 (Submission)**: Canonicalize the entire payload (exclude top-level `payload_checksum`) -> JSON Encode -> SHA-256 -> Assign to top-level `payload_checksum`.
- **JSON Flags**: Use `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.

---

## 5. Error Handling & Troubleshooting
POS providers should implement logic to handle the following common scenarios:

### 5.1 Response Codes & Actions
| HTTP Code | Error Message | POS Action |
| :--- | :--- | :--- |
| **201 / 200** | Accepted / Created | Success. Move to archived logs. |
| **400** | Invalid Checksum | **Stop Submission**. Check canonicalization logic and data types (int vs string). |
| **401** | Unauthorized | **Refresh JWT Token**. Tokens expire every 24 hours. |
| **409** | Duplicate Transaction | **Log & Skip**. The `transaction_id` has already been ingested. |
| **422** | Validation Failed | **Investigate Payload**. Required fields (e.g. `hardware_id`) are missing or malformed. |
| **500 / 502** | Gateway/Server Error | **Retry with Exponential Backoff**. Queue the payload and retry later. |

---

## 6. FAQ (Common Integration Issues)

### Q: Why do I get "Invalid payload_checksum for transaction" even though my calculation is correct?
**A:** This is usually due to numeric formatting. Ensure that `1499.00` is represented as `1499` in your hashed JSON string. Also, check if `hardware_id` is inside the `transaction` object.

### Q: Why do I get "Invalid submission payload_checksum" but the transaction one passes?
**A:** The submission hash includes the *hashed transaction string*. If you recalculated the transaction hash but forgot to update the `transaction.payload_checksum` field inside the submission before hashing the submission, the hashes will mismatch.

### Q: My numbers are integers, but the server says I should use floats.
**A:** The server casts monetary fields to floats. To match this, ensure your hashing service treats `gross_sales`, `net_sales`, and `amount` as float types during canonicalization.

### Q: What should I do if the server is down?
**A:** POS systems must maintain a persistent **Retry Queue**. Store the failed payloads and implement an exponential backoff (2s, 4s, 8s...) until connectivity is restored. Do NOT discard the payloads.

---

## 7. Field Type Reference
*   `tenant_id`: **Integer**
*   `terminal_id`: **Integer**
*   `gross_sales`, `net_sales`, `amount`: **Float**
*   `transaction_id`, `submission_uuid`: **String (UUID v4)**
*   `transaction_timestamp`: **String (ISO-8601)**
