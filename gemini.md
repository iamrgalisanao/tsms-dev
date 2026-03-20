# TSMS Project Constitution (Strategy)

## 1. System Vision
TSMS is a high-performance, multi-tenant transaction ingestion engine. It serves as the **immutable local authority** for PITX terminal data, prioritizing sub-second latency and cryptographic integrity over external connectivity.

---

## 2. Tech Stack

| Layer | Technology | Status |
| :--- | :--- | :--- |
| **Frontend** | ReactJS | Modern UI Layer |
| **Frontend (Legacy)** | Blade | Migration Path |
| **Backend** | Laravel 11.x | PHP 8.2+ |
| **Database** | MySQL 8.0+ | Primary Data Store |
| **Queue/Cache** | Redis | Laravel Horizon (Sharded) |
| **Authentication** | Laravel Sanctum | POS-to-API Security |

---

## 3. 3-Layer A.N.T. Architecture
To maintain discipline, all development must adhere to these layers:

### Layer 1: Architecture (`docs/architecture/`)
- **SOPs & Policies**: Defines sharding logic, checksum protocols, and audit standards.
- **Subagents**: Architectural logic for automated code scanning and compliance.

### Layer 2: Navigation
- **Tenant Resolution**: Mandatory `tenant_id` scoping for every database query.
- **Auth Context**: Sanctum-based middleware governing `pos_api` guard abilities.

### Layer 3: Tools (`tools/`)
- **Deterministic Execution**: Python/PHP scripts for transaction parsing, payload validation, and system seeding.
- **Standards Support**: Maintained readiness for future clinical data standards (HL7/FHIR).

---

## 4. Architectural Invariants (Non-Negotiables)
- **Data-First Rule**: Schemas and validation logic must be defined before implementing ingestion tools.
- **Sharded Fairness**: Jobs must be distributed across **8 Redis queues (s0-s7)** based on `tenant_id % 8` to prevent head-of-line blocking.
- **Checksum Integrity**: Dual-layer verification (V2.1 with V2.0 fallback) is mandatory for all POS payloads.
- **No External Leakage**: The WebApp Forwarder is **DEPRECATED and DISABLED**. TSMS is a standalone source of truth.
- **Self-Annealing**: Every error must lead to a tool patch, followed by a test, and finally an updated SOP.

---

## 5. Governance & Document Hierarchy
The following order of precedence applies to all TSMS development:

1.  **`gemini.md`**: This document—the strategy and architectural soul.
2.  **`spec.md`**: The domain-specific rules, non-negotiables, and functional requirements.
3.  **Subagents**: Operations-level logic for specific tasks (e.g., Code Scanning).
4.  **`operational_protocol.md`**: The mandatory gatekeeper for daily development and risk management.
5.  **[`coding-standards.md`](docs/standards/coding-standards.md)**: The mechanical rules for naming, sharding, and multi-tenant safety.

---

## 6. Maintenance Log

| Date | Event | Description |
| :--- | :--- | :--- |
| 2025-08-11 | Initial Brownfield | Baseline documentation captured (TSMS v1.0). |
| 2026-03-20 | A.N.T. Sync | Formalized "Isolated Source of Truth" protocol, added ReactJS to stack, and disabled WebApp forwarding. |