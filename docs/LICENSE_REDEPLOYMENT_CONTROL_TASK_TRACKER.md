# TSMS License Redeployment Control Task Tracker

Last updated: 2026-06-29

## Objective

Implement Release 1 as a redeployment-control perimeter around the existing TSMS application.

The goal is to prevent a copied, restored, or reused TSMS deployment from operating outside the approved client, deployment, and licensed location without rewriting current POS intake, reporting, tenant management, terminal management, or admin workflows.

## Guiding Rule

```text
No tenant, terminal, transaction intake, report, export, or protected operation may run unless it belongs to the current valid license, licensed deployment, and licensed location.
```

## Release Boundary

### Release 1: MVP Redeployment Control

Included:

- Signed license verification
- Deployment identity and fingerprint
- LicenseService
- LicenseMiddleware
- License audit logs
- Tenant deployment/location/license binding
- Terminal deployment/location/license binding
- POS intake enforcement
- License status endpoint
- License upload endpoint
- Recovery request endpoint
- Observe-only rollout before hard blocking
- Manual vendor-approved recovery

### Release 2: Full Licensing Platform

Postponed:

- Module packaging UI
- Advanced entitlement plans
- Billing/subscription rules
- Online license server
- Polished license dashboard
- Per-feature commercial limits
- Advanced recovery UX

## Enforcement Modes

The implementation must support these modes from day one:

```text
disabled   No checks are applied.
observe    Checks run and violations are audited, but requests continue.
restricted Protected operations are blocked when license/deployment scope is invalid; diagnostics and recovery remain available.
enforce    Full blocking for invalid license, deployment, location, tenant, or terminal scope.
```

Initial production rollout mode:

```env
LICENSE_ENFORCEMENT_MODE=observe
```

## Route Classification Tracker

Every route must be classified before hard enforcement is enabled.

Status legend:

```text
Not started
In progress
Complete
Blocked
```

| Area | Current Route Surface | Classification | Status | Notes |
|---|---|---|---|---|
| Login/logout | `/login`, `/api/auth/*` | Auth-only / diagnostic-safe | Complete | Must remain accessible enough for admin recovery. |
| Health checks | `/api/v1/health`, `/up`, simple status routes | Public/diagnostic | Complete | Should not require a valid license. |
| License diagnostics | `/api/license/status`, `/api/license/capabilities` | License-diagnostic | Complete | Vendor-license-authority only via `auth:sanctum`, `license.vendor:view`, throttled, outside future license enforcement middleware. |
| License replacement | `/api/license/upload` | License-diagnostic, vendor-only | Complete | Vendor-license-authority only via `auth:sanctum`, `license.vendor:upload`, throttled, validates before atomic private replacement. |
| Recovery request | `/api/license/recovery-request` | License-diagnostic, vendor-only | Complete | Vendor-license-authority only via `auth:sanctum`, `license.vendor:recovery_request`, throttled, outside future license enforcement middleware. |
| POS intake | `/api/v1/transactions/official`, `/api/v1/transactions/batch` | License-protected | Complete | `license.valid` attached in first observe wave. |
| POS refund/void | `/api/v1/transactions/{id}/refund`, `/api/v1/transactions/{id}/void` | License-protected | Complete | `license.valid` attached in first observe wave. |
| Terminal auth | `/api/v1/auth/terminal`, refresh, heartbeat, me | Mixed | Complete | Keep auth-only initially; heartbeat policy needs confirmation. |
| Terminal management | terminal token and terminal admin routes | License-protected | Complete | Protect after POS observe pass and binding plan. |
| Tenant management | tenant CRUD routes | License-protected | Complete | Protect after binding migrations/backfill are ready. |
| Reports | finance/commercial/report APIs and exports | License-protected | Complete | Protect after tenant/location binding fields exist. |
| Transaction logs | transaction log APIs and exports | License-protected | Complete | Protect after first POS observe pass. |
| Admin settings | `/admin/settings`, users, RBAC | License-protected | Complete | Some support/ops surfaces remain open decisions. |
| Sandbox/testing endpoints | parser, payload validator, demo/debug routes | Public/diagnostic/deprecated | Complete | Remove or disable debug/test routes before restricted/enforce. |
| Legacy routes | legacy V1/public transaction endpoints | Deprecated/remove or protected | Complete | Remove, disable, or explicitly protect before restricted/enforce. |

## Phase Tracker

### Phase 0: Policy Confirmation

Status: Not started

Tasks:

