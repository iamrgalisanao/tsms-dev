# Per-Tenant Circuit Breaker – Phase 1 (Observation) Documentation Plan

## 1. Purpose
Introduce safer multi-tenant resilience by detecting tenants that experience high retryable forwarding failures (network / 5xx) without altering production behavior yet.

## 2. Scope (Phase 1 Only)
- Collect attempt + retryable failure counts per tenant in a sliding time window.
- Compute failure ratio; log structured warning when thresholds crossed.
- NO blocking, throttling, backoff changes, or tenant isolation yet.

## 3. Audience
Operations, Engineering (backend & SRE), QA. Provides shared observable vocabulary before moving to enforcement phases.

## 4. Data Model / Counters
- attempts: count of batch forwarding attempts that include tenant as primary tenant.
- failures: count of retryable classifications:
  - HTTP_5XX_RETRYABLE
  - NETWORK_DNS
  - NETWORK_OTHER
- failure_ratio = failures / attempts (float, 2–3 decimal precision acceptable).

## 5. Configuration (config/tsms.php)
```
tenant_breaker.observation.enabled (bool, default true)
tenant_breaker.observation.min_requests (int, default 20)
tenant_breaker.observation.failure_ratio_threshold (float, default 0.5)
tenant_breaker.observation.time_window_minutes (int, default 10)
```

Rationale:
- min_requests avoids noisy small samples.
- ratio threshold tuned empirically before Phase 2.
- window balances responsiveness vs stability.

## 6. Sliding Window Strategy
Simple TTL-based buckets in cache. Counters auto-expire after window_minutes. On active traffic they continuously reflect last N minutes (approximate sliding window acceptable for Phase 1).

## 7. Logging Contract (When Over Threshold)
Level: warning
Message examples:
"Tenant breaker observation threshold crossed (failure path)"
Standard fields:
```
tenant_id, attempts, failures, failure_ratio, threshold_ratio,
window_minutes, phase="observation", enforced=false, schema_version
```

Additional paths identified in code: success path (after recovery), failure path (HTTP error), exception path.

## 8. Operational Playbook (Phase 1)
1. Monitor logs (warning) filtered by `phase=observation` and `enforced=false`.
2. For a tenant emitting repeated warnings:
   - Check upstream endpoint status & latency.
   - Compare with other tenants (is this localized?).
   - Inspect recent deployments / configuration changes.
   - Optionally lower ratio threshold temporarily for more sensitivity (document change in runbook).
3. Capture baseline statistics (top 5 tenants by failure_ratio daily) before moving to Phase 2.

## 9. Metrics Extension (Future)
Add explicit metrics keys (optional Phase 1):
`tenant_breaker.obs.threshold_crossed` (increment when a warning is emitted).
Deferred until necessity confirmed.

## 10. Phase Progression Gates
Move to Phase 2 (Shadow) only when:
- At least 3 days of baseline collected.
- No false positives causing >10% transient noise.
- Clear difference between healthy (<20% ratio) and problematic tenants identified.

## 11. Risk Assessment (Phase 1)
Risk is minimal: read/write lightweight cache counters; no behavioral change in forwarding pipeline. Failure of observer degrades silently.

## 12. Testing Strategy
- Unit test (future): simulate increments; ensure evaluation returns eligible + over_threshold only after min_requests.
- Integration smoke: trigger > min_requests with forced retryable failure classification and verify warning log emitted.
Tests deferred until Phase 2 unless needed earlier.

## 13. Failure Modes & Mitigation
Cache eviction early -> counters reset (acceptable; observation only).
Misclassification -> adjust classification list; no production impact.
High-cardinality tenant IDs -> acceptable (bounded by active tenants). Future optimization: periodic reset or LRU compaction if needed.

## 14. Upgrade / Rollback
Disable by setting `WEBAPP_TENANT_BREAKER_OBS_ENABLED=false` then deploy (or config:cache refresh). Code leaves no side-effects when disabled.

## 15. Future Phases (Not Implemented Yet)
- Phase 2 (Shadow): simulate block decision, annotate logs with `shadow_block_decision=true`.
- Phase 3 (Enforce): optionally short-circuit forwarding for tenants over threshold, with cooldown and manual override.

## 16. FAQ Highlights
Q: Does this slow forwarding? A: Negligible; two cache increments per batch + optional evaluation after result.
Q: Why warn on success path? A: Ratio can remain high even after intermittent success; surfacing aids visibility of recovery slope.

## 17. Ownership
Primary: Backend Team
Secondary: SRE (threshold tuning guidance)

---
Status: Implemented (observation only) – see `TenantBreakerObserver` and instrumentation in `WebAppForwardingService`.
