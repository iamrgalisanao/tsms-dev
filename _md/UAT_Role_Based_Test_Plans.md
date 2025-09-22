# TSMS Role-Based UAT Test Plans

**Document Code**: TSMS-UAT-RBP-2025-001  
**Version**: 1.0  
**Status**: Draft for Team Circulation  
**Source Baseline**: `UAT_Guidelines_POS_Terminal_Integration.md` (v1.0)  
**Purpose**: Provide focused UAT execution packets per role so each stakeholder can independently validate staging readiness without re‑deriving scope.

---
## 1. Roles Covered
| Role Code | Role Name | Primary Objective |
|-----------|-----------|-------------------|
| R1 | POS Integration Engineer (Provider) | Validate API contract, checksum logic, batch/single flows |
| R2 | Tenant Business / Finance User | Validate sales data integrity & reconciliation viability |
| R3 | TSMS Platform Administrator | Validate provisioning, tokens, permissions & operational controls |
| R4 | Security & Access Reviewer | Validate auth, token handling, rejection of invalid inputs |
| R5 | Performance & Concurrency Tester | Validate response time SLAs & parallel submission stability |
| R6 | Data Integrity & Reconciliation Analyst | Validate persistence, duplicate handling, status queries, checksum failures |
| R7 | Support / Monitoring & Observability | Validate logging, error surfaces, health/status endpoints, alert readiness |
| R8 | QA Automation Engineer (Optional) | Create regression automation scripts aligned with core manual cases |

Each role packet below is self‑contained: objectives → scope → prerequisites → test set → acceptance & exit matrix.

---
## 2. Shared Assumptions / Environment
| Item | Value |
|------|-------|
| Environment | Staging / Test TSMS |
| Auth Mechanism | Sanctum Bearer Token per terminal |
| Timezone | UTC internally; external display UTC+08 where noted |
| API Version | v1 |
| Base Endpoint | (Provide actual staging base URL) |
| Logging Access | Central log viewer or Laravel logs (secured) |
| Rate Limit Window | 1000 req/hr (test policy) |

---
## 3. Test ID Namespace Strategy
Format: `<ROLE>-<DOMAIN>-<SEQ>`  
Examples: `R1-TXN-001`, `R4-AUTH-003`, `R5-PERF-010`.

Mapping to Master UAT Phases:
| Phase | Master Doc Section | Role Drivers |
|-------|--------------------|-------------|
| 1 | Connectivity | R1, R3, R7 |
| 2 | Single Transactions | R1, R2, R6 |
| 3 | Batch Transactions | R1, R5, R6 |
| 4 | Error Handling | R1, R4, R6, R7 |
| 5 | Performance | R5 |
| 6 | Workflow | R1, R3, R7 |
| 7 | Security | R4 |
| 8 | Data Integrity | R2, R6 |
| 9 (Future) | Void / Refund (Planned) | R1, R6 |

---
## 4. Role Packets

### 4.1 R1 – POS Integration Engineer
**Objective**: Prove API contract fidelity (structure, required fields, checksum rules) for both single and batch flows including retry safety.

**In Scope**:
- Health check, auth handshake
- Single & batch submissions, checksum validation
- Duplicate transaction idempotency
- Adjustment & tax enumeration presence
**Out of Scope**: Performance load extremes (R5), deep security fuzzing (R4).

**Prerequisites**: Valid tenant_id, terminal_id, bearer token; reference checksum utility.

| Test ID | Purpose | Steps (Condensed) | Expected |
|---------|---------|-------------------|----------|
| R1-CONN-001 | API reachability | GET /healthcheck | 200 + services healthy |
| R1-AUTH-001 | Auth required | POST protected w/o token | 401 Unauthenticated |
| R1-TXN-001 | Single minimal valid txn | POST official single | 200 Accepted |
| R1-TXN-002 | Adjustments set canonical | Include 7 adjustment types (0 allowed) | 200; stored types match |
| R1-TXN-003 | Taxes set canonical | Include 4 tax types | 200; tax rows persisted |
| R1-TXN-004 | Duplicate transaction_id | Resubmit identical payload | 409 or documented duplicate response |
| R1-BATCH-001 | Batch 3 transactions | POST batch | 200; count=3 |
| R1-BATCH-002 | Batch with mixed adjustments | Mixed transaction shapes | All accepted; per-item integrity |
| R1-CHK-001 | Bad transaction checksum | Tamper field | 422 checksum error |
| R1-CHK-002 | Bad submission checksum | Tamper top-level | 422 checksum error |

