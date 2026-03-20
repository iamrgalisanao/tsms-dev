# TSMS Payload Checksum Specification (V2)

This document serves as the authoritative technical reference for computing and validating payload checksums for the Tenant Sales Management System (TSMS). It is derived directly from the `PayloadChecksumService` implementation.

## 1. The Multi-Layer Checksum Architecture

TSMS uses a hierarchical SHA-256 hashing strategy:

1.  **Transaction Level**: A checksum of the `transaction` object (excluding its `payload_checksum`).
2.  **Submission Level**: A checksum of the entire `submission` payload (including the transaction's hash, but excluding the submission's top-level `payload_checksum`).

> [!IMPORTANT]
> Because the submission-level hash includes the transaction-level hash string, an error at the transaction layer will invalidate the entire submission.

## 2. Canonicalization (Source of Truth)

Before hashing, data must be canonicalized to ensure that variations in JSON formatting or field order do not affect the result.

### 2.1 Recursive Key Sorting
*   Every **associative array** (object) must be sorted by its keys in ascending alphabetical order (`ksort` in PHP).
*   **Indexed arrays** (lists) must preserve their original order.

### 2.2 Monetary Field Normalization
To ensure consistency across systems with different floating-point precision, the following keys must be explicitly cast to **floats**:
*   `gross_sales`
*   `net_sales`
*   `amount`

### 2.3 Recursive Processing
The sorting and casting rules must be applied recursively to all nested levels of the payload.

## 3. Hashing Specification

The final hash is computed using the following algorithm:

1.  **JSON Serialization**: The canonicalized data is converted to a JSON string using specific flags:
    *   **Unescaped Slashes**: Do not escape `/` as `\/`.
    *   **Unescaped Unicode**: Do not escape multibyte characters as `\uXXXX`.
    *   **PHP/Standard Normalization**: Trailing zeros in whole number floats must be omitted (e.g., `1499.0` becomes `1499`).
2.  **Algorithim**: **SHA-256**.
3.  **Format**: Lowercase hexadecimal string.

### PHP Implementation Reference
```php
$jsonString = json_encode($canonicalData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$checksum = hash('sha256', $jsonString);
```

## 4. Implementation Checklist for POS Providers
- [ ] Use a UUID v4 for `submission_uuid` and `transaction_id`.
- [ ] Ensure `tenant_id` and `terminal_id` are passed as **integers**, not strings.
- [ ] Ensure `hardware_id` is placed inside the `transaction` object.
- [ ] Implement recursive `ksort` for all objects.
- [ ] Cast `gross_sales`, `net_sales`, and `amount` to floats before encoding.
- [ ] Use `JSON_UNESCAPED_SLASHES` during encoding.
- [ ] Compute Transaction Hash -> Attach to Transaction -> Compute Submission Hash -> Attach to Submission.
