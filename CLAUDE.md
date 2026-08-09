# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

TSMS is a Laravel 11 (PHP 8.2+) backend paired with a client-rendered React SPA (Vite, MUI, react-router, react-query — not Inertia) for managing POS transaction ingestion, circuit-breaker/backpressure-protected intake, sharded Horizon queue processing, licensing-gated routes, and sales/finance reporting across multiple tenants (companies/stores/POS terminals).

## Commands

Setup:
```bash
composer install
npm ci
cp .env.example .env && php artisan key:generate
php artisan migrate
```

Local dev:
```bash
php artisan serve
npm run dev        # Vite, separate process
```

Tests (PHPUnit, suites defined in `phpunit.xml` as `Unit`/`Feature`):
```bash
php artisan test                                   # full suite
php artisan test tests/Feature/SomeTest.php         # single file
php artisan test --filter=test_method_name          # single test
```
Local convention for running against the dedicated test database:
```bash
DB_DATABASE=tsms_db_test php artisan migrate:fresh --env=testing --force
DB_DATABASE=tsms_db_test php artisan test tests/Unit/Services/SomeTest.php
```
CI (`.github/workflows/ci.yml`) instead does `cp .env.testing .env` against a fresh MySQL service container, then `php artisan migrate --force && php artisan test`.

Lint/format (Pint, default Laravel preset — no `pint.json`):
```bash
./vendor/bin/pint          # fix
./vendor/bin/pint --test   # check only, no changes
```

Frontend build:
```bash
npm run build
```

CI gates to know about:
- PR title or body must reference a work ID matching `TSMS-\d{3,}` or `LRC-\d{2,}` (enforced by the `work-item` job).
- `rollback-branch-guard` job runs `scripts/verify-rollback-branch.sh`, which fails the PR if `origin/remove-webapp-forwarding` has moved past the commit recorded in `specs/001-100-tenant-resilience/rollback-baseline.txt`.

## Architecture

### Transaction ingestion pipeline
POS payload → `TransactionController::storeOfficial()` (behind `circuit.breaker:transaction-intake` + `ingestion.backpressure:processing` middleware) → `App\Services\TransactionIntakeService::handleOfficialIntake()` validates/checksums/dedupes, writes a `TransactionIntake` row, and routes it to a sharded intake queue via `IngestionQueueRouter`. `App\Jobs\ProcessTransactionIntakeJob` (`transaction-intake:s{N}` queue) calls `App\Services\TransactionIngestService::ingest()` (atomic insert + `DeadlockRetryService`) against `App\Models\Transaction`/`TransactionIdentity`. Downstream processing/forwarding runs via `App\Jobs\ProcessTransactionJob` on `transaction-processing:s{N}` queues using `TransactionValidationService`/`TransactionProcessingService`/`JobProcessingService`. Failed/invalid payloads land in `App\Models\IngestionQuarantine` (`IngestionQuarantine{List,Show,Replay}` commands — see `docs/INGESTION_QUARANTINE_README.md`). Submission-level audit events use `App\Models\SubmissionEvent` (`docs/TRANSACTION_LOGS_GUIDELINES.md`).

### Circuit breaker & backpressure
Two independent overload guards on the ingestion routes, authoritatively documented in `docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md`. `App\Services\CircuitBreaker` is a Redis-hash closed/open/half-open state machine tripped by consecutive downstream failures (`CircuitBreakerMiddleware`). `App\Services\IngestionBackpressureService` rejects/degrades when a Redis queue's ready-depth exceeds a threshold (`IngestionBackpressureMiddleware`, also called internally by `TransactionIntakeService`). Several legacy `App\Models\CircuitBreaker`-based controllers/dashboards look similar but are non-authoritative — don't cite them.

### Queue / Horizon topology
Redis-backed (`config/queue.php`), organized into named Horizon supervisors in `config/horizon.php`: `intake-supervisor` (`transaction-intake:s{0..N-1}` plus a dedicated `s-vip` shard for pilot tenants), `high-supervisor`/`processing-supervisor` (`transaction-processing:s{N}`), `reporting-supervisor` (`reporting` queue, read-replica DB — `docs/HORIZON_REPORTING_SETUP.md`), plus `low`, `notifications`, `webhook-supervisor`. Shard count is controlled by `TSMS_PROCESSING_SHARD_COUNT`/`TSMS_INTAKE_SHARD_COUNT`, routed by `App\Services\IngestionQueueRouter::shardIndex()` (`crc32(tenantId) % shardCount`). `App\Console\Commands\VerifyShardTopology` is the read-only tool for safely resizing shard count (`docs/SHARD_COUNT_CHANGE_RUNBOOK.md`). There is no database sharding — sharding is queue/Redis-only, keyed by tenant.