**Acceptance / Exit (R1)**:
- 0 Critical / High defects open impacting contract
- ≥95% pass rate of listed tests
- Duplicate detection confirmed.

### 4.2 R2 – Tenant Business / Finance User
**Objective**: Validate sales values, discount representation, readiness for reconciliation.
**Focus**: Data correctness, rounding, discount taxonomy clarity.

| Test ID | Purpose | Steps | Expected |
|---------|---------|-------|----------|
| R2-DATA-001 | Gross vs net formula | Submit known arithmetic case | Net = Gross - Adj - OtherTax (±0.01) |
| R2-DATA-002 | Zero adjustment case | Submit with all 0 adjustments | Net == Gross - OtherTax |
| R2-DATA-003 | High precision rounding | Amounts with >2 decimals (client pre-round) | Server rejects or normalizes per spec |
| R2-DATA-004 | Large boundary | Near max documented amount | Accepted without overflow |
| R2-DATA-005 | Batch day subset | Submit representative day mini-batch | Aggregates match manual sum |
| R2-DATA-006 | Discount taxonomy clarity | Retrieve stored adjustments | All expected categories present with 0 if unused |

Exit: No material variance >0.01 in any reconciliation scenario; discount taxonomy acceptable.

### 4.3 R3 – TSMS Platform Administrator
**Objective**: Operational readiness: provisioning, token lifecycle, revocation, pagination.

| Test ID | Purpose | Steps | Expected |
|---------|---------|-------|----------|
| R3-PROV-001 | Terminal provisioning | Create terminal & token | Terminal visible; token active |
| R3-PROV-002 | Token regeneration | Regenerate token | Old token invalid; new works |
| R3-PROV-003 | Token revocation | Revoke token | Further calls 401 |
| R3-LIST-001 | Pagination & filtering | List terminals with filters | Accurate filtered result |
| R3-MON-001 | Health & metrics visibility | Check health & logs | Logs show submissions |

Exit: All lifecycle actions succeed; no orphaned active tokens after revoke.

### 4.4 R4 – Security & Access Reviewer
**Objective**: Authorization boundaries & input hardening.

| Test ID | Purpose | Steps | Expected |
|---------|---------|-------|----------|
| R4-AUTH-001 | Missing token | POST without auth | 401 |
| R4-AUTH-002 | Malformed token prefix | Use `Token xyz` | 401 |
| R4-AUTH-003 | Expired / revoked token | Use revoked token | 401 |
| R4-VAL-001 | SQL injection attempt | Inject `' OR 1=1 --` in string field | 422 sanitized; no log errors |
| R4-VAL-002 | Oversized payload | Exceed documented size | 413 or 422 per policy |
| R4-VAL-003 | XSS probe | `<script>alert(1)</script>` in customer_code | Escaped / rejected |
| R4-VAL-004 | Rate limit | Rapid > threshold | 429 after limit |
| R4-CHK-001 | Tampered checksum | Modify field post-checksum | 422 checksum failure |

Exit: All negative security vectors blocked; no sensitive info in error bodies.

### 4.5 R5 – Performance & Concurrency Tester
**Objective**: Confirm SLA compliance under defined batch sizes & concurrent submissions.

| Test ID | Purpose | Load Pattern | Expected |
|---------|---------|-------------|----------|
| R5-PERF-001 | Single txn latency | 50 sequential singles | P50 < 300ms, P95 < 500ms |
| R5-PERF-002 | Small batch latency | 20 batches of 3 | P95 < 1s |
| R5-PERF-003 | Medium batch latency | 10 batches of 8 | P95 < 3s |
| R5-PERF-004 | Large batch latency | 5 batches of 15 | P95 < 5s |
| R5-CONC-001 | 10 concurrent singles | Burst 10 threads | All succeed; no 5xx |
| R5-CONC-002 | 5 concurrent medium batches | 5 threads × 8 txns | No deadlocks; all 2xx/4xx expected |
| R5-STAB-001 | Soak (optional) | 1 txn / 10s for 2h | Stable latency; no resource drift |

Exit: All SLA metrics met; 0 critical performance defects.

### 4.6 R6 – Data Integrity & Reconciliation Analyst
**Objective**: Ensure stored data traceability, duplicate prevention, status querying.

