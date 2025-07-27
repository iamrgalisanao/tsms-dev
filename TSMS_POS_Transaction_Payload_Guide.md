# TSMS POS Single and Batch Transaction Payload Guidelines

This document provides the official format and requirements for POS terminal providers and tenants to submit **single or batch transactions** to the TSMS system.

---

## 1. Single Transaction Submission Structure

Each submission contains metadata and a single transaction object.

### Example Single Transaction Payload

```json
{
    "submission_uuid": "batch-uuid-123",
    "tenant_id": 1,
    "terminal_id": 1,
    "submission_timestamp": "2025-07-04T12:00:00Z",
    "transaction_count": 1,
    "payload_checksum": "sha256-of-batch",
    "transaction": {
        "transaction_id": "txn-uuid-001",
        "transaction_timestamp": "2025-07-04T12:00:01Z",
        "base_amount": 1000.0,
        "payload_checksum": "sha256-txn-001",
        "adjustments": [
            { "adjustment_type": "promo_discount", "amount": 50.0 },
            { "adjustment_type": "senior_discount", "amount": 20.0 }
        ],
        "taxes": [
            { "tax_type": "VAT", "amount": 120.0 },
            { "tax_type": "OTHER_TAX", "amount": 10.0 }
        ]
    }
}
```

---

## 2. Batch Transaction Submission Structure

A batch submission contains metadata and an array of transaction objects. Use this format to submit multiple transactions in a single request.

**Note:** You may also use the batch format to submit a single transaction by providing a `transactions` array with only one transaction object and setting `transaction_count` to 1. This is functionally equivalent to a single transaction submission, but uses the batch structure.

### Example Batch Transaction Payload (Multiple Transactions)

```json
{
    "submission_uuid": "batch-uuid-200",
    "tenant_id": 2,
    "terminal_id": 5,
    "submission_timestamp": "2025-07-04T13:00:00Z",
    "transaction_count": 3,
    "payload_checksum": "sha256-of-batch-200",
    "transactions": [
        {
            "transaction_id": "txn-uuid-201",
            "transaction_timestamp": "2025-07-04T13:00:01Z",
            "base_amount": 1500.0,
            "payload_checksum": "sha256-txn-201",
            "adjustments": [
                { "adjustment_type": "promo_discount", "amount": 100.0 }
            ],
            "taxes": [{ "tax_type": "VAT", "amount": 180.0 }]
        },
        {
            "transaction_id": "txn-uuid-202",
            "transaction_timestamp": "2025-07-04T13:01:00Z",
            "base_amount": 2000.0,
            "payload_checksum": "sha256-txn-202",
            "adjustments": [
                { "adjustment_type": "service_charge", "amount": 50.0 },
                { "adjustment_type": "senior_discount", "amount": 80.0 }
            ],
            "taxes": [
                { "tax_type": "VAT", "amount": 240.0 },
                { "tax_type": "VAT_EXEMPT", "amount": 0.0 }
            ]
        },
        {
            "transaction_id": "txn-uuid-203",
            "transaction_timestamp": "2025-07-04T13:02:00Z",
            "base_amount": 500.0,
            "payload_checksum": "sha256-txn-203"
        }
    ]
}
```

### Example Batch Transaction Payload (Single Transaction)

This is valid and may be used if your integration always uses the batch format:

```json
{
    "submission_uuid": "batch-uuid-300",
    "tenant_id": 3,
    "terminal_id": 7,
    "submission_timestamp": "2025-07-04T14:00:00Z",
    "transaction_count": 1,
    "payload_checksum": "sha256-of-batch-300",
    "transactions": [
        {
            "transaction_id": "txn-uuid-301",
            "transaction_timestamp": "2025-07-04T14:00:01Z",
            "base_amount": 800.0,
            "payload_checksum": "sha256-txn-301",
            "adjustments": [
                { "adjustment_type": "promo_discount", "amount": 20.0 }
            ],
            "taxes": [{ "tax_type": "VAT", "amount": 96.0 }]
        }
    ]
}
```

---

## 3. Field Requirements

### Submission Fields (Single & Batch)

-   `submission_uuid` (string, required): Unique UUID for the submission.
-   `tenant_id` (integer, required): Tenant identifier assigned by TSMS.
-   `terminal_id` (integer, required): POS terminal identifier assigned by TSMS.
-   `submission_timestamp` (ISO8601 string, required): When the submission was sent.
-   `transaction_count` (integer, required): Number of transactions in the submission (1 for single, N for batch).
-   `payload_checksum` (string, required): SHA-256 hash of the full submission payload.
-   `transaction` (object, required for single): The transaction object.
-   `transactions` (array, required for batch): Array of transaction objects.

### Transaction Fields

