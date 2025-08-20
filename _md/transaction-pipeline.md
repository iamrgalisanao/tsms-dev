# TSMS Transaction Pipeline & Data Flow Documentation

This document describes the end-to-end data flow for POS transaction submissions in the TSMS application, including the main controllers, methods, and processing steps involved.

---

## 1. Overview

The TSMS transaction pipeline ingests transaction payloads from POS terminals via API endpoints, validates and persists them, and manages downstream processing (e.g., queueing, logging, and status tracking).

---

## 2. Data Flow Summary

1. **POS Terminal/Provider** submits a transaction (single or batch) to the API endpoint (`/api/v1/transactions/official`).
2. **API Controller** receives and validates the payload.
3. **Checksum Service** validates the integrity of the payload.
4. **Transaction & Related Models** persist the transaction, adjustments, and taxes.
5. **Queue/Job System** processes the transaction asynchronously.
6. **Status & Logs** are updated and can be queried via API or web dashboard.

---

## 3. Main Controllers & Methods

### 3.1. `App\Http\Controllers\API\V1\TransactionController`

- **Endpoint:** `/api/v1/transactions/official`
- **Methods:**
  - `public function official(Request $request)`
    - Entry point for official transaction submissions (single or batch)
    - Validates payload structure and required fields
    - Validates checksums (submission and transaction)
    - Persists transaction(s), adjustments, and taxes
    - Dispatches processing jobs
    - Returns success or error response

- **Other Methods:**
  - `validateTransactionChecksum(array $transaction)`
    - Computes and verifies SHA-256 checksums for transaction objects
  - `validateSubmissionChecksums(array $payload)` (via service)
    - Validates checksums for the entire submission and all transactions
  - `processSingleTransaction(array $transaction)`
    - Handles persistence and job dispatch for a single transaction
  - `processBatchTransactions(array $transactions)`
    - Handles persistence and job dispatch for batch submissions

### 3.2. `App\Services\PayloadChecksumService`
- **Methods:**
  - `computeChecksum(array $payload)`
  - `validateSubmissionChecksums(array $payload)`

### 3.3. `App\Jobs\ProcessTransactionJob`
- Handles asynchronous processing of ingested transactions (e.g., further validation, posting to other systems, updating status)

### 3.4. `App\Http\Controllers\API\V1\TransactionStatusController`
- **Endpoint:** `/api/v1/transactions/{transaction_id}/status`
- **Method:** `public function show($transaction_id)`
    - Returns the current status and details of a transaction

---

## 4. Data Flow Diagram (Textual)

```
[POS Terminal] 
    → [POST /api/v1/transactions/official]
        → [TransactionController@official]
            → [PayloadChecksumService]
            → [Persist Transaction, Adjustments, Taxes]
            → [Dispatch ProcessTransactionJob]
                → [Queue Worker]
                    → [Further Processing, Status Update]
        ← [API Response]
```

---

## 5. Key Processing Steps

1. **API Request Received**
    - Payload is parsed and validated for required fields and structure.
2. **Checksum Validation**
    - Both submission and transaction-level checksums are validated using SHA-256.
3. **Persistence**
    - Transaction, adjustments, and taxes are saved to the database.
4. **Job Dispatch**
    - A job is queued for further processing (e.g., business rules, notifications).
5. **Status Tracking**
    - Transaction status is updated and can be queried via status endpoint.
6. **Error Handling**
    - Validation or processing errors return a 422 or 500 response with details.

---

## 6. Related Files & Classes

- `app/Http/Controllers/API/V1/TransactionController.php`
- `app/Http/Controllers/API/V1/TransactionStatusController.php`
- `app/Services/PayloadChecksumService.php`
- `app/Jobs/ProcessTransactionJob.php`
- `app/Models/Transaction.php`, `TransactionAdjustment.php`, `TransactionTax.php`
- `tests/Feature/TransactionIngestionTest.php`, `TransactionIngestionTest_new.php`

---

## 7. Notes

- The pipeline supports both single and batch transaction submissions.
- All payloads must pass checksum validation for ingestion.
- Adjustments and taxes are optional arrays in the transaction object.
- The system is extensible for future enhancements (e.g., voids, refunds).

---

For more details, see the TSMS POS Transaction Payload Guide and the codebase documentation.