### Licensing / route classification
Newer subsystem gating business routes behind license validity, currently rolling out in `observe` mode starting with `/api/v1/transactions/official|batch`. Core services in `app/Services/Licensing/` (`LicenseService`, `SignedLicenseReader`, `LicenseValidationResult`, `DeploymentFingerprintService`, `LicenseRecoveryRequestService`, `LicenseReplacementService`, `LicenseAuditLogger`), enforced by `App\Http\Middleware\LicenseMiddleware`/`EnsureVendorLicenseAuthority` (`license.valid`/`license.vendor`), backed by `App\Models\LicenseAuditLog`/`DeploymentMetadata`. `docs/LICENSE_ROUTE_CLASSIFICATION.md` is the authoritative route-by-route classification (`public`/`auth-only`/`license-diagnostic`/`license-protected`/`deprecated-or-remove`).

### Reporting
Hourly/daily/weekly sales & finance aggregates computed against a read-replica DB connection on the dedicated `reporting` Horizon queue. Key files: `app/Services/Reports/{DailyReportService,HourlyReportService,WeeklyReportService,SalesReportDataService,FinanceCalculationService}.php`, refresh jobs `app/Jobs/Reporting/{RefreshHourlyWindowJob,InvalidateCountCacheJob}.php`, commands `ReportingRefreshCommand`/`ReportingDispatchCommand` (`docs/HORIZON_REPORTING_SETUP.md`, `docs/REPORTING_RUNBOOK.md`). A separate `app/Services/Security/*` set produces security/compliance reports.

### Multi-tenancy
`App\Models\Tenant` (with `Company`, `Store`, `PosTerminal` relations) is the tenant root. Tenancy threads through ingestion (queue sharding by `tenant_id`, pilot/VIP carve-out), backpressure (per-tenant queue depth), licensing (tenant/deployment binding on `POST/PUT /api/tenants/*`), and auditing (`TenantIngestionAuditService`). The circuit breaker itself is currently a single shared `transaction-intake` breaker, not per-tenant.

### API surface & routing
- `routes/api.php` — two audiences under `sanctum` guard: an internal dashboard/admin API (`auth:sanctum` + spatie `role:` middleware — tenants, users, metrics, monitoring, corrections), and the POS/provider ingestion API under `v1/` (`POST /v1/auth/terminal` is public; `/v1/transactions/{batch,official}` etc. require Sanctum ability checks and are stacked with `ingestion.payload_size`, `ingestion.backpressure`, `circuit.breaker:transaction-intake`, `ingestion.fairness`, `license.valid`). Conditionally `require`s `routes/webapp_api.php` when `webapp_api.enabled`.
- `routes/webapp_api.php` — feature-flagged, read-only `v1/webapp/*` reporting API guarded by `ensure.webapp.token` + `throttle:webapp`.
- `routes/web.php` — session-authenticated; nearly every route just returns `view('app')` (the SPA shell) or `Route::fallback` does; a few real JSON endpoints are proxied through web-guard controllers gated by `role:`.
- `routes/transaction.php` — **not registered anywhere** (not required in `bootstrap/app.php` or any provider); orphaned, superseded by the `v1/` routes in `api.php`. Don't extend it.

### Auth & authorization
Two Sanctum guards: `web` (session, `App\Models\User`, spatie `HasRoles` — roles enforced via `role:` middleware) for staff/dashboard, and `pos_api`/token abilities (`transaction:create`, `transaction:read`, `heartbeat:send`, `admin:manage`, `provider:testing`) for `App\Models\PosTerminal`.

### Frontend wiring
`resources/views/app.blade.php` is the single Blade shell (loads `resources/js/app.jsx` via `@vite`, embeds `window.authUser` and `window.config.api_base`). `resources/js/app.jsx` mounts React (`createRoot`) and owns all client routing via `react-router-dom` `BrowserRouter`, wrapped in `QueryClientProvider` and `AuthProvider`; route access is enforced client-side via `ProtectedRoute roles={[...]}`. API calls go through `resources/js/bootstrap.js` (axios, CSRF header from the blade meta tag, response interceptor redirects to `/login` on HTML/401/419) and per-domain modules in `resources/js/services/*.js` calling relative `/api/...` paths.

