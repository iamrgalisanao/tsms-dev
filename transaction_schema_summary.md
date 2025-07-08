
# Transaction Schema Summary

This document summarizes all normalized tables and lookup tables for the TSMS transaction processing schema.

---

## 1. `validation_statuses`
Lookup table for validation statuses.
- **code** `(VARCHAR(20))` – Primary key, e.g. 'PENDING', 'VALID', 'ERROR'
- **description** `(VARCHAR(100))` – Human-readable description

---

## 2. `job_statuses`
Lookup table for job processing statuses.
- **code** `(VARCHAR(20))` – Primary key, e.g. 'QUEUED', 'PROCESSING', 'RETRYING', 'FAILED', 'COMPLETED'
- **description** `(VARCHAR(100))` – Human-readable description

---

## 3. `transaction_submissions`
Stores each batch/submission of transactions from a POS terminal.
- **id** `(BIGINT UNSIGNED)` – PK
- **tenant_id** `(BIGINT UNSIGNED)` – FK to `tenants(id)`
- **terminal_id** `(BIGINT UNSIGNED)` – FK to `pos_terminals(id)`
- **submission_uuid** `(CHAR(36))` – Unique UUID for the batch
- **submission_timestamp** `(DATETIME)` – When batch was received
- **transaction_count** `(INT UNSIGNED)` – Number of transactions in batch
- **payload_checksum** `(CHAR(64))` – SHA-256 of full payload
- **status** `(VARCHAR(20))` – e.g. 'RECEIVED', 'PROCESSED', 'FAILED'
- **created_at** `(TIMESTAMP)`
- **updated_at** `(TIMESTAMP)`

---

## 4. `transactions`
Core transaction table with immutable POS-provided data.
- **id** `(BIGINT UNSIGNED)` – PK
- **submission_id** `(BIGINT UNSIGNED)` – FK to `transaction_submissions(id)`
- **transaction_id** `(CHAR(36))` – Unique transaction UUID
- **transaction_timestamp** `(DATETIME)` – When the sale occurred
- **base_amount** `(DECIMAL(15,2))` – Total gross sales amount
- **payload_checksum** `(CHAR(64))` – SHA-256 of individual transaction payload
- **created_at** `(TIMESTAMP)`
- **updated_at** `(TIMESTAMP)`

---

## 5. `transaction_adjustments`
Records any discounts or service charges per transaction.
- **id** `(BIGINT UNSIGNED)` – PK
- **transaction_id** `(CHAR(36))` – FK to `transactions(transaction_id)`
- **adjustment_type** `(VARCHAR(50))` – e.g. 'senior_discount', 'service_charge'
- **amount** `(DECIMAL(15,2))`
- **created_at** `(TIMESTAMP)`

---

## 6. `transaction_taxes`
Stores tax lines (VAT, VAT-exempt, other) for each transaction.
- **id** `(BIGINT UNSIGNED)` – PK
- **transaction_id** `(CHAR(36))` – FK to `transactions(transaction_id)`
- **tax_type** `(VARCHAR(20))` – e.g. 'VAT', 'VAT_EXEMPT', 'OTHER_TAX'
- **amount** `(DECIMAL(15,2))`
- **created_at** `(TIMESTAMP)`

---

## 7. `transaction_validations`
Audit history of validation runs on transactions.
- **id** `(BIGINT UNSIGNED)` – PK
- **transaction_id** `(CHAR(36))` – FK to `transactions(transaction_id)`
- **status_code** `(VARCHAR(20))` – FK to `validation_statuses(code)`
- **validation_details** `(TEXT)` – Any detailed messages
- **error_code** `(VARCHAR(191))` – If applicable
- **validated_at** `(TIMESTAMP)` – When validation occurred

---

## 8. `transaction_jobs`
Processing and retry metadata for each transaction.
- **id** `(BIGINT UNSIGNED)` – PK
- **transaction_id** `(CHAR(36))` – FK to `transactions(transaction_id)`
- **job_status** `(VARCHAR(20))` – FK to `job_statuses(code)`
- **last_error** `(TEXT)` – Last error message if failed
- **attempts** `(INT UNSIGNED)` – Number of processing attempts
- **retry_count** `(INT UNSIGNED)` – Number of retries
- **completed_at** `(TIMESTAMP)` – When processing completed
- **created_at** `(TIMESTAMP)`
- **updated_at** `(TIMESTAMP)`

---
