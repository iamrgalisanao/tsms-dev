# TSMS Developer Quick Guide

Welcome to the TSMS (Transaction Management System) project. This guide provides the essential standards and workflows for contributing to the application.

---

## 1. Environment & Tech Stack
- **Backend**: Laravel 11.x (PHP 8.2+)
- **Frontend**: ReactJS (Vite) / Legacy Blade
- **Database**: MySQL 8.0+
- **Cache/Queue**: Redis (Laravel Horizon)
- **Auth**: Laravel Sanctum (`pos_api` guard for terminals)

---

## 2. Core Development Standards

### 2.1 Multi-Tenant Isolation
Every tenant-owned model (Transactions, Voids, Refunds) **MUST** use the `BelongsToTenant` trait. This ensures:
- `tenant_id` is automatically added to every insertion.
- Every query is globally scoped by the current `tenant_id`.

### 2.2 Transaction Integrity (Checksums)
All POS payload ingestion **MUST** be validated via the `PayloadChecksumService`.
- **V2.1 (String-based)**: Use strict 2-decimal string formatting for monetary values.
- **V2.0 (Float-based)**: Automatically used as a fallback for legacy compatibility.

### 2.3 Sharded Fairness (Redis)
All background jobs (Transaction Ingestion, Forwarding) **MUST** be sharded across 8 Redis queues (`s0` to `s7`) to prevent head-of-line blocking:
- **Rule**: `queue_name = "s" . ($tenant_id % 8)`

---

## 3. Workflow & API Design

### 3.1 API Versioning
All POS/Terminal APIs reside under `app/Http/Controllers/API/V1/`.
- **Base Path**: `/api/v1/`
- **Controller Naming**: `TransactionController`, `TerminalController`, etc.

### 3.2 Service Layer Pattern
Keep controllers thin. Business logic (Validation, Hashing, Ingestion) belongs in **Services**:
- `TransactionIngestService`: Main entry point for transaction logic.
- `PayloadChecksumService`: Cryptographic verification.

---

## 4. Testing & Verification

### 4.1 Feature Tests
New features **MUST** include feature tests ensuring:
1.  **Terminal Auth**: Only registered terminals with valid Sanctum tokens can ingest data.
2.  **Tenant Isolation**: Data from Tenant A never leaks to Tenant B.
3.  **Checksum Success**: Verify both V2.1 and V2.0 fallback validation paths.

### 4.2 Local Verification Tools
Use the internal diagnostic tools in `tools/` for local testing:
- `reproduce_hash.php`: Use this to debug checksum mismatches with raw POS payloads.

---

## 5. Security Hygiene
- **No PII in Logs**: Never log names, customer codes, or full receipt numbers.
- **Encryption**: Sensitive IDs (Senior/PWD) must be encrypted in the database.
- **No Global All**: Avoid `Model::all()` or raw queries without explicit `where('tenant_id', ...)` clauses.