| Test ID | Purpose | Steps | Expected |
|---------|---------|-------|----------|
| R6-DUP-001 | Duplicate prevention | Resubmit txn_id | Duplicate response (no data change) |
| R6-STAT-001 | Status endpoint | Query soon after submit | Status transitions valid |
| R6-CONS-001 | Field persistence | Retrieve record | All submitted fields intact |
| R6-FAIL-001 | Corrupt checksum | Submit bad checksum | Rejected; no partial write |
| R6-BATCH-001 | Batch atomicity | Fail one txn intentionally | Policy (whole vs partial) documented & observed |
| R6-AUD-001 | Audit trail presence | Review logs / audit entries | Correlated IDs present |

Exit: No unexplained state anomalies; audit trail sufficient for reconstruction.

### 4.7 R7 – Support / Monitoring & Observability
**Objective**: Validate that operational teams can detect, triage, and communicate issues.

| Test ID | Purpose | Steps | Expected |
|---------|---------|-------|----------|
| R7-LOG-001 | Structured logging | Submit txn | Log line with txn/submission IDs |
| R7-ERR-001 | Validation failure log | Trigger 422 | Clear error context logged |
| R7-HEALTH-001 | Health degradation (simulate) | Disable dependent service (if sandbox) | Health endpoint reflects degradation |
| R7-ALERT-001 | Threshold alert (optional) | Exceed latency artificially | Alert triggered / recorded |
| R7-DASH-001 | Metrics visibility | View dashboard | Key charts (latency, error rate) visible |

Exit: All required telemetry surfaces; no silent failures.

### 4.8 R8 – QA Automation Engineer (Optional)
**Objective**: Codify critical regression paths.
**Required Coverage**: At minimum all R1 core plus checksum negative, duplicate, batch path, and a performance smoke.

| Test ID | Purpose | Framework Output |
|---------|---------|-----------------|
| R8-AUTO-001 | Automate single txn happy path | Pass/Fail artifact |
| R8-AUTO-002 | Automate checksum failure | Pass (422 validated) |
| R8-AUTO-003 | Automate duplicate detection | Pass (conflict) |
| R8-AUTO-004 | Automate batch 5 txns | Pass (count & IDs) |
| R8-AUTO-005 | Automate adjustment taxonomy | Pass (all categories) |
| R8-AUTO-006 | Smoke performance | Latency metric captured |

Exit: Automation suite stable; artifacts stored.

---
## 5. Cross-Role Dependency Matrix
| Dependency | Provides | Consumes |
|------------|----------|----------|
| Provisioned Terminal & Token | R3 | R1, R2, R5, R6, R8 |
| Load Scripts / JMeter Config | R5 | R1 (sanity), R7 (monitoring) |
| Logging / Dashboard Access | R7 | All roles needing evidence |
| Checksum Utility Spec | R1 | R2, R6, R8 |

---
## 6. Defect Severity Definition (UAT Context)
| Severity | Description | UAT Block? |
|----------|-------------|------------|
| Critical | Data loss, security bypass, unrecoverable error | Yes (halt) |
| High | Core function fails; no workaround | Yes (unless accepted risk) |
| Medium | Non-core failure; workaround exists | Track, not blocking |
| Low | Cosmetic / minor inconsistency | No |

Exit criteria per role require 0 Critical & High open (unless formally waived) and cumulative Medium defects risk-assessed.

---
## 7. Consolidated Exit & Go/No-Go Checklist
| Area | Criteria | Status (Fill) |
|------|----------|--------------|
| API Contract | All R1 tests pass |  |
| Data Integrity | R2 & R6 exit criteria met |  |
| Security | R4 negative cases blocked |  |
| Performance | R5 SLAs achieved |  |
| Monitoring | R7 telemetry verified |  |
| Automation | R8 baseline suite green |  |
| Documentation | All role results archived |  |
| Sign-offs | All role leads approved |  |

---
## 8. Role Sign-Off Template
```
Role: (R# Name)
Date:
Executed Test IDs: (...)
Defects Raised: # (Critical / High / Medium / Low)
Outstanding Risks: (summary)
Recommendation: GO | GO-WITH-RISKS | NO-GO
Signature / Initials:
```

---
## 9. Next Enhancements (Planned)
- Integrate Void/Refund role tests once schema & endpoints stabilized.
- Add automated extraction of pass/fail into DAILY_ACTIVITY digest for traceability.
- Introduce performance percentile tracking script feeding into Cipher memory.

---
## 10. Appendices
**A. Mapping to Original UAT Master IDs** – Each new role test ID maps to the nearest existing master ID; traceability maintained via description alignment.  
**B. Automation Seed List** – R8 cases recommended first wave for CI.

---
**End of Document**
