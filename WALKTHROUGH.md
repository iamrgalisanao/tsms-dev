# Project Walkthrough: TSMS

This file serves as a persistent record of major features and technical implementations.

## 1. Multi-Tenant Isolation
Implemented via `BelongsToTenant` trait and `TenantScope`. Ensures that all Eloquent queries are automatically restricted to the authenticated terminal or user's `tenant_id`.

## 2. Ingestion Engine (V2.1)
High-performance ingestion with dual-layer checksum verification. Validates payload integrity before persistence and logs proofs to the `transaction_validations` table.

## 3. A.N.T. Architecture
- **Architecture**: Standards and SOPs in `docs/`.
- **Navigation**: Tenant-aware routing and middleware.
- **Tools**: Verification and diagnostic scripts in `tools/`.

## 4. Governance Guardrails
Durable operating model using Rule-based behavioral constraints (`.agents/rules/`), Procedural SDLC workflows (`.agents/workflows/`), and an Operational Task Ledger for context preservation.
