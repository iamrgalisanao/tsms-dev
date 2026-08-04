# User Stories

Document ID: `TSMS-LIC-RDC-STORIES`
Status: Reviewed - aligned to feature specification
Last updated: 2026-07-20

## Story Map

| ID | Title | Primary Area |
|---|---|---|
| `TSMS-LIC-RDC-001` | Define Canonical License Identity Policy | Policy |
| `TSMS-LIC-RDC-002` | Implement Signed License Verification | License validation |
| `TSMS-LIC-RDC-003` | Add Deployment Identity and Fingerprint | Deployment identity |
| `TSMS-LIC-RDC-004` | Implement LicenseService | Service layer |
| `TSMS-LIC-RDC-005` | Add License Audit Logging | Audit |
| `TSMS-LIC-RDC-006` | Add License Middleware and Modes | Middleware |
| `TSMS-LIC-RDC-007` | Classify Routes | Route policy |
| `TSMS-LIC-RDC-008` | Add Binding Schema | Data model |
| `TSMS-LIC-RDC-009` | Build Binding Audit and Backfill | Data readiness |
| `TSMS-LIC-RDC-010` | Add Read-Only Vendor License Operations | API |
| `TSMS-LIC-RDC-011` | Add Cryptographic Vendor Action Authorization | Security |
| `TSMS-LIC-RDC-012` | Implement POS Binding Enforcement | POS |
| `TSMS-LIC-RDC-013` | Implement Vendor-Approved Recovery Flow | Recovery |
| `TSMS-LIC-RDC-014` | Implement Replay Consumption Ledger | Security |
| `TSMS-LIC-RDC-015` | Implement Environment Trust Separation | Environment |
| `TSMS-LIC-RDC-016` | Establish Signing-Key Trust Store and Rotation | Key management |
| `TSMS-LIC-RDC-017` | Execute Production Migration Profiling and Rehearsal | Migration |
| `TSMS-LIC-RDC-018` | Define Restricted-Mode and Offline Operations Matrix | Operations |
| `TSMS-LIC-RDC-019` | Complete Production Route Disposition | Route cleanup |
| `TSMS-LIC-RDC-020` | Pilot, UAT, and Production Enforcement Readiness | Release governance |

## TSMS-LIC-RDC-001: Define Canonical License Identity Policy

As a vendor licensing operator, I need canonical license identifiers so deployment binding is consistent across license files, runtime configuration, database records, and audit logs.

Acceptance criteria:

- Define `client_id`, `deployment_id`, `license_id`, `location_code`, and environment enum.
- Use opaque canonical IDs such as `cli_<uuidv7>`, `dep_<uuidv7>`, and `lic_<uuidv7>`.
- Define location-code format, for example `PH-PITX-MAIN`.
- Define issuance and rotation rules for client, deployment, and license identities.
- Define storage representation, including text at API/config boundaries and recommended `BINARY(16)` database representation where practical.
- Confirm human-readable labels are not treated as security identifiers.

Definition of done:

- `LIC-POL-001` is approved.
- No mode beyond `observe` is allowed until canonical policy values are confirmed.

## TSMS-LIC-RDC-002: Implement Signed License Verification

As TSMS, I need to verify a signed vendor license file so the system can determine whether the deployment is licensed.

Acceptance criteria:

- Add license configuration.
- Implement signed license DTO and reader.
- Verify signature using a public key resolved by `kid`.
- Enforce an algorithm allowlist.
- Detect missing, unreadable, malformed, tampered, expired, not-yet-valid, wrong-environment, and wrong-deployment licenses.

Definition of done:

- Valid license verifies.
- Tampered license fails.
- Private signing key is not present in TSMS runtime.
- Unit tests cover success and failure cases.

## TSMS-LIC-RDC-003: Add Deployment Identity and Fingerprint

As the vendor, I need TSMS to recognize the approved deployment identity so a copied application/database cannot silently operate elsewhere.

Acceptance criteria:

- Add `deployment_metadata`.
- Store/generate `application_installation_uuid` and `database_instance_uuid`.
- Implement `DeploymentFingerprintService`.
- Version fingerprint format with `fingerprint_version`.
- Store/report fingerprint hash and comparison result, not raw fingerprint components.
- Classify drift as expected, non-material, material, critical, or not evaluated.

Definition of done:

- Same deployment produces expected fingerprint.
- Changed stable identity is detected.
- Raw fingerprint inputs are not exposed.

## TSMS-LIC-RDC-004: Implement LicenseService

As TSMS, I need one service responsible for license validation so controllers and middleware do not read license files directly.

Acceptance criteria:

- Validate file, schema, signature, date range, environment, deployment ID, location, fingerprint, superseded state, and revoked key state.
- Return structured status and canonical safe reason code.
- Expose safe diagnostics only.

Definition of done:

- No controller reads license files directly.
- Safe status excludes signatures, keys, tokens, env values, and raw fingerprint inputs.

## TSMS-LIC-RDC-005: Add License Audit Logging

As vendor support, I need an audit trail of validation failures, observe violations, denials, recovery requests, replacements, and vendor action attempts.

