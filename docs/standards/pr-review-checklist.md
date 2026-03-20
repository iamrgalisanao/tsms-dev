# PR Review Checklist: TSMS

## 0. Automated CI Gates (Mandatory)
- [ ] **P.I.I. Leak Guardrail**: Passed (No customer PII in logs/storage/fixtures).
- [ ] **Tenant Isolation Guardrail**: Passed (100% scoped query coverage).
- [ ] **Transaction Integrity Tests**: Passed (100% pass rate for V2.1/V2.0 checksums).

## 1. Tenant Isolation (Row-Level)
| ✅ Verify | ❌ Forbidden |
+| :--- | :--- |
+| Every tenant table includes `tenant_id` | `Model::all()` or `Model::find()` (unscoped) |
+| ORM models use `BelongsToTenant` trait | Raw SQL without `tenant_id` filter |
+| Queries are explicitly tenant-scoped | Unscoped background jobs (dispatch without tenant) |
+| Jobs carry and re-authorize `tenant_id` | Silent tenant isolation bypass |
+| Exports enforce `tenant_id` filtering | Cross-tenant storage leak |

## 2. P.I.I. & Privacy (RA 10173)
| ✅ Verify | ❌ Forbidden |
+| :--- | :--- |
+| Sensitive data uses `SafeAuditLogger` | Customer PII logged in plaintext |
+| TLS 1.3 enforced in transit | PII in `localStorage` or `sessionStorage` |
+| Restricted data encrypted via AES-256 | PII embedded in URL parameters |
+| Screenshots use only synthetic data | Real customer data in tests or docs |
+| Access control applied to PII views | PII sent to external third-party telemetry |

## 3. Transaction Data Integrity
- [ ] **No Silent Overwrite**: Validated transactions are immutable; updates require void/refund.
- [ ] **State Machines**: Workflow follows the `PENDING` → `VALID` / `INVALID` → `COMPLETED` sequence.
- [ ] **Dual-Checksum**: Validation proof persists in the `TransactionValidation` table.
- [ ] **Audit Trail**: Every state change (Void/Refund) triggers a transaction audit entry.

## 4. Governance & Extra Approvals
Certain code changes **REQUIRE** specialized sign-offs:

| Change Type | Extra Review Required |
+| :--- | :--- |
+| **Financial Logic/Totals** | Finance Domain Reviewer |
+| **Checksum/Ingestion Code** | Senior Engineer / Architect |
+| **Voids/Refunds Processing** | Compliance Officer |
+| **Tenant Isolation Bypass** | Security Specialist |
+| **V2.1 Standard Updates** | Technical Standards Owner |

## 5. Security & Privacy (RAIN Audit)
- [ ] **R**esponsibility: Terminal identity is verified (Sanctum `pos_api`).
- [ ] **A**udit: All state changes log to the Transaction Validation Engine.
- [ ] **I**solation: No cross-tenant data leakage risk.
- [ ] **N**on-Repudiation: Every ingestion is attributable to a unique `terminal_id`.