- [ ] Confirm production `client_id`.
- [ ] Confirm production `deployment_id`.
- [ ] Confirm production `license_id`.
- [ ] Confirm production `location_code`.
- [ ] Confirm initial license validity dates.
- [x] Confirm client admin role is not allowed to upload replacement licenses; vendor license authority is required.
- [ ] Confirm behavior for heartbeat/auth routes during restricted mode.
- [ ] Confirm whether staging uses separate license/deployment IDs.

Expected PITX defaults to validate:

```text
deployment_id = MWM-PITX-MANILA-PROD-001
location_code = PITX_MANILA
license_id = LIC-MWM-PITX-001
```

Deliverables:

- [ ] Final Release 1 license policy values.
- [ ] Route enforcement policy decision record.

### Phase 1: License Foundation

Status: In progress

Tasks:

- [x] Add `config/license.php`.
- [x] Add `LICENSE_ENFORCEMENT_MODE` config support.
- [x] Add private license file path config.
- [x] Add public verification key path/config.
- [x] Implement signed license DTO/value object.
- [x] Implement `SignedLicenseReader`.
- [x] Implement canonical payload serialization.
- [x] Implement signature verification.
- [x] Add schema validation.
- [x] Add valid, expired, tampered, and wrong-environment test fixtures.

Acceptance criteria:

- [x] Valid signed license parses and verifies.
- [x] Tampered license fails verification.
- [x] Missing/unreadable license returns safe reason code.
- [x] Private key is not required or present in TSMS runtime.

### Phase 2: Deployment Identity

Status: In progress

Tasks:

- [x] Add `deployment_metadata` migration/model.
- [x] Generate or store `application_installation_uuid`.
- [x] Generate or store `database_instance_uuid`.
- [x] Implement `DeploymentFingerprintService`.
- [x] Hash stable fingerprint inputs.
- [x] Capture soft diagnostics without making them hard blockers.
- [x] Add copy-suspected detection.

Hard fingerprint inputs:

- `deployment_id`
- `application_installation_uuid`
- `database_instance_uuid`
- `environment`
- approved server identifier, if configured

Soft diagnostic inputs only:

- hostname
- private IP
- public IP
- MAC address
- cloud instance ID

Acceptance criteria:

- [x] Same approved deployment produces expected fingerprint.
- [ ] Wrong deployment ID is detected.
- [x] Changed stable identity is detected.
- [x] IP/hostname/MAC changes do not hard-block unless policy enables them.

### Phase 3: LicenseService

Status: In progress

Tasks:

- [x] Implement `App\Services\Licensing\LicenseService`.
- [x] Validate license file existence/readability.
- [x] Validate schema.
- [x] Verify signature.
- [x] Validate `not_before`.
- [x] Validate `expires_at`.
- [x] Validate environment.
- [x] Validate deployment ID.
- [x] Validate fingerprint.
- [x] Expose safe status for diagnostics.
- [ ] Cache validation result briefly.
- [ ] Invalidate cache on license upload.

Required reason codes:

- `LICENSE_VALID`
- `LICENSE_FILE_MISSING`
- `LICENSE_FILE_UNREADABLE`
- `LICENSE_SCHEMA_INVALID`
- `LICENSE_SIGNATURE_INVALID`
- `LICENSE_NOT_YET_VALID`
- `LICENSE_EXPIRED`
- `LICENSE_ENVIRONMENT_MISMATCH`
- `LICENSE_DEPLOYMENT_ID_MISMATCH`
- `LICENSE_SERVER_FINGERPRINT_MISMATCH`
- `LICENSE_VALIDATION_EXCEPTION`
- `CLOCK_ROLLBACK_SUSPECTED`
- `COPY_SUSPECTED`

Acceptance criteria:

- [x] No controller reads license files directly.
- [x] Service returns structured status and reason code.
- [x] Client-facing response does not expose sensitive details.

### Phase 4: Audit Logging

Status: In progress

Tasks:

- [x] Add `license_audit_logs` migration/model.
- [x] Implement `LicenseAuditLogger`.
- [x] Add sensitive-data sanitizer.
- [x] Log validation failures.
- [x] Log observe-only violations.
- [x] Log enforcement denials.
- [x] Log license replacement.
- [x] Log recovery request generation.

Sensitive values that must not be logged:

- private keys
- raw tokens
- terminal secrets
- `.env` values
- raw fingerprint inputs
- full license signature
- database credentials

Acceptance criteria:

- [x] Every denial or violation has an audit log.
- [x] Audit logs include reason code and safe context.
- [x] Audit logs do not contain secrets.

### Phase 5: Observe-Only Checks

Status: In progress

Tasks:

