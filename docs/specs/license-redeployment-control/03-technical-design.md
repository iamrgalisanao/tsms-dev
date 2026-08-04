# Technical Design

Document ID: `TSMS-LIC-RDC-DESIGN`
Status: Reviewed - aligned to feature specification
Last updated: 2026-07-20

## Architecture

Release 1 adds a backend licensing perimeter around existing TSMS flows. It uses service and middleware checks instead of rewriting POS intake, reporting, export, tenant, terminal, or admin controllers.

Controllers must not own licensing policy. Policy belongs in services, middleware, command handlers, and narrowly scoped validators.

## Components

| Component | Responsibility |
|---|---|
| `LicenseIdentity` / identity DTOs | Canonical client, deployment, license, location, and environment values. |
| `LicenseEnvironment` enum | Canonical environment values: development, test, staging, production, disaster-recovery. |
| `LicenseMode` enum | Canonical enforcement modes: disabled, observe, restricted, enforce. |
| `LicenseReasonCode` enum | Canonical safe validation outcomes. |
| `LicenseTrustStore` | Resolves approved public keys by `kid` and environment. |
| `LicenseKeyRevocationRegistry` | Rejects revoked key IDs. May be config-backed for Release 1. |
| `SignedLicenseReader` | Reads and verifies signed license envelopes. |
| `SignedLicense` | License value object. |
| `LicenseService` | Validates license status, date, environment, deployment, location, fingerprint, key state, and safe reason code. |
| `DeploymentFingerprintService` | Creates versioned deployment fingerprint and soft diagnostics. |
| `VendorActionTokenReader` | Parses vendor action tokens and verifies signature envelope. |
| `VendorActionAuthorizationService` | Validates action-specific claims and binding rules. |
| `LicenseActionConsumptionRepository` | Atomically consumes `jti`, request ID, and nonce. |
| `LicenseAuditLogger` | Writes sanitized license audit events. |
| `LicenseMiddleware` | Applies disabled/observe/restricted/enforce behavior to protected routes. |
| `EnsureLocalLicenseOperator` | Restricts local access to license endpoints; does not prove vendor authority. |
| `LicenseController` | Provides safe status, capabilities, diagnostics, recovery request, and action endpoints. |
| `LicenseReplacementService` | Validates, stages, and activates replacement licenses. |
| `LicenseRecoveryService` | Executes approved recovery/rebinding transitions. |
| `LicenseBindingService` | Central tenant/terminal binding evaluation. |
| `RestrictedModePolicy` | Determines allowed operations in restricted mode. |
| `LicenseBindingAuditCommand` | Reports missing/out-of-scope binding data. |
| `LicenseBindingBackfillCommand` | Dry-run/apply binding backfill with historical attribution safeguards. |
| `LicenseRouteClassifier` | Optional validation support for route classification. |
| `DeploymentMigrationMetrics` | Tracks backfill and new-write completeness. |

## Data Model

Required schema additions or confirmed fields:

### `deployment_metadata`

```text
id
client_id
deployment_id
environment
location_code
application_installation_uuid
database_instance_uuid
fingerprint_version
fingerprint_hash
created_at
updated_at
```

### `license_audit_logs`

```text
event_id
event_type
severity
mode
client_id
deployment_id
license_id
environment
location_code
tenant_id
terminal_id
actor_id
actor_type
vendor_approver
request_id_hash
token_hash
fingerprint_hash
reason_code
result
timestamp_utc
source_ip
user_agent
correlation_id
```

### `license_action_consumptions`

```text
id
jti_hash
request_id_hash
nonce_hash
action
client_id
deployment_id
license_id
environment
issued_at
expires_at
consumed_at
result
token_hash
created_at
```

Required unique constraints:

```text
UNIQUE(jti_hash)
UNIQUE(request_id_hash)
UNIQUE(nonce_hash)
```

### Trust Store

The key registry may be file/config-backed in Release 1, but its structure must support:

```text
kid
environment
algorithm
public_key
status
valid_from
valid_until
revoked_at
```

### Tenant Binding Fields

```text
tenants.deployment_id
tenants.location_code
tenants.license_id
tenants.license_binding_status
tenants.license_bound_at
tenants.license_bound_by
```

