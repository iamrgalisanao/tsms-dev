# Implementation Plan: Ingestion Hardening

**Branch**: `003-ingestion-hardening` | **Date**: 2026-10-26 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/003-ingestion-hardening/spec.md`

## Summary

Close the revalidated weaknesses on the POS ingestion path while leaving the working official intake flow's contract untouched. The legacy batch endpoint is replaced with a 410 retirement handler. `TransactionIngestService` stops using `insertOrIgnore` and classifies duplicate-key outcomes by index. Rejections get per-tenant/terminal metrics and a scheduled, cooldown-limited alert. The circuit breaker stops being force-reset by background job successes. Intake rejects impossible and far-future timestamps and flags late sales. The net-sales zero fallback goes, and ingest logs carry references instead of payloads. No migrations; no production data is rewritten.

## Technical Context

**Language/Version**: PHP 8.2+, Laravel 11

**Primary Dependencies**: Laravel queue/Horizon (Redis), Sanctum, existing `App\Services\CircuitBreaker` (Redis hash + Lua), `App\Support\Metrics`, `App\Support\LogContext`, `App\Services\NotificationService`, `App\Services\ProviderTimestampNormalizer`

**Storage**: MySQL 8; Laravel connection non-strict (`'strict' => false` → session `sql_mode=NO_ENGINE_SUBSTITUTION`; server global is strict but not used by app sessions), Redis (breaker state, metrics, rate limiter)

**Testing**: PHPUnit via `php artisan test`; local test DB `tsms_db_test` on the `tsms-test-mysql` Docker container; Pint

**Target Platform**: Linux server (staging LNX-PTX-POS01), Horizon workers

**Project Type**: Web service (Laravel API + React SPA; SPA untouched)

**Performance Goals**: No added per-request DB queries on the accepted official path beyond one timestamp parse; alert query bounded to one indexed scan of the last alert window

**Constraints**: Preserve official intake responses byte-for-byte for valid input (FR-022); additive-only, no migrations (FR-023); scheduled alert must not run long queries against the 3.4M-row / 8 GB `transaction_intake` table (uses `idx_intake_status_received`)

**Scale/Scope**: ~160–480k transactions/month on staging; ~3.4M intake rows

## Constitution Check

`.specify/memory/constitution.md` is an unfilled template, so there are no constitution gates. The repository's governing rules come from `CLAUDE.md` and `docs/agent-orchestration/`, which this plan applies:

| Rule | Status |
| --- | --- |
| Preserve existing behavior | Pass: FR-022 plus contract characterization tests before changes |
| High-risk gates (financial correctness, state machine, routes) | Applies: full gated path, Architect review before implementation and drift revalidation per slice |
| No destructive data changes | Pass: no migrations, no backfill |
| Remote actions need approval | Pass: plan stops at local commits |

Post-design re-check: pass; no new violations introduced by the design below.

## Project Structure

### Documentation (this feature)

```text
specs/003-ingestion-hardening/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── batch-retirement.md
│   ├── ingest-outcomes.md
│   └── intake-timestamp-rejections.md
└── tasks.md   (next: /speckit-tasks)
```

### Source Code (repository root)

```text
routes/
├── api.php                                   # US1: batch routes → retirement handler
└── console.php                               # US3: schedule rejection monitor
app/Http/Controllers/API/V1/
└── TransactionController.php                 # US1: retiredBatch(); remove batchStore body
app/Services/
├── TransactionIngestService.php              # US2, US4, US6, US7, trace ID (US3)
├── TransactionIntakeService.php              # US3 rejection metrics, US5 timestamp checks
└── Monitoring/IntakeRejectionMonitor.php     # US3 new: scheduled check + cooldown
app/Notifications/
└── IntakeRejectionAlert.php                  # US3 new
app/Jobs/
└── ProcessTransactionIntakeJob.php           # US3 pass correlation ID to ingest; US2 outcome mapping unchanged
config/
└── ingestion.php                             # US3/US5 thresholds (additive keys)
tests/
├── Feature/Ingestion/BatchRetirementTest.php           # US1 new
├── Feature/Ingestion/OfficialIntakeContractTest.php    # characterization (written first)
├── Feature/Ingestion/IngestOutcomeClassificationTest.php  # US2
├── Feature/Ingestion/IntakeTimestampSanityTest.php     # US5
├── Feature/Ingestion/IntakeRejectionMonitorTest.php    # US3
├── Unit/Services/TransactionIngestCircuitBreakerTest.php  # US4
└── (existing batch-route tests retargeted to /official or removed — see tasks)
```

**Structure Decision**: Existing Laravel layout; one new service under `app/Services/Monitoring/`, one new notification, new tests under `tests/Feature/Ingestion/`.

## Delivery order and risk

| Slice | Stories | Risk | Notes |
| --- | --- | --- | --- |
| 0 | Characterization | Medium | Pin current official-intake responses and statuses before any change |
| 1 | US1 batch retirement | High (routes, auth surface) | Retarget middleware tests to `/official` first so coverage is kept |
| 2 | US2 outcome classification | High (financial correctness) | Core ingest change; concurrency test |
| 3 | US4 circuit breaker | High (state machine) | Small diff, Redis-backed tests |
| 4 | US6 + US7 | Medium | Mechanical, in the same file as slice 2 |
| 5 | US5 timestamp sanity | High (changes intake acceptance) | Defaults chosen to avoid rejecting legitimate skew |
| 6 | US3 observability | Medium | Additive; scheduler + notification |

Stop-and-ask triggers: any existing official-intake test changes outcome; any need for a migration; alert recipients/thresholds disputed.

## Complexity Tracking

No violations to justify.