- [x] Add observe checks for license validity.
- [ ] Add observe checks for deployment mismatch.
- [ ] Add observe checks for tenant binding.
- [ ] Add observe checks for terminal binding.
- [ ] Add observe checks for location binding.
- [x] Add observe checks to POS intake path.
- [ ] Add observe checks to report/export paths.
- [ ] Add audit report/query for observed violations.

Acceptance criteria:

- [x] In `observe`, invalid scope is logged but not blocked.
- [x] POS intake continues while violations are collected.
- [ ] Observed violations are actionable for data cleanup.

### Phase 6: Data Backfill and Validation

Status: In progress

Tasks:

- [x] Add tenant binding columns.
- [x] Add terminal binding columns.
- [x] Add transaction `deployment_id` column.
- [x] Add `terminal_activations` table if terminal activation history is required for Release 1.
- [x] Build dry-run/apply backfill command.
- [ ] Backfill active tenants.
- [ ] Backfill active terminals.
- [ ] Backfill existing transactions where feasible.
- [x] Build validation command/report for missing bindings.
- [x] Build validation command/report for out-of-scope locations.

Required backfill fields:

- `tenants.location_code`
- `tenants.deployment_id`
- `tenants.license_id`
- `pos_terminals.location_code`
- `pos_terminals.deployment_id`
- `pos_terminals.license_id`
- `pos_terminals.activation_status`
- `transactions.deployment_id`

Acceptance criteria:

- [ ] No active tenant is missing required binding fields.
- [ ] No active terminal is missing required binding fields.
- [ ] Active terminals are linked to valid tenants.
- [ ] Backfill has rollback/restore notes.

### Phase 7: Middleware and Route Enforcement

Status: In progress

Tasks:

- [x] Implement `LicenseMiddleware`.
- [x] Register `license.valid` route middleware alias.
- [x] Protect POS intake routes.
- [ ] Protect tenant management routes.
- [ ] Protect terminal management routes.
- [ ] Protect report/export routes.
- [ ] Protect transaction log routes.
- [ ] Protect admin/user/settings routes.
- [ ] Keep login, health, license status, license upload, and recovery request accessible.
- [ ] Add route inventory verification.

Acceptance criteria:

- [ ] Protected route succeeds with valid scope.
- [x] Protected route logs in observe mode.
- [x] Protected route blocks in restricted/enforce mode.
- [x] Diagnostic routes remain accessible.

### Phase 8: POS Intake Binding Enforcement

Status: Not started

Tasks:

- [ ] Add service-level binding check before official transaction processing.
- [ ] Verify terminal token belongs to declared terminal.
- [ ] Verify terminal is active.
- [ ] Verify terminal deployment matches current deployment.
- [ ] Verify terminal location is licensed.
- [ ] Verify tenant deployment matches current deployment.
- [ ] Verify tenant location is licensed.
- [ ] Verify terminal belongs to declared tenant.
- [ ] Stamp new transactions with current `deployment_id`.

Acceptance criteria:

- [ ] Existing POS processing logic is minimally changed.
- [ ] Wrong-deployment terminal is denied in restricted/enforce mode.
- [ ] Unlicensed-location terminal is denied in restricted/enforce mode.
- [ ] Valid terminal continues to submit transactions.

### Phase 9: License Admin Endpoints

Status: In progress

Tasks:

- [x] Add `GET /api/license/status`.
- [x] Add `GET /api/license/capabilities`.
- [x] Add `POST /api/license/upload`.
- [x] Add `POST /api/license/recovery-request`.
- [x] Add admin-only authorization.
- [x] Add rate limiting.
- [x] Validate upload type and size.
- [x] Validate replacement license before activating it.
- [x] Store license outside public web root.
- [x] Audit license replacement.

Acceptance criteria:

- [x] Status endpoint exposes only safe diagnostics.
- [x] Upload rejects invalid/tampered license.
- [x] Recovery request package excludes secrets.
- [ ] Endpoints remain accessible in restricted mode to authorized admins.

### Phase 10: Recovery Flow

Status: In progress

Tasks:

- [ ] Define temporary recovery license schema.
- [ ] Support `license_type=emergency_recovery`.
- [ ] Validate original and recovery deployment IDs.
- [ ] Enforce recovery expiry.
- [x] Generate recovery request package.
- [ ] Audit recovery license usage.
- [ ] Document manual vendor approval process.

Acceptance criteria:

- [ ] Mismatch can enter restricted mode.
- [ ] Admin can generate recovery request.
- [ ] Valid vendor-issued recovery license restores protected operations.
- [ ] Expired recovery license blocks protected operations.
- [ ] No automatic self-reactivation exists.