## Agent Orchestration

This repository uses a gated multi-agent workflow for safe brownfield feature delivery, with `docs/agent-orchestration/` as the source of truth:

- `workflow.md` — the full gated flow (intake → architecture/impact review → baseline → slice loop → docs sync → pre-push audit → merge-readiness) and risk-based variants.
- `agent-matrix.md` — responsibilities for each custom agent.
- `harness-contract.md` — how to validate agent invocation names, foreground/SendMessage continuity rules.
- `model-routing.md` — which model tier to use per risk gate.
- `read-only-policy.md` — how review gates enforce (or verify) that they made no repository changes.
- `prompt-library.md` / `checklists.md` / `status-gates.md` — ready-to-use prompts, per-phase checklists, and standard status codes.
- `token-optimization.md` — context budgeting, prompt compaction, delta-only follow-ups, and other token-efficiency rules for multi-agent execution.

Custom agents (invoke by the exact frontmatter `name:` value, not the filename): `Software Architect`, `Senior Developer`, `Code Reviewer`, `Git Workflow Master`. The main thread is always the orchestrator — it owns approval gates and must not pass a subagent verdict through uncritically.

Core priorities: preserve existing behavior; keep each agent on one responsibility; verify with targeted evidence; maintain reviewable commits; require explicit approval before remote or destructive actions.

**Delivery status language** — never overstate completion. Use e.g. `US1–US4 complete and validated; US5 deferred.` Do not say "feature complete" while planned work remains deferred.

**Autonomous execution boundaries** — for an approved feature plan, the orchestrator may run the slice loop from `workflow.md` autonomously within the current phase, subject to:
- *Gate 0 (human-readable shorthand, not a status code)* — implementation may not start until `ARCHITECTURE_APPROVED`, `IMPACT_ANALYZED`, `BASELINE_RECORDED`, and `READY_TO_IMPLEMENT` have all been emitted (`docs/agent-orchestration/status-gates.md`). Do not invent `APPROVED`, `NOT_READY`, or `GATE_0_APPROVED` statuses — `GATE_0_APPROVED` does not exist unless `status-gates.md` is updated to add it as part of the same change.
- *Per-work-unit loop* — foreground implementation agent → targeted tests, relevant full regression checks, and Pint → foreground Code Reviewer (`SendMessage` back to the same developer agent on findings, repeat until `REVIEW_PASS`) → for high-risk work (`workflow.md`'s High-Risk Gates list), a fresh foreground Architect Agent drift revalidation to `ARCHITECTURE_CONSISTENT`, checking for architecture drift, invalidated assumptions, new dependency/sequencing conflicts, and violations of approved invariants. This revalidation mirrors the mandatory P→Q→R loop in `workflow.md`: it cannot be skipped, inferred from the reviewer result, or replaced by it. A slice counts as ready only once `REVIEW_PASS` — and, for high-risk work, `ARCHITECTURE_CONSISTENT` — has been emitted; tests/review/scope/file checks alone are not sufficient.
- *Continue automatically* to the next work unit only when all targeted checks pass, no blocker or high-severity review finding remains, drift revalidation (where required) came back clean, no unrelated files are included, and no user/business decision is required.
- *Documentation Sync before commit-group prep* — once every work unit in the phase has passed its required review (and, where applicable, revalidation), run Documentation Sync per `workflow.md`, reconciling the implementation with the approved plan, architecture notes, runbooks, and status records, before preparing the commit group (target `READY_FOR_COMMIT`). Skip only with explicit justification that it doesn't apply.
- *Stop and ask* when architecture or scope must change, a product/business rule is unresolved, authorization or tenant-isolation policy is unclear, a baseline failure can't be classified, Redis/Lua atomicity is uncertain, or a destructive Git action is needed.

This loop governs implementation, local testing, review, revalidation, and local commit preparation only. It does not override or weaken the Remote action gates below.

**Remote action gates** — the following always require explicit user authorization: push, PR creation, PR close/reopen, merge, branch deletion, force-push, history rewrite, branch-protection or workflow-policy changes. Approval for one action does not imply approval for the next.