Acceptance criteria:

- Add `license_audit_logs`.
- Implement `LicenseAuditLogger`.
- Capture mode, actor, vendor approver, reason code, token hash, request ID hash, fingerprint hash, and correlation ID where applicable.
- Sanitize private keys, raw tokens, terminal secrets, env values, raw fingerprint inputs, full signatures, and database credentials.

Definition of done:

- Every violation/denial is logged.
- Audit logs include safe reason code and context.
- Redaction tests pass.

## TSMS-LIC-RDC-006: Add License Middleware and Modes

As TSMS, I need protected routes to honor licensing mode without causing immediate production breakage.

Acceptance criteria:

- Implement `LicenseMiddleware`.
- Register `license.valid`.
- Support `disabled`, `observe`, `restricted`, and `enforce`.
- Observe mode executes validation and logs violations without blocking normal POS intake.
- Cryptographically invalid or replayed vendor actions are rejected in every mode.
- Do not block login, health, license status, diagnostics, recovery request, or approved recovery execution.

Definition of done:

- Observe logs but does not block protected business flow.
- Restricted/enforce blocks invalid protected operations according to the operations matrix.

## TSMS-LIC-RDC-007: Classify Routes

As a technical lead, I need routes classified before hard enforcement so recovery and diagnostic paths remain available.

Acceptance criteria:

- Maintain route classification.
- Classify routes as public, auth-only, license-diagnostic, license-protected, deprecated-or-remove, or needs-decision.
- Attach first observe wave to official POS intake, batch intake, refund, and void.
- Generate actual Laravel route inventory before production observe.

Definition of done:

- Route inventory is complete.
- First-wave protected routes are wired.
- Debug/test/legacy routes are identified for disposition under `TSMS-LIC-RDC-019`.

## TSMS-LIC-RDC-008: Add Binding Schema

As TSMS, I need tenants, terminals, and transactions bound to the licensed deployment/location.

Acceptance criteria:

- Add binding fields to tenants, terminals, and transactions.
- Include binding status fields such as `license_binding_status`, `license_bound_at`, and `license_bound_by` where approved.
- Include `terminal_binding_epoch` for terminals where supported.
- Add `terminal_activations` when required.
- Keep new fields nullable/backfillable for safe rollout.

Definition of done:

- Migrations run.
- Existing data is not hard-blocked before backfill.

## TSMS-LIC-RDC-009: Build Binding Audit and Backfill

As a deployment engineer, I need dry-run/apply tools to validate production readiness.

Acceptance criteria:

- Add `license:bindings:audit --json`.
- Add `license:bindings:backfill --json`.
- Add `license:bindings:backfill --apply --json`.
- Refuse backfill without required expected scope config.
- Do not blindly assign historical transactions to the current deployment.
- Support legacy or null attribution where provenance is uncertain.

Definition of done:

- Dry-run shows candidate counts/sample IDs.
- Apply requires explicit `--apply`.
- Historical attribution policy is approved before production enforcement.

## TSMS-LIC-RDC-010: Add Read-Only Vendor License Operations

As the vendor, I need safe license status, capabilities, diagnostics, audit, and recovery-request endpoints unavailable to client-admin-only users.

Acceptance criteria:

- Add status, capabilities, diagnostics, audit view/export, and recovery request generation endpoints.
- Protect with authentication, local operator permission, throttling, and audit where appropriate.
- Client `admin` role alone is denied.
- These endpoints do not require vendor action tokens unless they change license/deployment state.

Definition of done:

- Authorized operators can view safe diagnostics and generate recovery requests.
- Endpoints remain outside `license.valid`.
- No endpoint exposes raw fingerprint components or secrets.

## TSMS-LIC-RDC-011: Add Cryptographic Vendor Action Authorization

As the vendor, I need license-changing actions to require vendor-signed approval so client admins cannot create fake vendor accounts and approve redeployment.

Acceptance criteria:

- Require vendor-signed action token/package for install, replacement, recovery execution, redeployment, rebind, emergency unlock, and deployment identity rotation.
- Verify using trusted vendor public key resolved by `kid`.
- Validate `iss`, `aud`, `jti`, request ID, action, client ID, deployment ID, license ID, environment, location code, current fingerprint, target fingerprint where applicable, reason code, approver, `iat`, `nbf`, `exp`, nonce, and schema version.
- Reject generic action values such as `admin_action` or `override`.

Definition of done:

- Fake local vendor role cannot authorize license-changing actions.
- Invalid, expired, wrong-audience, wrong-algorithm, or replayed action token is rejected.
- Valid token authorizes only the intended action.

## TSMS-LIC-RDC-012: Implement POS Binding Enforcement

As TSMS, I need POS intake to reject invalid terminal/tenant/location bindings once enforcement is enabled.

Acceptance criteria:

- Verify terminal token, terminal status, terminal deployment/location, tenant deployment/location, terminal-to-tenant relationship, and request scope.
- Stamp new transactions with current `deployment_id`.
- Do not reject valid terminals solely because optional vendor heartbeat is unavailable.

