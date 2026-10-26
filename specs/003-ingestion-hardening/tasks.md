# Tasks: Ingestion Hardening

**Input**: Design documents from `specs/003-ingestion-hardening/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Included. The spec's success criteria (SC-001..SC-006) and the preservation rule (FR-022) require automated tests; behavior-preservation tests are written before changes.

**Order note**: Phases follow plan.md's slice order. US4, US6 and US7 come right after US2 because they edit the same file (`TransactionIngestService.php`) and must be serialized.

All test commands run against MySQL: `DB_DATABASE=tsms_db_test php artisan test <path>` (container `tsms-test-mysql`).

## Phase 1: Setup

- [x] T001 Confirm baseline artifacts exist (`tmp/baseline/failing.txt`, 107 failures; `tmp/baseline/junit.xml`) and record them in specs/003-ingestion-hardening/baseline.md with the commit hash and the command used
- [x] T002 [P] Add additive config keys `timestamps.*` and `rejection_alerts.*` (defaults per data-model.md) to config/ingestion.php

## Phase 2: Foundational (characterization before any change)

- [x] T003 Write tests/Feature/Ingestion/OfficialIntakeContractTest.php pinning current behavior of POST /api/v1/transactions/official: accepted single and batch-in-official responses (status + JSON keys + values), 422 structural failure shape, 403 HARDWARE_ID_MISMATCH shape, 422 on resubmitting a rejected submission_uuid, intake row statuses, and the recorded transaction values after running ProcessTransactionIntakeJob synchronously
- [x] T004 Run T003 on the unchanged code; it must pass before any later phase starts

**Checkpoint**: contract characterization green on unchanged code.

## Phase 3: User Story 1 - Retire the batch endpoint (P1)

**Goal**: No sale can be recorded through `/transactions/batch` (SC-001).
**Independent test**: tests/Feature/Ingestion/BatchRetirementTest.php passes; T003 still passes.

- [x] T005 [US1] Retarget middleware tests that use the batch route only as a vehicle to POST /api/v1/transactions/official with an equivalent valid payload, keeping each test's assertions about the middleware: tests/Feature/IngestionBackpressureTest.php, tests/Feature/IngestionBackpressureFailureModeTest.php, tests/Feature/IngestionFairnessMiddlewareTest.php, tests/Feature/IngestionFairnessTest.php, tests/Feature/IngestionPayloadLimitTest.php, tests/Feature/IngestionLimitBoundaryTest.php, tests/Feature/CircuitBreakerTestIsolationTest.php, tests/Feature/PosIngestionLicenseEnforcementTest.php; run them on unchanged code and compare with tmp/baseline/failing.txt
- [x] T006 [P] [US1] Write tests/Feature/Ingestion/BatchRetirementTest.php per contracts/batch-retirement.md: authenticated → 410 JSON body; no token → 401; missing ability → 403; oversized and malformed bodies → 410 without validation; no rows in transactions, transaction_intake, system_logs and no job pushed (Queue::fake)
- [x] T007 [US1] Add `retiredBatch(Request $request)` to app/Http/Controllers/API/V1/TransactionController.php returning the 410 body and a single Log::warning with terminal_id, tenant_id, correlation_id; delete the body of `batchStore()` (remove the method)
- [x] T008 [US1] In routes/api.php point both `transactions/batch` routes (the authenticated v1 group and the TSMS_ENABLE_LEGACY_API group) to `retiredBatch`, dropping the ingestion middleware from the authenticated one
- [x] T009 [US1] Remove or replace batch-only tests now superseded by BatchRetirementTest: tests/Feature/BatchStoreTerminalBindingTest.php, tests/Feature/BatchIngestionTenantTerminalValidationTest.php, tests/Feature/IngestionBatchLimitTest.php, and the batch-route cases in tests/Feature/TransactionIngestionTest_new.php, tests/Feature/TransactionPipeline/TransactionEndToEndTest.php, tests/Feature/TransactionPipeline/TransactionIngestionTest.php, tests/Feature/RouteProviderCharacterizationTest.php (update the route expectation to the retirement handler); also grep tests/ for any other caller (e.g. PosTerminalNotificationTest / PosTerminalDirectNotificationTest batch-notification cases, Module2Test) and classify each as remove or retarget; list each removed test name in specs/003-ingestion-hardening/baseline.md
- [x] T010 [US1] Run T003, T005 files, T006 and Pint; compare full-suite failures with baseline

## Phase 4: User Story 2 - Correct ingest outcome classification (P1)

**Goal**: Intake outcome matches the ledger (SC-002).
**Independent test**: tests/Feature/Ingestion/IngestOutcomeClassificationTest.php passes; T003 still passes.

- [x] T010a [US2] Read-only: capture the full unique-index list of `transactions` on staging (information_schema.STATISTICS) into specs/003-ingestion-hardening/baseline.md (done 2026-10-26 during review: global transaction_id unique, receipt/date unique, refund unique; submission_uuid non-unique)
- [x] T011 [P] [US2] Write tests/Feature/Ingestion/IngestOutcomeClassificationTest.php per contracts/ingest-outcomes.md, calling TransactionIngestService::ingest and ProcessTransactionIntakeJob: new sale; same-tenant duplicate transaction_id; receipt/date duplicate with a new transaction_id; cross-tenant transaction_id collision (asserts no other-tenant id in result and a warning logged); a forced strict-mode error surfaces as failed not ignored; assert "insert ignored but no existing transaction found" never appears. Add: same transaction_id present in two tenants plus a same-tenant resubmission → already_processed (not a collision). Simulate duplicate-key races through an `insertTransactionParent` test subclass that throws a QueryException with realistic `errorInfo` ['23000', 1062, "Duplicate entry 'x' for key 'transactions.<index>'"] (research A9), so tests are schema-independent
- [x] T012 [P] [US2] Add a concurrency test in tests/Feature/Ingestion/IngestOutcomeClassificationTest.php: two ingest calls for the same new sale where the second insert hits the unique index after the first commits → exactly one row, second result already_processed
- [x] T013 [US2] In app/Services/TransactionIngestService.php keep `insertTransactionParent(array $parent): int` but make it call `insert()` and return 1; in its caller inside the transaction closure catch QueryException where `$e->errorInfo[1] === 1062`, parse the index name from `errorInfo[2]` only, and return a marker `['__unique_violation' => $index]`; treat a `0` return (test seams) the same way with index null (research A3)
- [x] T014 [US2] In app/Services/TransactionIngestService.php add `classifyUniqueViolation(?string $index, array $parent): array`, called AFTER the transaction closure returns the marker (research A1/A2), using ordered lookups: (1a) same tenant + transaction_id (today's query) → already_processed; (1b) only if none, transaction_id alone `->select('id','tenant_id')->first()` → failed/cross_tenant_transaction_id_collision (warning with both tenant ids, no foreign row id returned, Metrics::incr('ingest.cross_tenant_collision_count')); (2) findReceiptConflict + receiptConflictResult; (3) failed/unique_constraint_violation with details = index name; remove the "insert ignored but no existing transaction found" branch; also change the outer catch's log to exception class + SQLSTATE + driver code instead of getMessage() and payload (research A4)
- [x] T015 [US2] Confirm app/Jobs/ProcessTransactionIntakeJob.php maps the new failed messages to FAILED_PERMANENT with last_error_code = message and already_processed/duplicate as today (no code change expected; add assertions to T011 if needed)
- [x] T016 [US2] Run T003, T011, T012, existing tests/Unit/Services/*Ingest* and tests/Feature/*Intake*; Pint

## Phase 5: User Story 4 - Circuit-breaker accounting (P2)

**Goal**: The breaker sees only infrastructure outcomes; job successes no longer force-close it.
**Independent test**: tests/Unit/Services/TransactionIngestCircuitBreakerTest.php passes.

- [x] T017 [P] [US4] Rewrite tests/Unit/Services/TransactionIngestServiceCircuitBreakerTest.php::test_real_ingest_success_genuinely_invokes_record_success to assert the new behavior (research A6) and add cases there (no new file): with the ingestion breaker open or half-open, a successful ingest leaves the state unchanged; a data-level failure records neither success nor failure and increments ingest.data_failure_count; an infrastructure failure records a failure as today; closed-state success remains a no-op
- [x] T018 [US4] In app/Services/TransactionIngestService.php remove both `$circuitBreaker->recordSuccess()` calls (receipt pre-check and post-transaction), keep `recordFailure()` for infrastructure failures, add Metrics::incr('ingest.data_failure_count') for data-level exceptions and classified failures; add a doc comment citing research.md R3
- [x] T019 [US4] Run T017, tests/Feature/CircuitBreaker*, tests/Unit/*CircuitBreaker*, T003; Pint

## Phase 6: User Story 6 - Missing net sales never become zero (P3)

**Goal**: FR-020.
**Independent test**: two cases in IngestOutcomeClassificationTest.

- [x] T020 [P] [US6] Add tests to tests/Feature/Ingestion/IngestOutcomeClassificationTest.php: payload without net_sales → failed/invalid_payload, no row; net_sales = 0 → accepted with 0.00
- [x] T021 [US6] In app/Services/TransactionIngestService.php add 'net_sales' to the required-field list in normalizePayload() and change `(float) ($payload['net_sales'] ?? 0.0)` to `(float) $payload['net_sales']`
- [x] T022 [US6] Run T020, T003; Pint

## Phase 7: User Story 7 - Logs carry references, not payloads (P3)

**Goal**: FR-021, SC-006; also carries the correlation ID (FR-012).
**Independent test**: log-capture assertions in IngestOutcomeClassificationTest.

- [x] T023 [P] [US7] Add log-capture tests (Log::spy) to tests/Feature/Ingestion/IngestOutcomeClassificationTest.php for the invalid-payload, receipt-duplicate, cross-tenant and ingest-failed paths: entries contain tenant_id, terminal_id, transaction_id, correlation_id and contain no 'payload', 'original_payload' or full row keys
- [x] T024 [US7] In app/Services/TransactionIngestService.php change `ingest(array $payload)` to `ingest(array $payload, ?string $correlationId = null)`, add a private `logRef(array $data, ?string $correlationId): array`, and replace every `'payload' => $payload` and whole-`$parent` log context with it
- [x] T025 [US7] In app/Jobs/ProcessTransactionIntakeJob.php pass `$traceId` as the second argument to both `$ingestService->ingest(...)` calls, and in app/Console/Commands/ReconcileStrandedIntake.php (~line 276) pass the intake's trace_id (research A10)
- [x] T026 [US7] Run T023, T003, T011; Pint

## Phase 8: User Story 5 - Timestamp sanity and late flag (P2)

**Goal**: FR-017..FR-019, SC-005.
**Independent test**: tests/Feature/Ingestion/IntakeTimestampSanityTest.php passes; T003 still passes.

- [x] T027 [P] [US5] Write tests/Feature/Ingestion/IntakeTimestampSanityTest.php per contracts/intake-timestamp-rejections.md, run in both modes (research A7): in `observe` (default) every case below is accepted as today and increments intake.timestamp_would_reject.{code}; in `enforce` the cases below apply. Also: a payload with another structural error plus a bad timestamp keeps STRUCTURAL_VALIDATION_FAILURE; a timestamp failing the format regex triggers no parse. Cases: `0000-00-00T00:00:00Z`, `2026-02-30T10:00:00Z`, `2019-12-31T23:59:59Z` → 422 INVALID_TRANSACTION_TIMESTAMP with a REJECTED intake row; now + 25h → 422 FUTURE_TRANSACTION_TIMESTAMP; now + 23h → accepted; now − 30 days → accepted, intake.late_count incremented; now → accepted unchanged; cover both provider timezone modes
- [x] T028 [US5] Add `timestamps.mode` (observe|enforce, default observe) to config/ingestion.php. In app/Services/TransactionIntakeService.php extend validateNestedTransactionContent(): only for strings matching the format regex, check raw Y/m/d/H/i/s and checkdate() before calling ProviderTimestampNormalizer, wrap parsing in try/catch, then apply earliest/future rules; in enforce mode add the errors and codes above, in observe mode only log + metric; ensure the rejection path persists the specific code via persistRejection() and returns it as error_code in the existing 422 shape (structural failures keep STRUCTURAL_VALIDATION_FAILURE)
- [x] T029 [US5] In app/Services/TransactionIntakeService.php, on the accepted path, compute lag = received_at − normalized transaction timestamp; when above the late threshold increment intake.late_count, add Metrics::bucket('intake.arrival_lag_seconds', lag) and include late/lag_seconds in the existing accept log context
- [x] T030 [US5] Run T027, T003, tests/Feature/*Intake*, tests/Feature/*Official*; Pint

## Phase 9: User Story 3 - Rejection observability and alerting (P2)

**Goal**: FR-009..FR-011, SC-003.
**Independent test**: tests/Feature/Ingestion/IntakeRejectionMonitorTest.php passes.

- [x] T031 [P] [US3] Write tests/Feature/Ingestion/IntakeRejectionMonitorTest.php: rejection counters by code/tenant/terminal increment on persistRejection; ≥ threshold rejections in window → one IntakeRejectionAlert (Notification::fake) naming tenant, terminal, code, count; second run within cooldown → no new alert; below threshold → none; terminal with rejections and no accepted transaction in 24h → alert; terminal with recent accepted sales → none; monitor disabled by config → none; DB error inside check is logged and does not throw
- [x] T032 [P] [US3] Create app/Notifications/IntakeRejectionAlert.php mirroring app/Notifications/TenantInactivityAlert.php channels and payload style
- [x] T033 [US3] In app/Services/TransactionIntakeService.php persistRejection() add Metrics::incr for intake.rejected.code.{code}, intake.rejected.tenant.{tenant_id}, intake.rejected.terminal.{terminal_id}.{code}
- [x] T034 [US3] Create app/Services/Monitoring/IntakeRejectionMonitor.php with check(): windowed grouped query on transaction_intake (intake_status = REJECTED, received_at ≥ now − window) with a MAX_EXECUTION_TIME hint on MySQL; no-accepted-sales query as ONE transaction_intake range scan on received_at over the last 24h grouped by tenant/terminal and intake_status (research A8; verify idx_intake_status_received exists on staging first); RateLimiter cooldown per terminal+code; delivery through the same admin recipients NotificationService uses plus SystemLog and Log::warning; catch and log all exceptions
- [x] T035 [US3] Schedule the monitor in routes/console.php every fifteen minutes, named intake-rejection-monitor, withoutOverlapping()->onOneServer(), guarded by config('ingestion.rejection_alerts.enabled')
- [x] T036 [US3] Run T031, T003, `php artisan schedule:list`; Pint

## Phase 10: Polish & cross-cutting

- [x] T037 Full suite run to tmp/after/junit.xml; diff failures against tmp/baseline/failing.txt; every difference must be a test removed in T009 or a pre-existing failure now passing; record the result in specs/003-ingestion-hardening/baseline.md
- [x] T038 [P] Documentation sync: docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md (job no longer records success), docs/LICENSE_ROUTE_CLASSIFICATION.md (batch → deprecated-or-remove, 410), docs/INGESTION_QUARANTINE_README.md if it references batch, provider-facing docs under resources/js that mention /transactions/batch, and a new rejection-alert section in docs/REPORTING_RUNBOOK.md or the circuit-breaker runbook; .env.example gains the new keys (commented defaults); runbook note: switching `INGESTION_TS_MODE` or alert settings needs `php artisan config:cache` plus a PHP-FPM reload and `php artisan horizon:terminate` when config is cached; release note: in observe mode a zero-date payload is still accepted at intake but now ends FAILED_PERMANENT/ingest_failed instead of being stored with coerced values
- [x] T039 `./vendor/bin/pint --test` clean on changed files; prepare commit groups (one per phase) for Git Workflow Master

## Dependencies

- Phase 1 → Phase 2 → all stories.
- US1 (Phase 3) is independent of the others.
- US2 → US4 → US6 → US7 are serialized (same file).
- US5 and US3 both edit TransactionIntakeService.php → serialized (US5 then US3), and both independent of US2..US7.
- Phase 10 after all stories.

## Parallel opportunities

- T002 with T003; T006 with T005; test-writing tasks marked [P] can be drafted while the preceding implementation task is in review.
- US1 can be developed in parallel with US2 in separate worktrees if needed (different files), but the slice loop runs them one at a time.

## Implementation strategy

MVP = Phase 2 + US1 + US2 (the two P1 stories): closes the bypass and fixes the false permanent failures. Each later phase is a separate reviewable commit group, so delivery can stop after any phase with a truthful status line.
