# TSMS Ingestion Engine

> [!IMPORTANT]
> **Source of Truth Quintet (SOQT)**: This project is governed by five mandatory pillars of documentation. Every change must synchronize with:
> 1. [gemini.md](gemini.md) (Strategy) | 2. [spec.md](spec.md) (Functional) | 3. [operational_protocol.md](operational_protocol.md) (Workflow) | 4. [coding-standards.md](docs/standards/coding-standards.md) (Mechanical) | 5. [.agents/skills/](.agents/skills/) (Execution)

## 1. System Vision
TSMS is a high-performance, multi-tenant transaction ingestion engine. It serves as the **immutable local authority** for terminal data, prioritizing sub-second latency and cryptographic integrity.

## 2. Tech Stack
- **Backend**: Laravel 11.x (PHP 8.2+)
- **Frontend**: ReactJS (Modern) / Blade (Legacy Migration)
- **Database**: MySQL 8.0+ / Redis (Sharded)
- **Security**: Laravel Sanctum / POS-to-API Guard

## 3. The A.N.T. Architecture
- **Architecture (`docs/architecture/`)**: Governance, Sharding Logic, and Checksum Protocols.
- **Navigation**: Tenant Resolution & Auth Context (Sanctum).
- **Tools (`tools/`)**: Deterministic Transaction Parsers and System Shapers.

## 4. Key Security Invariants
- **Data-First Rule**: Schemas and validation must be defined before implementation.
- **Sharded Fairness**: Jobs distributed across **8 Redis queues (s0-s7)**.
- **Checksum Integrity**: Multi-version validation (V2.1 with V2.0 fallback).
- **Absolute Isolation**: Global query scopes prevent cross-tenant data leakage.

## 5. Developer Quick Ops
- **Documentation Sync**: Run `.agents/workflows/documentation-sync.md`.
- **Compliance Audit**: View [findings.md](findings.md) (Managed by Compliance Scanner).
- **System Skills**: Check [.agents/skills/](.agents/skills/) for specialized tasks.

---
*Standalone Source of Truth. No External Leakage.*