### Phase 11: Full Enforcement Rollout

Status: Not started

Tasks:

- [ ] Deploy with `LICENSE_ENFORCEMENT_MODE=disabled`.
- [ ] Install signed license and deployment metadata.
- [ ] Switch to `observe`.
- [ ] Review audit logs for several business days.
- [ ] Complete data cleanup/backfill.
- [ ] Switch staging to `restricted`.
- [ ] Switch production to `restricted`.
- [ ] Switch production to `enforce` after validation.

Acceptance criteria:

- [ ] No critical POS/report/admin regressions in observe mode.
- [ ] No unreviewed violations remain before restricted mode.
- [ ] Production has documented rollback steps.

## Test Tracker

Status: Not started

Required tests:

- [ ] Valid signed license allows protected route.
- [ ] Missing license blocks in enforce mode.
- [ ] Missing license logs only in observe mode.
- [ ] Expired license blocks protected route.
- [ ] Tampered license returns `LICENSE_SIGNATURE_INVALID`.
- [ ] Wrong deployment ID blocks protected route.
- [ ] Fingerprint mismatch blocks or enters restricted mode.
- [ ] Tenant without deployment binding is logged/blocked by mode.
- [ ] Terminal without deployment binding is logged/blocked by mode.
- [ ] Terminal from unlicensed location is denied.
- [ ] POS intake rejects wrong-deployment terminal.
- [ ] Reports reject wrong-deployment tenant.
- [ ] License upload rejects invalid/tampered file.
- [ ] Recovery request excludes secrets.
- [ ] Audit logs do not contain raw tokens/signatures/env values.

## Open Questions

- [ ] What exact PITX production `client_id`, `deployment_id`, `license_id`, and `location_code` should be used?
- [ ] Should heartbeat be license-protected, observe-only, or allowed during restricted mode?
- [ ] Should terminal authentication be allowed during restricted mode but terminal activation blocked?
- [ ] Are demo/debug/test endpoints deployed in production, and should any be removed before enforcement?
- [ ] Should legacy routes be disabled, protected, or formally deprecated?
- [ ] What role/permission should allow license upload and recovery request generation?
- [ ] How long should observe mode run before restricted mode?
- [ ] Is `terminal_activations` required in Release 1, or can terminal activation history be deferred?

## Current Change Log

- 2026-06-29: Created tracker for Release 1 redeployment-control implementation.
- 2026-06-29: Added license redeployment control config and env template entries.
- 2026-06-29: Added signed license DTO, reader, canonicalization, Ed25519 verification, schema validation, and focused unit tests.
- 2026-06-29: Added deployment metadata migration/model and initial deployment fingerprint service.
- 2026-06-29: Added core LicenseService validation for file/schema/signature/date/environment/deployment/fingerprint checks with unit tests.
- 2026-06-29: Added copy-suspected fingerprint assessment plus dedicated license audit log migration/model/logger with sanitizer tests.
- 2026-06-29: Wired LicenseService to optionally audit validation failures.
- 2026-06-29: Added admin-only, throttled license status and capabilities API endpoints with safe response whitelisting.
- 2026-06-29: Added admin-only recovery request endpoint and safe vendor recovery package generation.
- 2026-06-29: Added admin-only license upload endpoint with candidate validation and atomic private license replacement.
- 2026-06-29: Added `LicenseMiddleware` with disabled/observe/restricted/enforce behavior and registered `license.valid` alias without attaching protected routes yet.
- 2026-06-29: Added route classification document for license middleware rollout: `docs/LICENSE_ROUTE_CLASSIFICATION.md`.
- 2026-06-29: Attached `license.valid` to first-wave POS mutation routes in observe-ready middleware group.
- 2026-06-29: Added nullable tenant, terminal, and transaction license binding schema plus terminal activation history model/table.
- 2026-06-29: Added read-only `license:bindings:audit` command for schema and missing binding readiness checks.
- 2026-06-29: Extended `license:bindings:audit` with out-of-scope location/deployment/license mismatch checks.
- 2026-06-29: Added `license:bindings:backfill` dry-run/apply command with explicit scope/schema precondition checks.
- 2026-06-29: Applied licensing schema migrations locally, ran dry-run backfill preview, applied local tenant binding with expected PITX scope values, and verified local audit reports zero issues. Production/business backfill remains open pending confirmed scope values.
- 2026-06-29: Replaced client-admin license authority with vendor-only `license.vendor` middleware for license status, upload, and recovery operations.
