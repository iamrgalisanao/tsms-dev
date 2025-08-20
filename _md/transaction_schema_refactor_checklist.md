# Transaction Schema Refactor: Prioritized Checklist

This checklist will help guide the migration to the new normalized transaction schema. Tackle each item in order for a smooth transition.

---

## Transaction Processing Pipeline (Normalized Schema)

1. **Submission**: POS submits a batch → `transaction_submissions`
2. **Ingestion**: Each transaction → `transactions` (immutable data only)
3. **Details**: Adjustments → `transaction_adjustments`, Taxes → `transaction_taxes`
4. **Processing**: Jobs → `transaction_jobs` (all job status, errors, retries)
5. **Validation**: Results → `transaction_validations` (all validation status, details, errors)
6. **Statuses**: All status codes reference lookup tables (`job_statuses`, `validation_statuses`)
7. **Querying**: All analytics, status, and error queries use `transaction_jobs` and `transaction_validations`, not `transactions`

---

## 1. Database & Migrations

-   [x] Create new tables: `transaction_adjustments`, `transaction_taxes`, `transaction_jobs`, `transaction_validations`, and lookup tables (`validation_statuses`, `job_statuses`).
-   [x] Update `transactions` table: remove/move fields, add `customer_code`, replace `gross_sales` with `base_amount`.
-   [x] Write migration scripts for data transformation and backfill.

## 2. Models

-   [x] Update `Transaction` model: fields, relationships, casts.
-   [x] Create models for adjustments, taxes, jobs, validations, and lookups.

## 3. Requests & Validation

-   [x] Update `TransactionRequest` and `ProcessTransactionRequest` to use new fields and relationships.
-   [x] Update validation rules for `customer_code`, `base_amount`, and related fields.

## 4. Services & Business Logic

-   [x] Refactor all transaction-related services to use new schema and relationships.
-   [x] Update logic for adjustments, taxes, jobs, and validations.

## 5. Controllers

-   [x] Update all transaction-related controllers to use new models, fields, and relationships.
-   [x] Refactor payload handling for new structure.

## 6. Jobs

-   [x] Update all jobs (e.g., `ProcessTransactionJob`, `RetryTransactionJob`) to use new schema.

## 7. Factories & Seeders

-   [ ] Update/create factories for all new tables.
-   [ ] Seed lookup tables and test data.

## 8. Tests

-   [ ] Update all feature/unit tests to use new schema and payloads.
-   [ ] Add tests for new relationships and business rules.

## 9. Views & API

-   [ ] Update all views and API responses to use new fields and relationships.
-   [ ] Update frontend code as needed.

## 10. Documentation

-   [ ] Update all documentation, integration guides, and API references.

---

Work through this list top-down for best results. Check off each item as you complete it!
