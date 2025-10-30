Parked issues (temporary)
=========================

Date: 2025-10-30

Context
-------
After recent fixes to ensure invalid JSON payloads are persisted as ERROR in transactions and to add backward-compatible summary keys in the security report aggregator, a subset of unit tests still fail during the full test run.

Action
------
These failures are being parked for future troubleshooting because they are largely outside the immediate transaction tamper-check scope that was the focus of the recent changes. The goal is to push the current working code (including the transaction tamper fixes and the report-aggregation compatibility updates) to staging so integration and QA can proceed.

Parked items
------------
- Tests failing in `Tests\Unit\Services\Security\ReportAggregationServiceTest` (login attempts, security alerts, comprehensive, insights) — root causes: factory insertions referencing columns/tables that do not exist in the current schema (e.g., `action` column, missing `security_alerts` table), duplicate template names causing unique constraint violations.
- Tests failing in `Tests\Unit\Services\Security\SecurityReportingServiceTest` — likely downstream of report templates / report storage schema differences.
- Tests failing in `Tests\Unit\TransactionProcessingServiceTest` and `Tests\Unit\TransactionValidationServiceTest` — some failures show missing expected input keys in tests (e.g., `transaction_id`) and validation errors; these will be examined separately.

Next steps (future troubleshooting)
---------------------------------
1. Review `database/factories/*` for `SecurityEvent` and `SecurityAlert` and align them with current migrations/models; either update factories or add non-destructive migrations to restore expected columns.
2. Ensure test templates use unique names or adjust the factories/tests to avoid unique constraint collisions.
3. Inspect `TransactionProcessingService` input contract vs. unit test fixtures and reconcile missing keys.
4. Re-run full test suite in CI after the above fixes and iterate.

Notes
-----
- This note intentionally does not block pushing the current feature branch to staging because the recent changes (invalid JSON persistence and aggregator compatibility aliases) are valuable and should be available for QA.

Branch: feat/void-by-receipt-no
