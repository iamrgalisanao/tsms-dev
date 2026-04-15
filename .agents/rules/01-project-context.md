# 01 Project Context: TSMS (Transaction Management System)

## Vision
TSMS is a high-performance, multi-tenant ingestion system designed for PITX terminal operations. It provides a scalable, secure, and standalone source of truth for transaction data, prioritizing absolute isolation and cryptographic integrity.

## Core Architecture (A.N.T. Design)
- **Layer 1: Architecture**: Governance documents in `docs/architecture/` (sharding, checksums, audit standards).
- **Layer 2: Navigation**: Multi-tenant resolution, authentication, and ability-based routing.
- **Layer 3: Tools**: Deterministic scripts for validation, seeding, and parsing in `tools/`.

## Key Features
- **Multi-tenancy**: Shared-Database, Isolated-Schema enforced via global query scopes.
- **Cryptographic Integrity**: Dual-layer SHA-256 checksum verification for both transactions and submissions.
- **Asynchronous Ingestion**: Synchronous handshake followed by background validation workers.
- **Queue Sharding**: 8 shards (`s0-s7`) based on `tenant_id` to prevent performance bottlenecks.

## Guardrails
- **Passive Ingestion**: Ingest raw truth without mutation.
- **Cryptographic Gatekeeping**: Never bypass `PayloadChecksumService`.
- **Standalone Archive**: WebApp forwarding is permanently disabled.
- **Audit Requirement**: Every `VALID` transaction must have a `TransactionValidation` record.
