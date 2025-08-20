# TSMS Handover Document

## 1. Purpose
Enable smooth operational ownership transfer of the Transaction Management System.

## 2. System Snapshot
| Aspect | Detail |
|--------|--------|
| Framework | Laravel 11 |
| Auth | Sanctum tokens |
| Queue | Redis + Horizon |
| DB | MySQL |
| Core Flow | Transaction ingest → validate → queue → (optional) forward → status updates |

## 3. Critical Endpoints
| Function | Endpoint |
|----------|----------|
| Health | GET /api/v1/health |
| Introspect | GET /api/v1/tokens/introspect |
| Submit | POST /api/v1/transactions |
| Official submit | POST /api/v1/transactions/official |
| Status | GET /api/v1/transactions/{id}/status |
| Void | POST /api/v1/transactions/{id}/void |
| Heartbeat | POST /api/v1/heartbeat |

## 4. Daily Ops Checklist
| Task | Pass Condition |
|------|----------------|
| Health endpoint | status=ok |
| Horizon queue depth | Within normal threshold |
| Failed jobs | Near zero |
| Error code spikes | No sustained surges |
| Token anomalies | No mass invalid_token bursts |

## 5. Incident Triggers
| Trigger | Response |
|---------|----------|
| API down | Announce, collect logs, rollback recent deploy |
| Queue backlog | Scale workers, inspect failing job patterns |
| Many checksum_failed | Contact affected provider, inspect payload diff |
| Circuit breaker open | Investigate downstream endpoint health |
| High void frequency | Audit reasons, potential misuse |

## 6. Key Logs / Fields
- transaction_id
- terminal_id
- validation_status (PENDING, VALID, INVALID)
- void_reason
- correlation id header (X-Correlation-Id)

## 7. Token Lifecycle
| State | Cause |
|-------|------|
| Active | Fresh + terminal active |
| Expired | expires_at passed |
| Revoked | Manual revoke / regenerate |
| Inactive | Terminal disabled (status_id != 1 or is_active false) |

## 8. Common Recovery Actions
| Issue | Action |
|-------|--------|
| Stuck queued | Restart workers / confirm Redis health |
| Flood of retries | Check validation errors; refine upstream payloads |
| Repeated invalid_token | Verify token creation path & time skew |
| Callback failures | Inspect remote endpoint availability |

## 9. Configuration Touchpoints (.env)
- WEBAPP_FORWARDING_ENABLED
- CIRCUIT_BREAKER_* vars
- RATE_LIMIT_API_* vars

## 10. Change Management
Before releasing:
- Run tests (`php artisan test`)
- Check route list for new endpoints
- Validate migrations applied

## 11. KPIs
| KPI | Target |
|-----|--------|
| Median API latency | < 500 ms |
| Availability | ≥ 99.5% |
| Failed job rate | < 1% of total |
| Duplicate tx rate | Near zero (idempotent control) |

## 12. Known Risks
| Risk | Mitigation |
|------|-----------|
| Mixed legacy routes | Gradual deprecation plan |
| Validation inconsistency | Centralization roadmap |
| Logging noise | Structured log refactor pending |

## 13. Escalation Flow
1. Triage (support)
2. Ops engineer
3. Core dev / architect
4. Product for business impact

## 14. Handback Acceptance
- Runbook followed for 5 business days
- No Sev1/Sev2 unresolved
- Documentation updated with any deltas

## 15. Reference Docs
- USER_MANUAL.md
- QUICK_START_GUIDE.md
- TRAINING_PLAN.md
- FAQ_GLOSSARY.md (created alongside)
