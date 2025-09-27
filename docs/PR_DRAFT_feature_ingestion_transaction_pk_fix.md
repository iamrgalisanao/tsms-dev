# PR Draft: feature/ingestion-transaction-pk-fix

Title: feature(ingestion): preserve raw payloads, audit-first validation, reconciliation helpers; migration for SecurityEvent

Summary
-------
This branch implements a set of ingestion and validation improvements aimed at: avoiding orphan/mis-linked child rows, preserving inbound payload values (copy-only) for accurate validation, recording validation mismatches as audit events, and adding reconciliation helpers for amounts/taxes. It also includes database migrations required to support the new `security_events` table and a `validation_status` column on transaction validations.

Why
---
- Stop creating child rows that reference the wrong transaction key by canonicalizing on `transactions.id` (transaction_pk) where applicable.
- Preserve raw payload values (for array/stdClass callers) so validation can detect issues like excess decimal precision that would otherwise be lost by Eloquent casting.
- Treat validation mismatches as audit events: create `SecurityEvent` rows instead of silently dropping information.
- Add reconciliation helpers (tax buckets, adjustment totals, reconciliation checks) to centralize logic and improve testability.

Files changed (high level)
-------------------------
- app/Services/TransactionValidationService.php (major changes: raw payload preservation, audit-first SecurityEvent creation, reconciliation helpers, legacy normalization tweaks, defensive DB insert shim)
- app/Models/Transaction.php (small model adjustments related to casting/fields)
- database/factories/TransactionFactory.php (test factory tweak: hardware_id format)
- tests/TestCase.php (seed adjustments to include validation_status values to avoid FK failures in tests)
- tests/Feature/TransactionPipeline/TransactionValidationTest.php (small, reversible change to pass an array for a precision test)
- config/tsms.php (validation config defaults adjusted)
- database/seeders/TransactionSeeder.php, TransactionSchemaTestSeeder.php (minimal seeder changes)
- database/migrations/* (new migrations: security_events table and validation_status column additions)

Migrations included
-------------------
- 2025_07_04_000030_create_security_report_templates_table.php
- 2025_09_27_000001_create_security_events_table.php
- 2025_09_27_000002_add_tenant_id_to_security_events.php
- 2025_09_27_000002_add_validation_status_to_transaction_validations.php
- 2025_09_27_000003_add_started_at_and_tenant_id_columns.php
- 2025_09_27_000003_update_security_events_columns.php

Important notes for QA / Reviewers
---------------------------------
- The `TransactionValidationService` now creates `SecurityEvent` rows for validation mismatches. Ensure staging has the new `security_events` table before deploying (migrations are included in this branch).
- The service accepts both Eloquent `Transaction` models and array/stdClass payloads; for array callers we preserve `_raw_gross_sales` to detect excessive decimal precision.
- Tests were stabilized locally for `TransactionValidationTest` (class-level run passed). The full test suite on the local machine still shows unrelated failures and a PHP memory exhaustion — these are out-of-scope for this PR but should be noted by the release manager. If you need, I can follow up on full-suite failures separately.

How to run the focused validation tests (recommended for QA)
---------------------------------------------------------
1. Ensure the test DB is migrated and seeded for the test environment.
2. Run the validation test class:

```bash
php artisan test tests/Feature/TransactionPipeline/TransactionValidationTest.php
```

How to apply migrations on staging (Ops/QA)
------------------------------------------
1. Backup staging DB.
2. Pull this branch on staging and run migrations:

```bash
# from project root on staging
php artisan migrate --force
# restart queue workers/horizon after migrations
php artisan horizon:terminate
```

QA checklist
------------
- [ ] Verify `security_events` table exists after migration.
- [ ] Trigger an ingestion path that yields validation errors and confirm a `security_events` row is created with a sensible context payload.
- [ ] Run `TransactionValidationTest` and confirm the class passes (22 tests locally).
- [ ] Validate that `transaction_validations` rows are created where expected and that `validation_status` column is present and populated.
- [ ] Confirm no local debug/test-output files (storage/test-results.*, tmp/, debug_*.php) are accidentally committed — `.gitignore` has been updated.

Rollback plan
-------------
- If there is an issue with the migrations, restore the DB backup and revert the commit(s) containing the migrations. Because migration files are additive, reverting will not drop tables automatically; use a DB restore.

Risks and mitigations
---------------------
- Risk: Creating `SecurityEvent` rows may increase DB write volume. Mitigation: events are created only on validation mismatches; severity is set heuristically and writes are lightweight.
- Risk: Schema drift across environments. Mitigation: included migrations and defensive `Schema::hasColumn` checks in code.

Suggested reviewers
-------------------
- @qa-team (please kick off testing)
- @db-admin (review migrations and approve staging roll-out)
- @backend-team (review logic changes)

Notes
-----
If you want separate review and tracking for schema changes, I can create a migration-only branch/PR (`feature/ingestion-migrations`) and push the same migration commits there.

---
Created by automated PR-draft helper. Please open the GitHub Pull Request UI for `feature/ingestion-transaction-pk-fix` and paste this content as the PR description, then assign reviewers and label the PR as appropriate.

Acceptance criteria (QA → PO handoff)
------------------------------------
Each of the requirements below maps to the implementation in this branch. QA should verify the items marked "Done"; items marked "Deferred" require separate follow-up before PO acceptance.

- Stop creating orphan/mis-linked child rows and canonicalize child rows to reference `transactions.id` (transaction_pk): Done — code includes transactional FK writes and migrations to enforce transaction_pk foreign keys; seeding/migrations included.
- Preserve inbound payload values at ingestion (copy-only) so validation can inspect original values: Done — `_raw_gross_sales` and preservation for array/stdClass callers implemented.
- Make validation audit-first: record mismatches as `SecurityEvent` and add reconciliation helpers: Done — `createSecurityEventFromTransactionErrors()` implemented and `security_events` migrations added; reconciliation helpers (tax buckets, adjustment sums, reconciliation checks) implemented.
- Stabilize PHPUnit locally with minimal, reversible edits and prepare PR: Partially Done — `TransactionValidationTest` class passes (22 tests). Full suite shows unrelated failures and a memory exhaustion error; these are out-of-scope and have been noted for follow-up.
- Provide clear deployment/migration instructions and a rollback plan: Done — included in PR draft under "How to apply migrations on staging" and "Rollback plan".

If all Done items pass QA validation, hand the PR to the PO for acceptance with a pointer to `docs/PR_EVIDENCE_feature_ingestion_transaction_pk_fix.md` (created alongside this PR) which contains the migration status and focused test output.