### Terminal Binding Fields

```text
pos_terminals.deployment_id
pos_terminals.location_code
pos_terminals.license_id
pos_terminals.activation_status
pos_terminals.terminal_binding_epoch
pos_terminals.license_binding_status
pos_terminals.license_bound_at
pos_terminals.license_bound_by
pos_terminals.activated_at
pos_terminals.revoked_at
```

### Transaction Attribution

```text
transactions.deployment_id
```

New binding fields must remain nullable until backfill, historical attribution, and observe validation are complete.

## Runtime Configuration

Environment variables are expected runtime constraints, not the authoritative active license record.

Recommended:

```env
LICENSE_ENABLED=true
LICENSE_ENFORCEMENT_MODE=observe
LICENSE_EXPECTED_CLIENT_ID=cli_...
LICENSE_EXPECTED_DEPLOYMENT_ID=dep_...
LICENSE_EXPECTED_LOCATION_CODE=PH-PITX-MAIN
LICENSE_EXPECTED_ENVIRONMENT=production
LICENSE_FILE_PATH=storage/app/private/license.json
LICENSE_TRUST_STORE_PATH=storage/app/private/license-trust-store.json
LICENSE_LOCAL_OPERATOR_ROLES=vendor_support,license_manager
LICENSE_PERMISSION_STATUS_VIEW=license.status.view
LICENSE_PERMISSION_DIAGNOSTICS_VIEW=license.diagnostics.view
LICENSE_PERMISSION_AUDIT_VIEW=license.audit.view
LICENSE_PERMISSION_RECOVERY_REQUEST=license.recovery.request
LICENSE_PERMISSION_INSTALL=license.install
LICENSE_PERMISSION_REPLACE=license.replace
LICENSE_PERMISSION_REBIND=license.rebind
LICENSE_PERMISSION_EMERGENCY_UNLOCK=license.emergency_unlock
```

Do not use `LICENSE_ID` as a manually synchronized runtime constraint. The active signed artifact provides the active `license_id`, and the ID changes on every issuance or replacement.

Do not use a single `LICENSE_PUBLIC_KEY_PATH` for production rotation. Use a trust store that resolves keys by `kid`.

## Route Policy

Do not attach `license.valid` globally.

First observe wave:

- `POST /api/v1/transactions/official`
- `POST /api/v1/transactions/batch`
- `POST /api/v1/transactions/{transaction_id}/refund`
- `POST /api/v1/transactions/{transaction_id}/void`

Preferred license route namespace for new endpoints:

```text
GET  /api/v1/license/status
GET  /api/v1/license/capabilities
GET  /api/v1/license/diagnostics
POST /api/v1/license/recovery-requests
POST /api/v1/license/installations
POST /api/v1/license/replacements
POST /api/v1/license/recovery-actions
POST /api/v1/license/deployment-rebindings
```

Each endpoint must declare:

- authentication middleware;
- local permission;
- vendor action-token requirement, if state-changing;
- rate limit;
- audit event;
- route classification;
- restricted-mode availability.

Read-only status/diagnostic/recovery-request endpoints require local permission but not a vendor action token. Installation, replacement, recovery execution, rebind, emergency unlock, and identity rotation require local permission plus vendor-signed action token.

## Recovery Model

Use vendor action token plus replacement license artifact as the standard recovery model:

```text
Recovery request
-> vendor action token authorizes operation
-> deployment/fingerprint binding changes
-> replacement license installed when required
-> previous license superseded
```

Do not make `license_type=emergency_recovery` behave as both command and standing license. Exceptional temporary emergency licenses require separate approval, explicit expiry, and audit.

## Security Constraints

- Backend enforcement is authoritative.
- Frontend hiding is optional only.
- Controllers must not read license files directly.
- Local RBAC identifies a local operator but does not prove vendor authority.
- License-changing operations require vendor-signed action authorization.
- Do not log private keys, raw tokens, terminal secrets, env values, raw fingerprint inputs, full signatures, or database credentials.
- Do not hard-block on IP, hostname, MAC address, or other unstable signals by default.
- Login, health, diagnostics, recovery request, and approved recovery execution paths must remain available according to route policy.

