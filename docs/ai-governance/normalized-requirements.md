# Normalized Requirements: TSMS

## Business Objective
To provide a high-performance, multi-tenant ingestion system for PITX terminal operations that acts as a standalone source of truth for transaction data with absolute cryptographic integrity.

## Core Requirements
### R1: Multi-Tenancy
- Support multiple independent terminal owners.
- Enforce absolute data isolation via `tenant_id` scopes.
- Ensure terminals can only access their own data.

### R2: Cryptographic Integrity
- Implement dual-layer checksum verification (`PayloadChecksumService`).
- Layer 1: Inner SHA-256 hash of the transaction object.
- Layer 2: Outer SHA-256 hash of the submission envelope.
- Canonicalization: Alpha-sort (ksort) and 2-decimal string formatting.

### R3: Ingestion Pipeline
- Synchronous Handshake: Initial checksum and token verification (`201 Accepted`).
- Asynchronous Completion: Background workers for math audits and business rules.
- State Flow: `PENDING` -> `VALID` / `FAILED`.

### R4: Operational Continuity
- Support Voids and Refunds (requires pre-existing sale).
- Idempotency: Reuse original `transaction_id` for retries; return `409` if payload differs.
- Rate Limiting: High-availability scaling for peak terminal hours.

### R5: Data Privacy (DPA Compliance)
- Transparency and Legitimate Purpose for data processing.
- Proportionality: Collect minimum data for auditing.
- Support Data Subject Rights (Access, Objection, Erasure).

## Success Criteria
- 100% verification of transaction integrity.
- Zero cross-tenant data leaks.
- Background validation completing within defined SLAs (e.g. < 5 minutes).

### R2: Reporting & Analytics
*   **R2.1: Transaction Aggregation**:
    *   The system must calculate hourly and daily totals for Gross Revenue, Net Revenue, and Tax.
    *   **Revenue Reconciliation**: Reports must distinguish between "Total Gross" (all ingested sales) and "Net Revenue" (Gross minus Voids and Discounts).
    *   **Void Handling**: Daily reports must explicitly subtract the `gross_sales` of any transaction marked as `voided_at` from the final revenue total.
*   **R2.2: Terminal Performance**: Monitor and rank terminals by volume and value.

## Constraints
- **Single Transaction Rule**: Exactly one transaction per submission.
- **FIFO Order**: Sequential submission required.
- **Permanently Disabled**: WebApp forwarding engine must not be enabled.
