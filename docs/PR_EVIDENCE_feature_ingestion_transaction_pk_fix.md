# Evidence: feature/ingestion-transaction-pk-fix

This file captures the migration status and focused test run output used for QA validation and PO handoff.

Migration status (excerpt)
--------------------------
The following migrations are present and have been run in this environment (excerpt):

```
2025_07_04_000030_create_security_report_templates_table  [1] Ran
2025_09_27_000001_create_security_events_table            [1] Ran
2025_09_27_000002_add_tenant_id_to_security_events       [1] Ran
2025_09_27_000002_add_validation_status_to_transaction_validations [1] Ran
2025_09_27_000003_add_started_at_and_tenant_id_columns   [1] Ran
2025_09_27_000003_update_security_events_columns         [1] Ran
```

Focused test run (TransactionValidationTest)
-------------------------------------------
Command:

```
php artisan test tests/Feature/TransactionPipeline/TransactionValidationTest.php
```

Result:

```
PASS  Tests\Feature\TransactionPipeline\TransactionValidationTest
  ✓ smoke discovery
  ✓ validates valid transaction
  ✓ validates operating hours
  ✓ validates negative amounts
  ✓ validates terminal status
  ✓ validates maximum amount limits
  ✓ validates minimum amount limits
  ✓ validates transaction timestamp format
  ✓ validates future timestamps
  ✓ validates old transactions
  ✓ validates customer code format
  ✓ validates terminal belongs to customer
  ✓ validates duplicate transaction ids
  ✓ validates required fields
  ✓ validates decimal precision
  ✓ validates concurrent transactions
  ✓ validates transaction items consistency
  ✓ validates hardware id format
  ✓ logs validation results
  ✓ validates business rules
  ✓ validates transaction sequence
  ✓ validates multiple validation errors

Tests:    22 passed (47 assertions)
Duration: 2.82s
```

Notes:
- The focused validation tests for this PR pass locally. The full PHPUnit suite produced many unrelated failures and a memory exhaustion error in an earlier run; that is logged separately and should be triaged as a follow-up.