-   `transaction_id` (string, required): Unique UUID for the transaction.
-   `transaction_timestamp` (ISO8601 string, required): When the sale occurred.
-   `base_amount` (decimal, required): Total gross sales amount.
-   `payload_checksum` (string, required): SHA-256 hash of the transaction payload.
-   `adjustments` (array, optional): List of discounts, promos, or service charges.
-   `taxes` (array, optional): List of tax lines (VAT, VAT-exempt, other).

### Adjustments

-   `adjustment_type` (string, required): Type of adjustment (e.g., `promo_discount`, `senior_discount`, `service_charge`).
-   `amount` (decimal, required): Amount of the adjustment.

### Taxes

-   `tax_type` (string, required): Type of tax (e.g., `VAT`, `VAT_EXEMPT`, `OTHER_TAX`).
-   `amount` (decimal, required): Amount of the tax.

---

## 4. General Rules

-   All IDs must be unique within their scope (submission, transaction).
-   All timestamps must be in ISO8601 format (e.g., `2025-07-04T12:00:00Z`).
-   All monetary values must be decimals with two decimal places.
-   The payload checksum must be calculated using SHA-256.
-   Do not include validation, job, or status fields—these are system-generated.
-   For batch submissions, each transaction in the `transactions` array must follow the same structure as a single transaction.

---

## 5. Example Minimal Single Transaction

```json
{
    "submission_uuid": "batch-uuid-124",
    "tenant_id": 1,
    "terminal_id": 1,
    "submission_timestamp": "2025-07-04T12:05:00Z",
    "transaction_count": 1,
    "payload_checksum": "sha256-of-batch-2",
    "transaction": {
        "transaction_id": "txn-uuid-003",
        "transaction_timestamp": "2025-07-04T12:05:00Z",
        "base_amount": 500.0,
        "payload_checksum": "sha256-txn-003"
    }
}
```

---

## 6. Computing the Payload Checksum

The `payload_checksum` field is required for both single and batch submissions. It ensures data integrity and must be computed using the SHA-256 algorithm.

### Single Transaction Submission

-   The `payload_checksum` at the submission level is computed from the entire JSON payload (as a string), including all fields and the nested `transaction` object, but with the `payload_checksum` field itself left blank or omitted during calculation.
-   The `payload_checksum` inside the `transaction` object is computed from the JSON string of the transaction object (including all its fields and nested arrays), again with its own `payload_checksum` field left blank or omitted during calculation.

#### Example Steps:

1. Prepare the JSON object for the submission, leaving the `payload_checksum` field empty or omitting it.
2. Serialize the object to a compact JSON string (no extra whitespace or line breaks).
3. Compute the SHA-256 hash of this string.
4. Insert the resulting hash as the value of `payload_checksum`.

### Batch Transaction Submission

-   The `payload_checksum` at the submission level is computed from the entire JSON payload (including the `transactions` array), with the `payload_checksum` field itself left blank or omitted during calculation.
-   Each transaction in the `transactions` array must also have its own `payload_checksum`, computed from the JSON string of the transaction object (with its own `payload_checksum` field left blank or omitted).

#### Example Steps:

1. For each transaction object, leave its `payload_checksum` field empty or omit it, serialize to a compact JSON string, and compute the SHA-256 hash. Insert the hash into the transaction object.
2. Prepare the full submission object (with all transactions now having their checksums), leave the submission-level `payload_checksum` field empty or omit it, serialize to a compact JSON string, and compute the SHA-256 hash.
3. Insert the resulting hash as the value of the submission's `payload_checksum`.


### Important Details for Proper Checksum Computation

- **Omit or blank the `payload_checksum` field** before hashing. For each transaction, remove or leave empty the `payload_checksum` field before computing its hash. For the submission, do the same for the submission-level `payload_checksum` field.
- **Use compact JSON**: Serialize the object to a compact JSON string (no extra spaces, tabs, or line breaks, and fields in a consistent order) before hashing. Pretty-printed or minified JSON with different field orders will result in a different hash.
- **SHA-256 Algorithm**: Always use the SHA-256 algorithm for hashing.
- **Order of Operations for Batch:**
    1. Compute and insert the `payload_checksum` for each transaction first (with their own `payload_checksum` omitted/blank).
    2. Then, compute the submission-level `payload_checksum` (with all transaction checksums present, but the submission-level checksum omitted/blank).
- **For single transaction submissions:**
    1. Compute the transaction's `payload_checksum` first (with its own field omitted/blank).
    2. Then, compute the submission's `payload_checksum` (with the transaction checksum present, but the submission-level checksum omitted/blank).
- **Field order must be consistent**: Always serialize fields in the same order for both checksum calculation and submission.
- **No extra fields**: Do not include system-generated or validation fields (like `validation_status`, `job_status`, etc.) in the payload or during checksum calculation.

