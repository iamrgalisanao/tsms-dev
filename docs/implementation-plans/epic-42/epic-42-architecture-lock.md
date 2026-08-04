# Epic 42 Architecture Lock

Status: Draft
Last updated: 2026-07-20
Source spec: `docs/specs/license-redeployment-control/`

## 1. Objective and Security Boundary

Epic 42 implements TSMS License Redeployment Control as a Release 1 backend perimeter. It protects TSMS from copied, restored, reinstalled, redeployed, or reused deployments operating outside vendor-approved client, deployment, environment, location, tenant, and terminal scope.

## 2. Release 1 Scope Boundary

Release 1 includes signed local license verification, deployment fingerprinting, observe-mode middleware, audit logs, binding, vendor-approved recovery, and controlled enforcement gates. It excludes billing, subscriptions, online commercial license server, self-service customer portal, and mandatory live vendor dependency for every POS transaction.

## 3. Canonical License Identity

Use opaque canonical identifiers:

- `client_id`: `cli_<uuidv7>`
- `deployment_id`: `dep_<uuidv7>`
- `license_id`: `lic_<uuidv7>`
- `location_code`: controlled uppercase business code such as `PH-PITX-MAIN`
- `environment`: strict enum: `development`, `test`, `staging`, `production`, `disaster-recovery`

Human-readable labels are metadata only and must not replace security identifiers.

## 4. License Artifact and Signature Standard

License artifacts are signed by the vendor and verified by TSMS using public keys resolved by `kid`. Production signing must use asymmetric signatures. Recommended algorithm is Ed25519/EdDSA; ECDSA P-256/ES256 is permitted where required by enterprise tooling.

## 5. Deployment Fingerprint Rules

Fingerprinting must be versioned and based on stable approved deployment attributes. Raw fingerprint components must not be returned by APIs or written to ordinary logs. IP, hostname, MAC address, and similar unstable values are diagnostics unless explicitly approved otherwise.

## 6. Environment Trust Separation

Staging and production must use separate deployment IDs, license IDs, fingerprints, action-token audiences, secret stores, license files, and preferably signing keys. Production must reject staging licenses and staging action tokens.

## 7. Vendor Action Authorization

Local RBAC never establishes vendor authority by itself. License-changing actions require local operator permission plus vendor-signed action authorization. Tokens are action-specific, environment-specific, time-limited, single-use, and validated before any state mutation.

## 8. Replay Protection

Replay protection must use an atomic database-backed consumption ledger. Cache-only replay protection is prohibited. Consuming `jti`, request ID, nonce, and executing the state transition must happen in one transaction.

## 9. Tenant, Terminal, and Transaction Binding

Active tenants and terminals must be bound to deployment, location, and license lineage before restricted/enforce mode. New transactions must receive the active deployment identity. Historical transactions must not be blindly backfilled to the current deployment when provenance is uncertain.

## 10. Enforcement-Mode Semantics

`disabled` bypasses blocking checks. `observe` validates and audits without blocking normal business flow. `restricted` blocks selected protected or security-sensitive operations according to the approved operations matrix. `enforce` applies full blocking only after all production gates are approved.

## 11. Restricted-Mode Availability

A valid locally signed license permits continued transaction operations. Vendor authorization is required only for security-sensitive state changes such as activation, rebinding, recovery, replacement, reinstall, and redeployment.

## 12. Recovery and Redeployment Rules

Standard recovery flow:

```text
Recovery request
-> vendor action token authorizes operation
-> binding/recovery action executes
-> replacement signed license establishes final valid state when required
-> prior license or binding is superseded
```

## 13. Key Trust Store and Rotation

Use a trust store that resolves public keys by `kid` and environment. Support active, previous verification-only, and emergency replacement keys. TSMS must never contain the vendor private signing key.

## 14. Route Protection Policy

Do not attach `license.valid` globally. Classify routes first. First observe wave is POS official intake, batch intake, refund, and void. Diagnostics and recovery routes must remain outside `license.valid` and use explicit authentication/permission controls.

## 15. Migration and Historical Attribution

Use expand, dual-write, chunked backfill, validate, index, constrain. Do not add `NOT NULL` on `transactions.deployment_id` until historical attribution is approved and rehearsal evidence exists.

## 16. Audit and Secret-Redaction Rules

Audit events must use safe reason codes and hashes for token/request/fingerprint identifiers. Do not log private keys, full action tokens, raw nonces, raw request IDs, raw fingerprint components, terminal secrets, database credentials, or env secrets.

## 17. Production Promotion Gates

Restricted/enforce mode requires approved `LIC-POL-001`, `LIC-KMP-001`, `LIC-TKN-001`, `LIC-RPL-001`, `LIC-OPS-001`, `LIC-MIG-001`, `LIC-RTE-001`, and `LIC-ROL-001`, plus successful recovery, rollback, key-rotation, vendor-outage, route, and migration rehearsals.

## 18. Explicitly Prohibited Implementations

- No private vendor signing key in TSMS.
- No HMAC production signing.
- No local role as sole vendor authority.
- No cache-only replay protection.
- No global license middleware before route classification.
- No hard enforcement before observe-mode approval.
- No blind historical transaction backfill.
- No staging license promotion to production.
- No live vendor heartbeat dependency for each POS request.
- No raw fingerprint or token exposure.

