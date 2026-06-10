# Changelog

All notable changes to the TSMS project will be documented in this file.

## [Unreleased] - 2026-06-10

### Added
- **Manual Reconciliation Trigger**: Added a "Manual Reconciliation" button on the Transaction Logs page for `admin`, `finance`, and `commercial` roles to invoke the self-healing reconciliation pipeline on-demand with real-time feedback.
- **Intake Repair Scheduling**: Scheduled a daily cron job at 11:00 PM for the `tsms:reconcile-intake --repair-missing` command to automatically repair processed intake records missing matching transaction rows.

### Fixed
- **POS API Redirection**: Hardened the authentication layer to force 401 JSON responses instead of 302 redirects for `/api/v1/` routes. This prevents machine-to-machine integration failures for POS terminals that do not send explicit `Accept` headers.

### Changed
- **Exception Rendering**: Centralized `AuthenticationException` rendering in `bootstrap/app.php` with detailed resolution metadata (`TSMS_AUTH_001`) to assist developers in troubleshooting token and header issues.

## [Unreleased] - 2026-04-20

### Added
- **Governance Hardening**: Initialized mandatory rule files (`04-tool-governance.md`, `06-security-compliance.md`) and operational guard rails (`task-ledger.md`, `stage-gates.md`, `risk-register.md`) to prevent context degradation and silent failures.
- **SDLC Workflows**: Formalized procedural workflows for Project Intake, Delivery Planning, and Task Implementation to ensure reproducible standards.

## [Unreleased] - 2026-04-10

### Fixed
- **Financial Reconciliation**: Resolved mathematical discrepancies in Grand Totals and Summary Views by subtracting `voided_at` transactions from high-level aggregations.
- **Tenant Filtering Gap**: Implemented resilient filtering in `TransactionLogController` to correctly find transactions via the Terminal relationship even when the direct `tenant_id` is missing.
- **Missing Tenant Attribution**: Added a "Self-Healing" mechanism in `ProcessTransactionJob` to automatically restore missing `tenant_id` from the Terminal relation during background audit.

### Added
- **Security Isolation (Terminal-Token Binding)**: Enforced strict validation ensuring each API token is bound to a specific `terminal_id`, blocking impersonation attempts within the same tenant.
- **Reporting Configuration**: Introduced `reporting.exclude_voids_from_totals` in `config/tsms.php` for environment-driven reconciliation behavior.

### Changed
- **Reconciled Dashboard Labels**: Renamed transaction summary cards to **"Net (Reconciled)"** and **"Gross (Valid)"** to provide clearer transparency for Finance auditors.
- **Zero-Value Void Representations**: Forced voided transaction rows to display **₱0.00** in sales columns to eliminate manual sum-up errors in the Detailed View.

## [Unreleased] - 2026-03-25

### Added
- **Sanctum Authorizable Check**: Implemented `Authorizable` interface and trait in `PosTerminal` model, resolving `TypeError` when checking permissions via Spatie/Laravel Gate.
- **Resilient Logging**: Implemented "Safe Logging" in `TransactionService` to prevent API failures when the `transaction_histories` table is missing (automatic fallback to `Log::info`).
- **Transaction Authorization**: Implemented `TransactionPolicy` to strictly enforce terminal-level ownership and tenant-level visibility, replacing manual controller checks.
- **Refund Validation**: Created `RefundTransactionRequest` for structured, decoupled validation of POS-initiated refund payloads.
- **Model Binding**: Configured the `Transaction` model and `api.php` routes for Route Model Binding via `transaction_id` (UUID), reducing manual lookup boilerplate by 30+ lines.

### Fixed
- **V2.1 Checksum Compliancy**: Restructured `PayloadChecksumService` and `TransactionController` to strictly adhere to V2.1 positioning standards (checksum positioning above transaction/adjustments).
- **Nested Array Hashing**: Fixed logic error in payload generation where nested `adjustments` and `taxes` were excluded from the hash calculation.
- **Refund 404 (UUID Lookup)**: Resolved `404 Not Found` in the refund lifecycle by switching from internal ID lookups to POS-generated `transaction_id` (UUID) lookups.
- **Refund Schema Alignment**: Fixed SQL `Unknown column` errors by aligning the codebase with the actual database schema:
    - Replaced `refund_status` and `refund_processed_at` with `is_refunded` (tinyint).
    - Synchronized `refund_reference_id` to `refund_reference` across all layers.
- **Inconsistent Ingestion**: Refactored `TransactionIngestService` for "Zero-Mutation" mapping, ensuring nested `taxes` and `adjustments` arrays match the database without arithmetic error.

### Changed
- **Payload Generator V2.1**: Revised `tools/generate_tsms_payload.php` to use the "Gold Standard" structural formatting, ensuring bit-perfect alignment with integration guidelines.

### Documentation
- **POS Test Scenarios**: Comprehensive update to `docs/test-scenarios.md` with V2.1 API-compliant payloads and "System Connectivity" (Base URL/Prefix) guidelines.
- **System Architecture**: Created `docs/system-functionalities.md` as the definitive technical reference for the TSMS API and data flow.

## [Unreleased] - 2026-03-20

### Added
- **TSMS Skills System**: Implemented a structured `.agents/skills/` system with specialized commands and scripts for `Ingestion`, `Governance`, and `Security`.
- **Architectural Refinement**: Decluttered the repository by archiving over 120 stale documents into `docs/archive/` and aligning the root `README.md` with the SOQT (Source of Truth Quintet).
- **Multi-Tenant Isolation**: Implemented `BelongsToTenant` trait and `TenantScope` for automated global query filtering across core models.
- **Custom Subagents**: Created standard-compliance, architectural, and UI/UX subagents in `.agents/subagents/`.

### Changed
- **Operational Protocol**: Refactored `operational_protocol.md` with TSMS-specific task-gating and session rules.
- **Security & Hygiene**: Adapted `docs/security/security-hygiene-guardrails.md` and `docs/standards/engineering-safeguards-policy.md` to the transactional ingestion domain.

### Fixed
- **Tenant Leakage Prevention**: Hardened `TransactionIngestService` by adding explicit `tenant_id` scoping to raw `DB::table` queries.
- **Infinite Recursion Fix**: Resolved 500 Internal Server Errors in `TenantScope` by implementing a recursion guard during authentication.

### Documentation
- **Developer Standards**: Created `docs/standards/developer-quick-guides.md` and `docs/standards/pr-review-checklist.md` to align PR reviews with TSMS-specific architectural invariants. Moved `coding-standards.md` to `docs/standards/` for organizational consistency.
- **Architectural Reference**: Updated `project-documentation.md` to reflect real-time sharding, multi-version checksum fallbacks, and the `hardware_id` compatibility shim.