**Summary:**

- Omit or blank the `payload_checksum` field before hashing.
- Use compact JSON (no pretty-printing, no extra whitespace).
- Use SHA-256.
- For batch, calculate transaction checksums first, then the submission checksum.
- For single, calculate the transaction checksum, then the submission checksum.

This ensures your payloads will pass checksum validation in the TSMS API.

---

## 7. Sample Code: Computing the Payload Checksum

Below is a sample code snippet in JavaScript (Node.js) for computing the SHA-256 payload_checksum for a transaction object or a full submission payload. The same logic applies in other languages (Python, PHP, Java, etc.) using their respective SHA-256 libraries.

```javascript
const crypto = require("crypto");

function computePayloadChecksum(obj) {
    // Clone the object to avoid mutating the original
    const clone = JSON.parse(JSON.stringify(obj));
    // Remove or blank out the payload_checksum field
    if ("payload_checksum" in clone) {
        delete clone.payload_checksum;
    }
    // For transaction objects inside arrays
    if (Array.isArray(clone.transactions)) {
        clone.transactions = clone.transactions.map((txn) => {
            const txnClone = { ...txn };
            delete txnClone.payload_checksum;
            return txnClone;
        });
    }
    // Serialize to compact JSON (no spaces or line breaks)
    const jsonString = JSON.stringify(clone);
    // Compute SHA-256 hash
    return crypto.createHash("sha256").update(jsonString).digest("hex");
}

// Example usage:
const transaction = {
    transaction_id: "txn-uuid-001",
    transaction_timestamp: "2025-07-04T12:00:01Z",
    base_amount: 1000.0,
    // payload_checksum will be omitted for calculation
    adjustments: [
        { adjustment_type: "promo_discount", amount: 50.0 },
        { adjustment_type: "senior_discount", amount: 20.0 },
    ],
    taxes: [
        { tax_type: "VAT", amount: 120.0 },
        { tax_type: "OTHER_TAX", amount: 10.0 },
    ],
};

const checksum = computePayloadChecksum(transaction);
console.log("Transaction payload_checksum:", checksum);
```

---

### Sample Code: Computing payload_checksum for Batch Submissions

Below is a JavaScript (Node.js) example for preparing a batch submission, computing the checksum for each transaction, and then for the full batch payload:

```javascript
const crypto = require("crypto");

function computePayloadChecksum(obj) {
    const clone = JSON.parse(JSON.stringify(obj));
    if ("payload_checksum" in clone) {
        delete clone.payload_checksum;
    }
    if (Array.isArray(clone.transactions)) {
        clone.transactions = clone.transactions.map((txn) => {
            const txnClone = { ...txn };
            delete txnClone.payload_checksum;
            return txnClone;
        });
    }
    const jsonString = JSON.stringify(clone);
    return crypto.createHash("sha256").update(jsonString).digest("hex");
}

// Example batch submission object (before checksums)
let batchSubmission = {
    submission_uuid: "batch-uuid-400",
    tenant_id: 4,
    terminal_id: 10,
    submission_timestamp: "2025-07-04T15:00:00Z",
    transaction_count: 2,
    // payload_checksum will be added after
    transactions: [
        {
            transaction_id: "txn-uuid-401",
            transaction_timestamp: "2025-07-04T15:00:01Z",
            base_amount: 1200.0,
            // payload_checksum will be added after
            adjustments: [{ adjustment_type: "promo_discount", amount: 60.0 }],
            taxes: [{ tax_type: "VAT", amount: 144.0 }],
        },
        {
            transaction_id: "txn-uuid-402",
            transaction_timestamp: "2025-07-04T15:01:00Z",
            base_amount: 900.0,
            // payload_checksum will be added after
            taxes: [{ tax_type: "VAT", amount: 108.0 }],
        },
    ],
};

// 1. Compute and insert payload_checksum for each transaction
batchSubmission.transactions = batchSubmission.transactions.map((txn) => {
    const txnForChecksum = { ...txn };
    txnForChecksum.payload_checksum = computePayloadChecksum(txnForChecksum);
    return txnForChecksum;
});

// 2. Compute and insert payload_checksum for the batch submission
const batchForChecksum = { ...batchSubmission };
batchForChecksum.payload_checksum = undefined; // or delete batchForChecksum.payload_checksum;
const batchChecksum = computePayloadChecksum(batchForChecksum);
batchSubmission.payload_checksum = batchChecksum;

console.log(
    "Batch submission with checksums:",
    JSON.stringify(batchSubmission, null, 2)
);
```

This code ensures that each transaction and the overall batch submission have the correct SHA-256 checksums, following the documented process.

---

## 8. Contact

For questions or integration support, contact the TSMS technical team at [support@tsms.example.com](mailto:support@tsms.example.com).
