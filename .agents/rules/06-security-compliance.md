# 06 Security & Compliance Policy

This policy defines the non-negotiable standards for maintaining data integrity, multi-tenant isolation, and PII hygiene within the TSMS ecosystem.

## 1. Multi-Tenant Isolation
- **Global Scopes**: All Eloquent queries must utilize the `BelongsToTenant` trait to enforce schema-level separation.
- **Tenant Context**: Never cache data or assets across tenant boundaries without explicit sanitization and salt-shifting.
- **Access Control**: Every entry point must validate `tenant_id` context against the authenticated provider's scope.

## 2. Cryptographic Integrity (Gatekeeping)
- **Payload Validation**: No transaction ingestion is permitted to bypass the `PayloadChecksumService`.
- **Dual-Layer Hashing**: Verification must occur at the individual `Transaction` level and the `Submission` batch level.
- **Immutability**: Once a transaction is marked `VALID` and stored, its raw metadata must remain immutable. Any corrections must be handled as supplemental audit events.

## 3. PII & Privacy Hygiene
- **Data Minimization**: Only ingest fields strictly required for terminal reporting and validation.
- **Anonymization**: Any logs or debugging data containing sensitive user info must be masked or anonymized at the ingestion gateway.
- **Audit Trails**: Maintain a high-fidelity audit log of all system-level state changes, especially those affecting security settings or cryptographic keys.

## 4. Compliance Gates
Prior to any production release, a security scan and compliance audit must be recorded in `docs/ai-governance/security-checklist.md`.
