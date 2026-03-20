# Changelog

All notable changes to the TSMS project will be documented in this file.

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
