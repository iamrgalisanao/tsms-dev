# Quickstart Validation: 100 Tenant Ingestion Resilience

## Purpose

Use this guide to validate the remediation plan in development/staging before a 100-tenant load test or production-like release.

## Prerequisites

- Current branch is `remediate-backpressure-sharding-foundation`.
- Test database is available.
- Redis/Horizon are available for staging validation.
- Backpressure thresholds and modes are configurable per environment.
- Dashboards or log queries exist for required operational signals.

## Local/CI Validation

1. Run focused tests for existing foundation:

   ```bash
   DB_DATABASE=tsms_db_test php artisan test \
     tests/Feature/IngestionBackpressureTest.php \
     tests/Unit/IngestionQueueRouterTest.php \
     tests/Feature/ReconcileStrandedIntakeTest.php
   ```

2. Run route/authorization regression slice:

   ```bash
   DB_DATABASE=tsms_db_test php artisan test \
     tests/Feature/BatchStoreTerminalBindingTest.php \
     tests/Feature/RouteProviderCharacterizationTest.php
   ```

3. Add and run new tests as tasks are implemented:

   - Official overload gate before DB-backed validation.
   - Async intake acceptance and dispatch-after-commit.
   - Payload and batch limit boundaries.
   - Redis/backpressure unavailable in enforce mode.
   - Shared Redis circuit breaker transitions.
   - Tenant/terminal fairness.
   - No hardcoded `% 8` dispatch paths.

4. Run fallback-branch integrity guard after it is implemented:

   ```bash
   scripts/verify-rollback-branch.sh
   ```

   Expected outcome:

   - `origin/remove-webapp-forwarding` tip equals the SHA recorded in `specs/001-100-tenant-resilience/rollback-baseline.txt`.
   - The recorded baseline SHA is an ancestor of the current release branch.
   - The guard fails if the fallback branch has moved unexpectedly.

## Fallback Baseline Update Procedure

Use this only when the team intentionally changes the fallback branch baseline.

1. Fetch full remote history:

   ```bash
   git fetch origin --prune
   ```

2. Verify the intended new fallback tip:

   ```bash
   git rev-parse origin/remove-webapp-forwarding
   ```

3. Confirm the new fallback tip is intentionally approved as the rollback baseline.
4. Update `specs/001-100-tenant-resilience/rollback-baseline.txt` with the exact approved SHA.
5. Run `scripts/verify-rollback-branch.sh` and include the passing output with the pull request or release note.

## Staging Validation

1. Start in observe mode.
2. Confirm dashboards show:

   - API latency p50/p95/p99.
   - Request/rejection rates by tenant and terminal.
   - Intake and processing queue depth per shard.
   - Oldest job age and worker drain rate.
   - DB locks, deadlocks, slow queries, and connection pressure.
   - Circuit breaker state.
   - Tenant skew/top talkers.

3. Run a small multi-tenant smoke test.
4. Enable enforce mode at conservative thresholds.
5. Simulate queue saturation and confirm deterministic rejection.
6. Simulate Redis/backpressure check failure and confirm bounded `503` or configured degraded response.
7. Run hot-tenant scenario: one noisy tenant plus 99 normal tenants.
8. Confirm normal tenants continue to make progress.
9. Drill alert and runbook paths.

## Load Test Exit Criteria

- Queue depth and oldest job age remain bounded.
- API latency remains within selected staging target.
- DB lock waits/deadlocks remain within selected staging target.
- No unrecoverable intake records remain after reconciliation.
- Rejections include machine-readable reason and retry metadata.
- Alerts fire for injected failures.
- Rollback path is documented and has not been invalidated.
