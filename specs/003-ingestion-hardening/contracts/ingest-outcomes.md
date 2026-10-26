# Contract: Ingest outcomes and intake processing status

The official endpoint's HTTP contract is unchanged (accepted submissions still return the same status and body: 200 `success: true, status: PENDING, code: ACCEPTED` since PR #18, TSMS-004). This contract covers the asynchronous outcome recorded on `transaction_intake`.

| Scenario | Ingest result | Intake processing_status | last_error_code |
| --- | --- | --- | --- |
| New sale | accepted | PROCESSED | null |
| Same tenant resubmits same transaction_id | already_processed | PROCESSED | null |
| Same terminal/receipt/business date, new transaction_id | duplicate / duplicate_receipt_conflict | DUPLICATE | duplicate_receipt_conflict |
| transaction_id already used by another tenant | failed / cross_tenant_transaction_id_collision | FAILED_PERMANENT | cross_tenant_transaction_id_collision |
| Other unique index hit | failed / unique_constraint_violation | FAILED_PERMANENT | unique_constraint_violation |
| Missing net_sales (direct ingest call) | failed / invalid_payload | FAILED_PERMANENT | invalid_payload |
| Two concurrent identical new sales | one accepted, one already_processed | both PROCESSED | null |

The message "insert ignored but no existing transaction found" is never produced after this feature.
