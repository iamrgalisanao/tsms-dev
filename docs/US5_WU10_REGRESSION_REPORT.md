# US5 WU10 — Final Regression and Rollback Diff Against Gate 0 Baseline

**Work unit**: WU10 of `specs/001-100-tenant-resilience/plan.md`'s "Phase 8 Detailed
Implementation Plan" (T057, T058, T060). Run after WU1–WU9 landed
(`feature/us5-operational-readiness`, HEAD `52f7c320` at the time this report was
generated).

**Purpose**: confirm nothing regressed relative to the Gate 0 baseline recorded
before any WU1 code change — not merely that the suite is green today. A diff
against a recorded baseline, not a fresh pass in isolation, is what this document
reports.

## Method

The exact same commands run for Gate 0 were re-run here, and the resulting failure
sets were diffed line-for-line against the Gate 0 output, not just compared by
count:

```bash
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
scripts/verify-rollback-branch.sh
```

Plus the six named suites that are the direct regression surface for this feature:
`IngestionBackpressureTest`, `IngestionCircuitBreakerTest`, `IngestionFairnessTest`,
`RedisCircuitBreakerTest`, `TenantShardRoutingConsistencyTest`,
`HorizonSupervisorSeparationTest`.

## Results

### Unit suite

| | Gate 0 baseline (pre-WU1) | WU10 (post-WU9) |
|---|---|---|
| Passed | 160 | 209 |
| Failed | 14 | 14 |
| Assertions | 980 | 1306 |

**Failure set diff: byte-identical.** The same 14 test methods fail in the same
files (`SecurityReportingServiceTest`, `ReportAggregationServiceTest`,
`JobProcessingServiceTest`, `TransactionProcessingServiceTest`,
`TransactionValidationServiceTest`) for the same pre-existing reasons (schema/fixture
drift unrelated to this feature — e.g. a missing `name` column, stale
`transaction_id`-array test fixtures), confirmed via `diff` against the saved Gate 0
output. None of these files are touched by any WU1–WU9 change. The 49 additional
passing tests are exactly the new Unit tests WU1–WU9 added (WU2's
`MetricsDistributionTest`, WU3's `DeadlockRetryServiceTest`, WU4's
`SkewRankingServiceTest` and `IngestionQueueRouterTest` extensions, WU5's
`TenantFairnessOverrideServiceTest` and `IngestionFairnessOverrideIntegrationTest`,
WU1's `LogContextTest`).

### Feature suite

| | Gate 0 baseline (pre-WU1) | WU10 (post-WU9) |
|---|---|---|
| Passed | 210 | 257 |
| Failed | 93 | 93 |
| Assertions | 1363 | 1771 |

**Failure set diff: byte-identical.** The same 93 test methods fail for the same
pre-existing reasons already characterized at Gate 0 — confirmed via `diff` against
the saved Gate 0 output (see Gate 0's own record for the root-cause breakdown:
`StoreOfficialResilienceTest`'s stale synchronous-200-response assertions against the
already-shipped async 202 behavior, `BatchIngestionTenantTerminalValidationTest`'s
missing `batch_id` fixture field, `TransactionPipeline\TransactionValidationTest`'s
type mismatch, and others — none touch any WU1–WU9 file). The 47 additional passing
tests are exactly the new Feature tests WU1–WU9 added (WU1's
`CorrelationIdNormalizationTest`, WU2's `ObservabilityLatencyMetricsTest`, WU4's
extensions to `IngestionBackpressureTest`/`IngestionBatchLimitTest`/others, WU5's
`TenantThrottleOverrideMiddlewareTest` and `TenantThrottleArtisanCommandsTest`, WU7's
`ObservabilityIngestionEndpointsTest`).

### Named regression-surface suites

All six remain 100% clean, identical to Gate 0:

- `IngestionBackpressureTest` — 0 failures
- `IngestionCircuitBreakerTest` — 0 failures
- `IngestionFairnessTest` — 0 failures
- `RedisCircuitBreakerTest` — 0 failures
- `TenantShardRoutingConsistencyTest` — 0 failures
- `HorizonSupervisorSeparationTest` — 0 failures

### Rollback guard

```
rollback guard passed: origin/remove-webapp-forwarding remains at 6c4487698fcbb01504cbcdebb55cc16633e613ed
```

Identical SHA to the Gate 0 run — the `remove-webapp-forwarding` fallback branch has
not moved or diverged at any point during WU1–WU9's implementation.

## Conclusion

Zero new failures introduced anywhere across WU1–WU9, confirmed by direct diff
against the recorded Gate 0 baseline rather than by re-running tests fresh and
assuming a clean pass means no regression. Every one of the 96 new tests added by
WU1–WU9 (49 Unit + 47 Feature) passes. The 107 pre-existing failures (14 Unit + 93
Feature) are unchanged in both count and identity from Gate 0, confirming they are
genuinely unrelated to this feature's work rather than newly introduced.

**Not covered by this report** (explicitly out of scope for WU10, per the plan):
T049 (staging alert drill), T050 (failed-job replay drill), T051 (100-tenant staging
load test), the full T059 validation (depends on T049–T051 actually running), and
T061 (final architecture readiness review). This report only confirms the
code-producible portion of US5 (T048, T052–T056) introduced no regressions — it does
not constitute a staging drill or a load test result.
