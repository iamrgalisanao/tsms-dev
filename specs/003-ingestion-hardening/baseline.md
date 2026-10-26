# Baseline: Ingestion Hardening

- Branch: `003-ingestion-hardening`, created from `main` at b656c516 (no code changes yet)
- Date: 2026-10-26
- DB: `tsms_db_test` on Docker `tsms-test-mysql`, `migrate:fresh --env=testing` OK
- Command: `DB_DATABASE=tsms_db_test php artisan test --log-junit tmp/baseline/junit.xml`
- Result: 511 passed, 107 failed, 5 skipped, 3 risky, 1 deprecated (72s)
- Failing list: `tmp/baseline/failing.txt` (gitignored). The failures are pre-existing on main (known RefreshDatabase isolation bug in tests/TestCase.php), and include 3 BatchIngestionTenantTerminalValidationTest cases and 2 batch-notification cases.

Status: BASELINE_RECORDED

## Tests removed or retargeted (filled during US1)

Format: `file::method` | action | reason.

Retargeted to `POST /api/v1/transactions/official` (T005; middleware assertions kept; all passed on unchanged code before the route change, none were in `tmp/baseline/failing.txt`):

- `tests/Feature/IngestionBackpressureTest.php::test_batch_endpoint_returns_429_when_backpressure_is_enforced` → `test_official_endpoint_returns_429_when_backpressure_threshold_is_one` | retargeted | route-level backpressure vehicle
- `tests/Feature/IngestionBackpressureFailureModeTest.php::test_route_level_processing_check_returns_503_ingestion_degraded_when_redis_throws_in_enforce_mode` | retargeted (name kept) | route-level degraded path vehicle
- `tests/Feature/IngestionBackpressureFailureModeTest.php::test_route_level_processing_check_fails_open_when_redis_throws_in_observe_mode` | retargeted (name kept) | success status 200 → 202 (controller response of /official; middleware assertion "reaches controller" unchanged); after rebase onto PR #18 (TSMS-004) /official accepts with 200 `status: PENDING, code: ACCEPTED`, so the assertion is now 200 + `code: ACCEPTED`
- `tests/Feature/IngestionBackpressureFailureModeTest.php::test_degraded_response_body_and_retry_after_header_use_the_same_clamped_value` | retargeted (name kept) | vehicle only
- `tests/Feature/IngestionBackpressureFailureModeTest.php::test_degraded_response_includes_correlation_id` | retargeted (name kept) | vehicle only
- `tests/Feature/IngestionFairnessMiddlewareTest.php::test_batch_endpoint_enforces_fairness` → `test_official_endpoint_enforces_fairness_for_multi_transaction_submission` | retargeted | multi-transaction submission now sent as an official `transactions` array
- `tests/Feature/IngestionPayloadLimitTest.php::test_batch_endpoint_rejects_payload_over_byte_limit_with_413` → `test_official_multi_transaction_submission_rejects_payload_over_byte_limit_with_413` | retargeted | payload-size middleware vehicle
- `tests/Feature/IngestionPayloadLimitTest.php::test_batch_endpoint_accepts_payload_within_byte_limit` → `test_official_multi_transaction_submission_accepts_payload_within_byte_limit` | retargeted | payload-size middleware vehicle
- `tests/Feature/PosIngestionLicenseEnforcementTest.php::test_batch_ingestion_passes_through_when_license_mode_does_not_block` (disabled, observe) → `test_official_ingestion_passes_through_when_license_mode_does_not_block` | retargeted | asserts 202 + QUEUED intake row instead of 200 + transactions row (official is async); after rebase onto PR #18 (TSMS-004) the accepted response is 200 `code: ACCEPTED` (intake row still stored as QUEUED internally)
- `tests/Feature/PosIngestionLicenseEnforcementTest.php::test_batch_ingestion_is_blocked_when_license_mode_enforces_invalid_license` (restricted, enforce) → `test_official_ingestion_is_blocked_when_license_mode_enforces_invalid_license` | retargeted | 403 body assertions unchanged; also asserts no intake row and no job
- `tests/Feature/RouteProviderCharacterizationTest.php::test_pos_batch_route_keeps_terminal_auth_license_and_intake_guards` → `test_pos_batch_route_is_retired_behind_terminal_auth_and_license_without_intake_guards` | retargeted | route expectation now `retiredBatch`, auth/ability/throttle/license present, ingestion middleware absent
- `tests/Feature/IngestionFairnessTest.php`, `tests/Feature/CircuitBreakerTestIsolationTest.php` | comment-only edit | mentioned the batch route in docblocks; no test referenced it

Removed (T009; superseded by `tests/Feature/Ingestion/BatchRetirementTest.php`):

