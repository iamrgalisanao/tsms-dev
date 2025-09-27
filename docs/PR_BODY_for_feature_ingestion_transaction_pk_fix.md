Title: feature(ingestion): preserve raw payloads, audit-first validation, reconciliation helpers; migration for SecurityEvent

Summary
-------
This branch implements ingestion and validation improvements to avoid orphan child rows, preserve inbound payload values (copy-only), record validation mismatches as audit events, and add reconciliation helpers for amounts and taxes. Included are migrations to create `security_events` and add `validation_status` to `transaction_validations`.

See `docs/PR_DRAFT_feature_ingestion_transaction_pk_fix.md` for full QA checklist, acceptance criteria, and evidence.

Migration notes
---------------
- Run `php artisan migrate --force` on staging after DB backup. Restart queue workers/Horizon after migrations.

Acceptance criteria
-------------------
- Focused `TransactionValidationTest` passes (22 tests).
- `security_events` table exists and receives rows when ingestion encounters validation mismatches.
- `transaction_validations.validation_status` column present and behaves as expected.

Testing evidence
----------------
- See `docs/PR_EVIDENCE_feature_ingestion_transaction_pk_fix.md` for migration status and focused test output.

Notes
-----
If you'd like migrations separated for independent review, branch `feature/ingestion-migrations` was created and pushed containing migration commits only.
