# TSMS Payload Guidelines: Feedback and Improvement Suggestions

> [!NOTE]
> This document has been superseded by the **[PAYLOAD_CHECKSUM_SPEC_V2.md](file:///Users/teamsolo/Projects/PITX/tsms-dev/docs/PAYLOAD_CHECKSUM_SPEC_V2.md)**, which serves as the authoritative technical reference derived directly from the system's `PayloadChecksumService`.

This document summarizes the identified gaps and proposed improvements for the TSMS Integration and Checksum Guidelines based on real-world integration issues.

## 1. Critical Gaps & Mismatches

### Field Mapping Inconsistencies
*   **Adjustment Structure**: The `TSMS-Integration-Guidelines-V1.0.md` (v1.0) defines discounts as an object (`discount_details`). However, the system currently expects an `adjustments` array of objects (e.g., `{"adjustment_type": "promo_discount", "amount": 0.00}`).
*   **Hardware ID Location**: The guides place `hardware_id` at the top level of the submission. However, the `PayloadChecksumService` requires it inside the `transaction` object for valid checksum computation.
*   **Data Types**: Both `tenant_id` and `terminal_id` are sometimes represented as strings in documentation but must be passed as **integers** to avoid checksum mismatches.

### Structural Discrepancies
*   **Submission Wrapper**: The guidelines describe a flat transaction payload. The actual `/api/v1/transactions/official` endpoint requires a hierarchical structure:
    ```json
    {
      "submission_uuid": "...",
      "tenant_id": 40,
      "transaction": { ... }
    }
    ```
*   **Missing Fields**: Top-level coordination fields like `submission_uuid` and `submission_timestamp` are not documented in the integration guide sample.

## 2. Checksum Logic Ambiguities

### Canonicalization Pitfalls
*   **Trailing Zeros**: The `CHECKSUM_GUIDE.md` notes that field order doesn't matter, but it fails to highlight that number formatting does. PHP's `json_encode` strips trailing zeros from floats (e.g., `1499.00` becomes `1499`). POS providers must account for this "lossy" normalization before hashing.
*   **Numerical Normalization**: Documentation should explicitly state that *all* monetary values must be cast to floats before hashing to match the server's behavior.

### Hierarchy of hashes
*   The relationship between the `transaction.payload_checksum` and the top-level `payload_checksum` needs clearer visualization. The top-level hash **includes** the transaction hash; an error at the bottom layer cascades upward.

## 3. Recommended Improvements

### Enhanced Sample Payload
Update the sample payload to reflect the actual schema used by the `TransactionIngestService`:
```json
{
  "submission_uuid": "UUID-V4",
  "tenant_id": 40,
  "terminal_id": 55,
  "transaction_count": 1,
  "transaction": {
    "transaction_id": "UUID-V4",
    "hardware_id": "...",
    "gross_sales": 1499.0,
    "adjustments": [...],
    "taxes": [...],
    "payload_checksum": "TRANSACTION_HASH"
  },
  "payload_checksum": "SUBMISSION_HASH"
}
```

### Technical Requirements for POS Providers
*   **BigInt Foreign Keys**: Note that while `transaction_id` is a UUID for tracking, the internal database mapping uses a `bigint` PK. Integration developers should strictly use the provided UUIDs for their lookups.
*   **Strict JSON Encoding**: Recommend specific JSON encoding flags (e.g., `JSON_UNESCAPED_SLASHES` if applicable) to ensure bit-perfect string matching.

## 4. Documentation Strategy
*   **Version alignment**: Ensure `TSMS-Integration-Guidelines` and `CHECKSUM_GUIDE` are updated in sync.
*   **Validation Script**: Provide or link to the `scripts/validate-checksums.php` utility as a reference implementation for POS providers.