- `tests/Feature/BatchStoreTerminalBindingTest.php` (file deleted): `test_batch_store_rejects_terminal_id_that_does_not_match_authenticated_token`, `test_batch_store_accepts_own_terminal_id` | removed | tested `batchStore()` token/terminal binding; handler deleted
- `tests/Feature/BatchIngestionTenantTerminalValidationTest.php` (file deleted): `test_happy_path_matching_tenant_and_terminal`, `test_request_level_mismatch_rejected_with_422`, `test_item_level_mismatch_marks_item_failed_but_continues_batch` | removed | `batchStore()` behavior; all 3 were already failing in the baseline
- `tests/Feature/IngestionBatchLimitTest.php::test_batch_endpoint_rejects_batch_over_max_count_with_422`, `::test_batch_endpoint_batch_limit_guard_precedes_terminal_id_db_lookup`, `::test_batch_endpoint_accepts_batch_within_max_count` | removed | `batchStore()`'s own count guard (deleted); the official count guard stays covered by the two `test_official_endpoint_*` cases in the same file
- `tests/Feature/IngestionLimitBoundaryTest.php::test_batch_endpoint_exactly_at_max_count_is_not_rejected_for_count`, `::test_batch_endpoint_one_over_max_count_is_rejected_for_count` | removed | `batchStore()` count guard, not middleware; official equivalents `test_official_batch_*_max_count_*` remain in the same file
- `tests/Feature/TransactionIngestionTest_new.php::batch_endpoint_accepts_multiple_transactions` (+ helper `getValidBatchPayload`) | removed | never executed (file/class name mismatch; `@test` docblocks unsupported on PHPUnit 12)
- `tests/Feature/TransactionPipeline/TransactionEndToEndTest.php::processes_batch_transactions_end_to_end` | removed | never executed (`@test` docblock only)
- `tests/Feature/TransactionPipeline/TransactionIngestionTest.php::can_receive_batch_transactions_successfully` | removed | never executed (`@test` docblock only)

Classified, no change: `PosTerminalNotificationTest` / `PosTerminalDirectNotificationTest` `test_terminal_receives_batch_notification` call `notifyTerminalOfBatchResult()` directly (method kept, still used by `/official` legacy paths); `Module2Test` and `tests/Feature/TransactionIngestionTest.php` do not call the batch route.

## US1 full-suite comparison (T010)

- Command: `DB_DATABASE=tsms_db_test php artisan test --log-junit tmp/after-us1/junit.xml`; failures extracted to `tmp/after-us1/failing.txt` (same extraction reproduces `tmp/baseline/failing.txt` exactly)
- Result: 516 passed, 104 failed, 5 skipped, 3 risky, 1 deprecated
- Newly failing vs baseline: none
- No longer failing: the 3 removed `BatchIngestionTenantTerminalValidationTest` cases (removed, not fixed)

## Unique indexes on `transactions` (T010a)

Captured read-only during the architecture review (2026-10-26, `information_schema.STATISTICS`; see research.md A1). Not re-queried during US2.

- Staging (10.20.0.41): `PRIMARY(id)`, `ux_transactions_transaction_id(transaction_id)` (global; not created by any migration), `ux_tx_tenant_terminal_receipt_date_unique(tenant_id, terminal_id, receipt_no, transaction_date)`, `ux_transactions_refund_reference(refund_reference)`. `submission_uuid` is indexed but non-unique.
- Migration-built test DB (`tsms_db_test`, verified 2026-10-26 during US2): `PRIMARY(id)`, `trx_terminal_transaction_unique(terminal_id, transaction_id)`, `ux_transactions_submission_uuid(submission_uuid)`, `ux_tx_tenant_terminal_receipt_date_unique(...)`, `ux_transactions_refund_reference(refund_reference)`. No global `transaction_id` unique.
- Production: unverified.

## US2 full-suite comparison (T016)

- Command: `DB_DATABASE=tsms_db_test php artisan test --log-junit tmp/after-us2/junit.xml`; failures extracted to `tmp/after-us2/failing.txt` with the same extraction (it reproduces both earlier lists exactly)
- Result: 531 passed, 104 failed, 5 skipped, 3 risky, 1 deprecated, 2 warnings
- vs `tmp/after-us1/failing.txt`: identical (no new failures, none fixed)
- vs `tmp/baseline/failing.txt`: only the 3 removed `BatchIngestionTenantTerminalValidationTest` cases differ (removed in US1)
- Note: the `mysql` connection runs non-strict (`config/database.php` `'strict' => false`, session `sql_mode=NO_ENGINE_SUBSTITUTION`). Replacing `insertOrIgnore` with `insert` therefore surfaces duplicate keys and hard errors (e.g. explicit NULL into NOT NULL) but over-length values are still truncated with a warning, not rejected. The strict-mode test forces `STRICT_ALL_TABLES` for its session only.

## Final full-suite comparison (T037)

Clean isolated run after all slices (test DB re-migrated, no concurrent runs), 2026-10-26:
- Full suite: 575 passed, 104 failed, 5 skipped, 3 risky, 1 deprecated (`tmp/final/junit.xml`, `tmp/final/failing.txt`).
- Newly failing vs baseline: none.
- Differences vs baseline: only the 3 removed `BatchIngestionTenantTerminalValidationTest` cases (deleted in US1).
- `tests/Feature/Ingestion`: 65/65 passed.
- Note: one earlier full run was invalid because two test runs shared `tsms_db_test` concurrently; it was discarded and rerun in isolation.

Status: all slices REVIEW_PASS and ARCHITECTURE_CONSISTENT.

## After rebase onto main 721577c9 (PR #18 / TSMS-004 merged)

- Rebased cleanly (no conflicts); alignment commit ea7c05a8 updates 003 tests/docs to the 200 PENDING/ACCEPTED contract.
- Full suite (isolated, test DB re-migrated): 591 passed, 103 failed, 5 skipped (`tmp/after-rebase/`). Newly failing vs baseline: none. Newly passing vs pre-rebase: `OfficialIdempotentReplayTest::test_replay_same_transaction_id_is_idempotent_across_submissions` (fixed by PR #18).
- Note: intermediate commits df8a3c56..b2731b93 assert 202 and fail against main's 200 contract until ea7c05a8 (history not rewritten); use `git bisect skip` for them.
