# Project Roadmap

See the detailed development roadmap in [_md/NEXT_DEVELOPMENT_ROADMAP.md](_md/NEXT_DEVELOPMENT_ROADMAP.md).

## Phase 1: Core Ingestion Restoration (Current)
- [x] Dual-Checksum Validation (V2.1/V2.0)
- [x] Multi-Tenant Isolation (Global Scopes)
- [x] Real-time Ingestion Proof (TransactionValidation)
- [x] Contextual Memory Layer (Fixes, Features, Research)
- [x] Compliance Audit Logging (findings.md)

## Phase 2: TSMS Transaction Intake Refactor (Active)
- [x] **Phase 1: Design Finalization** - Defined state models and validation boundaries.
- [x] **Phase 2: Durable Intake** - Implemented `transaction_intake` table and thin controller path.
- [x] **Phase 3: Async Pipeline** - Implemented background job processing for business logic.
- [x] **Phase 4: Recovery & DLQ** - Implemented 2-minute reconciliation SLA worker.
- [x] **Phase 5: Observability** - Instrument dashboards, alerts for queue lag, and latency metrics.
- [x] **Phase 6: Governance Alignment** - Hardened rule sets and initialized operational ledger/stage-gates.
- [ ] **Phase 7: Validation & Rollout** - Shadow mode testing, tenant pilot, and full migration.
- [ ] **Phase 8: Monitoring Enhancements** - Implement Operational/Business Hours for Inactivity Alerts to prevent false alarms during closing hours.

## Phase 3: React UI & Dashboards (Planned)
- [ ] Multi-Tenant Dashboard
- [ ] Real-time Transaction Monitor
- [ ] Tenant Management UI