Definition of done:

- Observe logs mismatches but allows intake.
- Restricted/enforce denies invalid bindings according to the approved operations matrix.
- Valid POS submissions continue.

## TSMS-LIC-RDC-013: Implement Vendor-Approved Recovery Flow

As vendor support, I need a manual recovery path for approved reinstall/redeploy/reuse events.

Acceptance criteria:

- Generate recovery request package without secrets.
- Recovery execution requires a vendor-signed action token.
- Where deployment identity or fingerprint changes, a newly issued replacement license establishes the resulting valid state.
- Previous license or deployment binding is superseded where applicable.
- Emergency temporary license use is separate, explicitly approved, time-limited, and audited.
- Prevent automatic self-reactivation.

Definition of done:

- Vendor-approved recovery restores protected operations.
- Expired or replayed recovery action is rejected.
- No recovery path acts as both command and standing license unless separately approved.

## TSMS-LIC-RDC-014: Implement Replay Consumption Ledger

As TSMS, I need an atomic replay ledger so signed vendor action tokens cannot be reused.

Acceptance criteria:

- Add `license_action_consumptions`.
- Store hashed `jti`, request ID, nonce, and token hash.
- Add unique constraints for `jti_hash`, `request_id_hash`, and `nonce_hash`.
- Consume replay identifiers and execute state transition in one transaction.
- Treat cache/Redis only as an optimization.

Definition of done:

- Concurrent replay tests pass.
- Observe mode rejects replayed vendor actions.
- Ledger retention policy is documented.

## TSMS-LIC-RDC-015: Implement Environment Trust Separation

As a release engineer, I need staging and production trust domains separated so staging licenses or action tokens cannot work in production.

Acceptance criteria:

- Use separate deployment IDs, license IDs, fingerprints, action-token audiences, secret stores, license files, and preferably signing keys.
- Production rejects staging licenses and staging action-token audience.
- Licenses are issued separately per environment and never promoted from staging to production.

Definition of done:

- Cross-environment negative tests pass.
- CI/CD does not copy license files or trust stores between staging and production.

## TSMS-LIC-RDC-016: Establish Signing-Key Trust Store and Rotation

As vendor security, I need a trust store and key lifecycle so license verification can support `kid`, rotation, revocation, and emergency replacement.

Acceptance criteria:

- Define `LicenseTrustStore`.
- Resolve public keys by `kid` and environment.
- Support active, previous verification-only, and emergency replacement keys.
- Define scheduled rotation, emergency revocation, and public-key distribution process.
- Reject revoked key IDs.

Definition of done:

- `LIC-KMP-001` is approved.
- Key rotation and key revocation tests pass.
- TSMS contains no private signing key.

## TSMS-LIC-RDC-017: Execute Production Migration Profiling and Rehearsal

As DevOps/DBA, I need production-scale migration evidence before deployment identity backfill reaches enforcement.

Acceptance criteria:

- Profile MySQL version, table size, row count, current indexes, peak writes, free disk, and backup/restore timing.
- Rehearse expand, dual-write, chunked backfill, index validation, and rollback using production-scale data.
- Define historical attribution strategy.
- Avoid `NOT NULL` constraints until historical handling is approved.

Definition of done:

- `LIC-MIG-001` is approved.
- Rehearsal evidence is attached to release gate.

## TSMS-LIC-RDC-018: Define Restricted-Mode and Offline Operations Matrix

As PITX IT and vendor operations, we need a clear restricted-mode behavior matrix that preserves availability without weakening redeployment control.

Acceptance criteria:

- Define behavior for valid local license with vendor unreachable.
- Define heartbeat windows and alerting.
- Define whether read-only reports continue.
- Define terminal authentication behavior.
- Define operations that continue, block, or require vendor action token.
- Include vendor-outage test.

Definition of done:

- `LIC-OPS-001` is approved by PITX IT, Finance where reporting is affected, and vendor operations.

## TSMS-LIC-RDC-019: Complete Production Route Disposition

As security reviewer, I need every production route reviewed and disposed so debug/legacy paths cannot bypass licensing.

Acceptance criteria:

- Run actual Laravel route inventory.
- Remove, environment-gate, or protect debug/test routes.
- Define exact authorization for retained license routes.
- Align license endpoints under versioned API namespace where approved.
- Add unauthorized route tests.

Definition of done:

- `LIC-RTE-001` is approved.
- No prohibited production route remains publicly accessible.

## TSMS-LIC-RDC-020: Pilot, UAT, and Production Enforcement Readiness

As release manager, I need accountable gates before restricted/enforce mode so production promotion is controlled.

Acceptance criteria:

- Define RACI and named release authority.
- Enforce staging observe, production observe, restricted pilot, controlled enforcement, and full enforcement gates.
- Include rollback runbook with Laravel config-cache refresh and verification.
- Collect evidence for architecture, security, data, operations, business, contract, and go/no-go gates.

Definition of done:

- `LIC-ROL-001` is approved.
- Production restricted/enforce mode has signed approval from named authorities.
