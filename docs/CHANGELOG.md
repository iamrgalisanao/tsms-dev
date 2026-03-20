# Changelog

All notable changes to the TSMS project will be documented in this file.

## [Unreleased] - 2026-03-20

### Added
- **Multi-Tenant Isolation**: Implemented `BelongsToTenant` trait and `TenantScope` for automated global query filtering across core transactional and identity models.
- **Documentation Infrastructure**: Established the [Source of Truth Suite](docs/standards/pr-review-checklist.md) by adding `ROADMAP.md`, `progress.md`, and `WALKTHROUGH.md` to the root directory.
- **AI Interaction Standards**: Created `ai-interaction.md` and the `.agents/workflows/documentation-sync.md` workflow to enforce high-discipline development practices.

### Changed
- **Operational Protocol**: Refactored `operational_protocol.md` with TSMS-specific task-gating and session rules.
- **Security & Hygiene**: Adapted `docs/security/security-hygiene-guardrails.md` and `docs/standards/engineering-safeguards-policy.md` to the transactional ingestion domain.

### Fixed
- **Tenant Leakage Prevention**: Hardened `TransactionIngestService` by adding explicit `tenant_id` scoping to raw `DB::table` queries, ensuring absolute data isolation at the storage layer.

### Documentation
- **Developer Standards**: Created `docs/standards/developer-quick-guides.md` and `docs/standards/pr-review-checklist.md` to align PR reviews with TSMS-specific architectural invariants.
- **Architectural Reference**: Updated `project-documentation.md` to reflect real-time sharding, multi-version checksum fallbacks, and the `hardware_id` compatibility shim.
