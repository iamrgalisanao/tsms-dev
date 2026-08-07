# Tasks: 100 Tenant Ingestion Resilience

**Input**: Design documents from `specs/001-100-tenant-resilience/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

**Tests**: Required for each release-blocker remediation before implementation is considered complete.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing.

## Phase 1: Setup and Baseline

**Purpose**: Establish visibility and protect current fallback state before changing ingestion flow.

- [ ] T001 Document current official and batch ingestion flow in `specs/001-100-tenant-resilience/research.md`
- [ ] T002 [P] Add baseline log/metric inventory for official and batch ingestion in `specs/001-100-tenant-resilience/contracts/operational-signals.md`
- [ ] T003 [P] Confirm rollback/fallback branch reference in deployment notes or runbook under `docs/`
- [x] T003a [P] Add fallback-branch integrity guard script that records the expected `origin/remove-webapp-forwarding` commit SHA in `specs/001-100-tenant-resilience/rollback-baseline.txt`, fails if the current remote fallback tip differs from that SHA unless the baseline is intentionally updated, and verifies the recorded baseline is an ancestor of the release branch, in `scripts/verify-rollback-branch.sh`
- [x] T003b [P] Register the fallback-branch integrity guard as a blocking pull-request job in `.github/workflows/ci.yml` with `fetch-depth: 0` and document usage plus baseline-update procedure in `specs/001-100-tenant-resilience/quickstart.md`
- [x] T004 [P] Add focused test plan references for current backpressure and routing foundation in `specs/001-100-tenant-resilience/quickstart.md`

---

## Phase 2: Foundational Remediation

**Purpose**: Shared primitives required by all resilience stories.

- [ ] T005 Add config entries for ingestion payload limit, batch limit, enforce/degraded mode, fairness limits, and circuit breaker Redis backend in `config/tsms.php`
- [ ] T006 [P] Add/adjust queue router architecture test to prevent hardcoded `% 8` dispatch paths in `tests/Unit/`
- [ ] T007 [P] Add shared ingestion response/rejection contract tests in `tests/Feature/`
- [x] T008 Add migration for durable ingestion request state in `database/migrations/`
- [x] T009 Adapt the existing `TransactionIntake` model in `app/Models/TransactionIntake.php` and its `transaction_intake` schema migrations; do not create a parallel ingestion request model/table unless a later architecture decision explicitly replaces `transaction_intake`
- [x] T009a [P] Correct `specs/001-100-tenant-resilience/data-model.md` so the current persisted Ingestion Request entity matches the real `transaction_intake` schema (`payload_checksum`, `trace_id`, split `intake_status`/`processing_status`, `attempt_count`, `duplicate_of_intake_id`, `source_ip`); move router-computed `queue`/`shard` to `contracts/operational-signals.md`, keep `source`/`accepted_at` only as future-schema candidates, and flag the `X-Request-Id` versus `X-Correlation-ID` header divergence as an open design question
- [ ] T010 Add shared DTO/result object for backpressure decisions in `app/Services/`
- [ ] T011 Add correlation ID propagation for ingestion requests in `app/Http/Middleware/` or existing request handling path

**Checkpoint**: Foundation ready; user stories can proceed.

---

## Phase 3: User Story 1 - Protect Official Intake During Overload (Priority: P1) MVP

**Goal**: Official ingestion rejects or accepts durably before expensive DB validation/write work.

**Independent Test**: Saturate queue/backpressure and prove `/api/v1/transactions/official` rejects before DB-backed terminal existence validation or transaction persistence.

### Tests

- [x] T012 [P] [US1] Add feature test proving official overload gate runs before FormRequest `exists` validation in `tests/Feature/IngestionBackpressureTest.php`
- [x] T013 [P] [US1] Add feature test for async official intake `202 Accepted` and dispatch-after-commit in `tests/Feature/OfficialAsyncIntakeTest.php`
- [x] T014 [P] [US1] Add idempotency/conflict tests for `submission_uuid` and payload hash in `tests/Feature/OfficialAsyncIntakeIdempotencyTest.php`

### Implementation

- [x] T015 [US1] Add lightweight official intake request or middleware that performs cheap structural checks before DB validation in `app/Http/Requests/` or `app/Http/Middleware/`
- [x] T016 [US1] Implement official async intake boundary service in `app/Services/`
- [x] T017 [US1] Update official route/controller flow in `routes/api.php` and `app/Http/Controllers/API/V1/TransactionController.php`
- [x] T018 [US1] Persist durable intake state and dispatch intake job after commit in `app/Services/` and `app/Jobs/`
- [x] T019 [US1] Preserve compatibility response/status lookup behavior in `app/Http/Controllers/API/V1/`
- [x] T019a [P] [US1] Test unified 409 `IDEMPOTENCY_CONFLICT` shape across different-terminal submission UUID conflict, same-terminal payload-drift conflict, and intake-layer duplicate cases in `tests/Feature/OfficialAsyncIntakeIdempotencyTest.php`
- [x] T019b [US1] Reconcile submission UUID conflict responses onto the contract's `IDEMPOTENCY_CONFLICT` shape while preserving the existing separate `DUPLICATE_RECEIPT_CONFLICT` and `SUBMISSION_ALREADY_REJECTED` semantics in `app/Http/Controllers/API/V1/TransactionController.php`, `app/Services/TransactionIntakeService.php`, and `specs/001-100-tenant-resilience/contracts/ingestion-api.md`
- [x] T018a [US1] Fix `ProcessTransactionIntakeJob` batch-item orchestration so later item failures cannot strand earlier committed-but-undispatched transactions; dispatch each successful item's `ProcessTransactionJob` immediately after its successful `ingest()` result, continue through item failures, reserve `FAILED_PERMANENT` for zero-success batches, and cover with `tests/Feature/ProcessTransactionIntakeJobBatchFailureTest.php`
- [x] T019c [US1] Handle concurrent duplicate `transaction_intake.submission_uuid` insert races in `TransactionIntakeService::handleOfficialIntake()` by catching unique-constraint failures and resolving through the existing 202/409 idempotency response path in `tests/Feature/OfficialAsyncIntakeIdempotencyTest.php`
- [x] T019d [US1] Restore adjustment/tax child shape and required type-presence validation in `TransactionIntakeService::officialStructuralRules()` and its validation after-hook, covering malformed details without accepting a queued intake
- [x] T019e [US1] Restore registered-vs-submitted hardware ID mismatch validation in `TransactionIntakeService`, preserving `403 HARDWARE_ID_MISMATCH` for single and batch official intake payloads
- [x] T020 [US1] Add reconciliation handling for accepted-but-not-queued/queued-but-not-processed intake states in `app/Console/Commands/`
- [x] T020a [P] [US1] Test p95 transaction duration and prove the outer official ingestion transaction is not held open across non-atomic validation, logging, notification, or per-item work in `tests/Feature/OfficialIngestionTransactionBoundaryTest.php`
- [x] T020b [US1] Split the outer `storeOfficial` DB transaction so request-scope work, validation, logging, notifications, and per-item writes do not hold one long transaction open, extending the existing per-item savepoint pattern in `app/Http/Controllers/API/V1/TransactionController.php` or its Phase 1 replacement service
- [x] T020c [US1] Add or confirm indexes for tenant, terminal, external transaction ID, receipt/date, status, and timestamp lookups used by the shortened official ingestion path in `database/migrations/`

**Checkpoint**: Official ingestion no longer depends on the large synchronous write path for request acceptance.

---

## Phase 4: User Story 2 - Bound Payload, Batch, and Transaction Work (Priority: P1)

**Goal**: Reject oversized work before memory, validation, or DB fanout becomes dangerous.

**Independent Test**: Over-limit payloads and batches are rejected before persistence; boundary-limit requests proceed.

### Tests

- [x] T021 [P] [US2] Add payload byte limit tests in `tests/Feature/IngestionPayloadLimitTest.php`
- [x] T022 [P] [US2] Add batch transaction count limit tests in `tests/Feature/IngestionBatchLimitTest.php`
- [x] T023 [P] [US2] Add boundary tests for exactly max payload and max batch count in `tests/Feature/IngestionLimitBoundaryTest.php`

### Implementation

- [x] T024 [US2] Add early payload-size middleware in `app/Http/Middleware/`
- [x] T025 [US2] Register payload-size middleware on ingestion routes in `routes/api.php` or route middleware configuration
- [x] T026 [US2] Add batch count validation before per-item loops in `app/Http/Controllers/API/V1/TransactionController.php` or async intake validator
- [x] T027 [US2] Add stable error codes for `PAYLOAD_TOO_LARGE` and `BATCH_LIMIT_EXCEEDED` in ingestion response helpers

**Checkpoint**: A single oversized request cannot monopolize request/DB resources.

---

## Phase 5: User Story 3 - Shared Failure Controls and Safe Backpressure (Priority: P1)

**Goal**: Backpressure and circuit breaker decisions are shared and fail safely.

**Independent Test**: Simulate Redis/dependency failure and confirm shared breaker state plus bounded rejection in enforce mode.

### Tests

- [ ] T028 [P] [US3] Add unit tests for Redis circuit breaker closed/open/half-open transitions in `tests/Unit/`
- [ ] T029 [P] [US3] Add feature test for Redis/backpressure unavailable in enforce mode in `tests/Feature/IngestionBackpressureFailureModeTest.php`
- [ ] T030 [P] [US3] Add tests proving validation `4xx` failures do not open breaker state in `tests/Feature/IngestionCircuitBreakerTest.php`
- [ ] T031 [P] [US3] Add response consistency test for clamped retry header/body in `tests/Feature/IngestionBackpressureTest.php`

### Implementation

- [ ] T032 [US3] Replace or extend local circuit breaker service with Redis-backed state in `app/Services/CircuitBreaker.php`
- [ ] T033 [US3] Record breaker success/failure around ingestion dependencies in `app/Http/Middleware/CircuitBreakerMiddleware.php` and ingestion services/jobs; no ingestion code path currently records outcomes against this breaker
- [ ] T034 [US3] Update `IngestionBackpressureService` to return fail-closed/degraded decisions in enforce mode in `app/Services/IngestionBackpressureService.php`
- [ ] T034a [US3] Define and implement the official ingestion backpressure aggregation policy for intake and processing queues in `rejectWhenProcessingBackpressureEnforced()` or its Phase 1 replacement: after T034, when backpressure is enabled and in enforce mode, reject if either queue is overloaded, return degraded if either required health check cannot be evaluated, exclude disabled-mode decisions from degraded aggregation, and include both `backpressure.intake` and `backpressure.processing` sub-decisions in response/log context
- [ ] T035 [US3] Expose one clamped retry value for both JSON body and `Retry-After` header in backpressure response code
- [ ] T036 [US3] Add breaker/backpressure runbook in `docs/`

**Checkpoint**: Overload control protects the system consistently across app instances.

---

## Phase 6: User Story 4 - Fair Multi-Tenant Queue Processing (Priority: P2)

**Goal**: Ensure deterministic routing, isolated workers, and tenant/terminal fairness.

**Independent Test**: One hot tenant cannot starve 99 normal tenants in staging.

### Tests

- [ ] T037 [P] [US4] Add architecture/static test for no hardcoded `% 8` dispatch paths in `tests/Unit/`
- [ ] T038 [P] [US4] Add feature tests for tenant and terminal fairness rejections in `tests/Feature/IngestionFairnessTest.php`
- [ ] T039 [P] [US4] Add Horizon config characterization test for separate staging supervisors in `tests/Feature/`

### Implementation

- [ ] T040 [US4] Replace `% 8` routing in `app/Http/Controllers/TestTransactionController.php`
- [ ] T041 [US4] Replace `% 8` routing in `app/Http/Controllers/API/V1/RetryHistoryController.php`
- [ ] T042 [US4] Replace `% 8` routing in `app/Jobs/BulkGenerateTransactionsJob.php`
- [ ] T043 [US4] Replace `% 8` routing in `app/Console/Commands/TestTransactionPipeline.php`
- [ ] T044 [US4] Implement Redis global/tenant/terminal fairness service in `app/Services/`
- [ ] T045 [US4] Apply fairness before enqueue in official and batch ingestion paths in `app/Http/Controllers/API/V1/` or ingestion middleware
- [ ] T046 [US4] Split staging Horizon supervisors for processing, low, notifications, and reporting in `config/horizon.php`; depends on T020b transaction splitting passing validation before processing worker capacity is increased
- [ ] T047 [US4] Document shard-count change/remapping risk in `docs/`

**Checkpoint**: Queue processing locality and tenant fairness are consistent enough for controlled 100-tenant load.

---

## Phase 7: User Story 5 - Operational Readiness for 100-Tenant Load Test (Priority: P2)

**Goal**: Make staging failures diagnosable, alertable, and reversible.

**Independent Test**: Dashboard/alert drills pass before load test.

### Tests and Drills

- [ ] T048 [P] [US5] Add synthetic metric/log emission tests where practical in `tests/Feature/`
- [ ] T049 [US5] Run manual staging alert drill for queue age, DB pressure, breaker open, Redis unavailable, and tenant skew
- [ ] T050 [US5] Run failed-job replay drill using reconciliation command and documented runbook
- [ ] T051 [US5] Run hot-tenant plus 99 normal tenants staging load test and capture results in `docs/`

### Implementation

- [ ] T052 [US5] Emit required operational log context from ingestion services/jobs in `app/Services/`, `app/Jobs/`, and controllers
- [ ] T053 [US5] Add metrics for request rate, latency, rejection reason, queue depth/age, worker drain, DB pressure, breaker state, and tenant skew in `app/Support/` or configured metrics sink
- [ ] T054 [US5] Create dashboards or dashboard definitions for required signals under `docs/` or observability configuration
- [ ] T055 [US5] Create alert definitions or alert documentation for required thresholds under `docs/`
- [ ] T056 [US5] Create operational runbooks for overload, breaker, Horizon scaling, failed-job replay, tenant throttling, Redis degradation, and rollback under `docs/`

**Checkpoint**: Staging load test is observable, actionable, and reversible.

---

## Final Phase: Release Gate and Cleanup

- [ ] T057 Run full focused resilience test suite and document results in `docs/`
- [ ] T058 Run route/provider/license regression tests to protect unrelated behavior
- [ ] T059 Validate no P0/P1 resilience findings remain against `specs/001-100-tenant-resilience/spec.md`
- [ ] T060 Confirm `remove-webapp-forwarding` fallback branch remains untouched and rollback path remains usable
- [ ] T061 Prepare final architecture readiness review before production-like release

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 Setup**: No dependencies.
- **Phase 2 Foundation**: Depends on setup.
- **US1, US2, US3**: Can begin after foundation, but US1 should be MVP priority.
- **US4**: Can begin after queue router foundation and should complete before 100-tenant load confidence.
- **US5**: Starts early for baseline, completes after all operational signals are implemented.
- **Final Release Gate**: Depends on selected stories and staging drills.
- **US1 batch partial-failure safety**: T020, T020a, T020b, and T020c depend on T018a so reconciliation and transaction-boundary work build on the corrected async batch status/dispatch semantics.
- **Horizon processing capacity increases**: T046 may split supervisors earlier, but worker capacity increases are gated on T020b passing transaction-boundary validation.

### MVP Scope

MVP for controlled staging load readiness is US1 + US2 + US3 with enough US5 observability to diagnose the run.

### Parallel Opportunities

- T002, T003, T003a, T003b, T004 can run in parallel.
- T006, T007, T011 can run in parallel.
- Tests within each user story can be written in parallel.
- US2 payload/batch limits can proceed in parallel with US3 circuit breaker work after foundation.
- US4 hardcoded routing replacements can be split by file.

## Implementation Strategy

1. Preserve current remediation branch and fallback branch safety.
2. Write failing tests for each priority release blocker.
3. Implement the smallest safe change for each story.
4. Run focused tests after each story.
5. Run staging in observe mode before enforce mode.
6. Treat production-like release as blocked until P0/P1 criteria pass.
