# Feature Specification

Document ID: `TSMS-LIC-RDC-SPEC`
Status: Reviewed - observe-mode implementation approved; restricted/enforce mode blocked pending authorization package
Last updated: 2026-07-20
Parent Epic: `TSMS License Redeployment Control`
Related Validation: `TSMS-LIC-RDC-VALIDATION`

## 1. Feature Summary

TSMS License Redeployment Control establishes a Release 1 licensing and deployment-security perimeter around the existing TSMS application.

The feature is intended to detect and prevent unauthorized:

- copying of the TSMS application;
- restoration of TSMS databases into another environment;
- reinstallation on unapproved infrastructure;
- redeployment to another server or location;
- reuse of an existing deployment identity;
- use of staging licenses in production;
- reassignment of tenants or terminals outside the approved deployment;
- locally authorized license changes that do not carry vendor-signed approval.

Release 1 is limited to deployment protection, cryptographic verification, binding enforcement, diagnostics, audit logging, and controlled recovery.

Release 1 does not implement a full commercial licensing, billing, subscription, or entitlement platform.

## 2. Business Value

The feature provides the following business value:

1. Protects vendor intellectual property and deployment agreements.
2. Prevents unauthorized operation of copied or restored TSMS environments.
3. Creates technical evidence for suspected unlicensed or mismatched deployments.
4. Prevents locally created administrator accounts from overriding vendor-controlled licensing decisions.
5. Reduces production risk through observe-first deployment.
6. Preserves existing POS intake, reporting, tenant management, terminal management, and administrative workflows during initial validation.
7. Establishes a controlled recovery process for approved server replacement, disaster recovery, reinstall, and redeployment.
8. Creates the technical foundation for a future licensing platform without including billing or advanced entitlement functionality in Release 1.

## 3. Architectural Principles

### 3.1 Availability Principle

```text
A valid locally signed license permits continued transaction operations.

Vendor authorization is required only for security-sensitive state changes such as activation, rebinding, recovery, replacement, reinstall, and redeployment.
```

### 3.2 Binding Principle

Every protected operation must be evaluated against the active:

- client identity;
- license identity;
- deployment identity;
- environment;
- licensed location;
- deployment fingerprint;
- tenant binding;
- terminal binding.

### 3.3 Observe-First Principle

Production enforcement must not start directly in restricted or enforce mode.

The required initial setting is:

```env
LICENSE_ENFORCEMENT_MODE=observe
```

Observe mode must execute the same validation logic intended for enforcement, but violations are logged rather than blocked.

### 3.4 Vendor Authorization Principle

Local TSMS accounts, including locally created accounts assigned vendor-like roles, must not independently authorize:

- license installation;
- license replacement;
- deployment rebinding;
- location rebinding;
- fingerprint override;
- recovery approval;
- emergency unlock;
- deployment identity rotation.

These operations require valid vendor-signed action authorization.

### 3.5 Fail-Safe Principle

A vendor connectivity outage must not automatically invalidate:

- a cryptographically valid local license;
- valid terminal credentials;
- previously approved tenant and terminal bindings.

Vendor availability and local license validity are separate security conditions.

## 4. Scope

### 4.1 Release 1 Included

Release 1 includes:

- signed license verification;
- canonical license identity;
- deployment identity;
- deployment fingerprint generation and comparison;
- environment binding;
- location binding;
- `LicenseService`;
- `LicenseMiddleware`;
- license audit logs;
- tenant deployment, location, and license binding;
- terminal deployment, location, and license binding;
- transaction deployment attribution;
- POS intake validation;
- license status endpoint;
- controlled license installation endpoint;
- recovery request generation;
- vendor-signed action-token verification;
- replay protection for vendor actions;
- observe-only production rollout;
- restricted pilot support;
- controlled enforcement;
- manual vendor-approved activation, recovery, replacement, and redeployment;
- diagnostic and recovery access during restricted mode;
- emergency rollback through enforcement-mode configuration;
- migration and backfill support for deployment identity;
- production route inventory and cleanup.

### 4.2 Release 1 Excluded

Release 1 does not include:

- billing or subscription rules;
- automated invoicing;
- module-specific commercial entitlements;
- advanced entitlement plans;
- per-feature usage limits;
- license-purchase workflow;
- self-service customer license portal;
- polished license dashboard;
- public online license server;
- continuous vendor dependency for POS transaction processing;
- automated renewal billing;
- advanced recovery user experience;
- 24/7 vendor monitoring unless separately covered by an SLA;
- commercial support beyond the applicable contract or maintenance agreement.

### 4.3 Scope-Control Rule

Any requirement for the following must be evaluated as a Release 2 feature or formal change request:

- real-time online activation;
- continuous remote license revocation;
- online subscription validation;
- central customer-facing licensing portal;
- automated commercial entitlement assignment;
- live vendor heartbeat as a mandatory dependency for transaction intake;
- multi-client licensing administration UI.

## 5. Canonical License Identity

The following identity formats are the recommended Release 1 standard.

| Field | Format | Purpose | Rotation Rule |
|---|---|---|---|
| `LICENSE_CLIENT_ID` | `cli_<uuidv7>` | Identifies the authorized client organization. | Immutable for the client relationship. |
| `LICENSE_DEPLOYMENT_ID` | `dep_<uuidv7>` | Identifies one approved deployment lifecycle. | Rotates for unapproved move, new installation, or formally replaced deployment. |
| `LICENSE_ID` | `lic_<uuidv7>` | Identifies one signed license artifact. | New value for every issuance or replacement. |
| `LICENSE_LOCATION_CODE` | Controlled uppercase code such as `PH-PITX-MAIN`. | Identifies the approved business location. | Changes only through approved relocation. |
| `LICENSE_ENVIRONMENT` | Strict enum. | Identifies the license trust environment. | Requires a newly issued license. |

Allowed environment values:

```text
development
test
staging
production
disaster-recovery
```

Aliases such as `prod`, `prd`, or `live` must not be accepted.

### 5.1 Identity Relationships

```text
One client may have multiple deployments.

One deployment may have multiple successively issued licenses.

One license ID identifies exactly one signed license artifact.
```

### 5.2 Storage Requirements

Canonical UUID values should be exposed as text at API and configuration boundaries.

Recommended MySQL representation:

```text
BINARY(16)
```

Human-readable names such as `MWM-PITX` may be stored as labels, but they must not replace the canonical security identifiers.

## 6. Staging and Production Separation

Staging and production must use separate:

- deployment IDs;
- license IDs;
- infrastructure fingerprints;
- action-token audiences;
- secret stores;
- database environments;
- configuration namespaces;
- license files;
- signing keys, where operationally feasible.

### 6.1 Required Runtime Check

```text
runtime environment
must exactly equal
signed license environment
```

A production system must reject a staging license even when the client identity or location label matches.

### 6.2 Required Action-Token Audiences

```text
tsms-license-staging
tsms-license-production
```

Production must not accept tokens issued for the staging audience.

### 6.3 Promotion Rule

A staging license is never promoted or copied to production.

Production deployment requires:

1. Approval of the production environment.
2. Generation of a production deployment identity.
3. Capture of the production deployment fingerprint.
4. Issuance of a new production license.
5. Installation using an audited process.
6. Verification under observe mode.

## 7. Enforcement Modes

```text
disabled
observe
restricted
enforce
```

### 7.1 Disabled Mode

No blocking license checks are applied.

The system may still expose non-blocking diagnostics and configuration validation.

Disabled mode is intended for:

- emergency rollback;
- controlled maintenance;
- explicitly approved troubleshooting.

Disabled mode must not delete audit history or binding data.

### 7.2 Observe Mode

Validation is executed and violations are audited, but protected business requests continue.

Observe mode must:

- verify signatures;
- validate identity claims;
- compare deployment fingerprints;
- validate environment and location;
- evaluate tenant and terminal bindings;
- detect replayed action tokens;
- produce the same reason codes intended for restricted/enforce mode;
- generate alerts for high-severity violations;
- never accept cryptographically invalid vendor actions;
- never allow replay merely because enforcement is observational.

Observe mode must not block normal POS intake because of license-binding violations.

### 7.3 Restricted Mode

Restricted mode blocks security-sensitive or protected operations when license, deployment, environment, location, tenant, or terminal scope is invalid.

Restricted mode must keep the following available:

- license status;
- health checks;
- safe diagnostics;
- audit-log creation;
- recovery-request generation;
- vendor-signed recovery action processing;
- approved read-only reports, subject to business approval;
- valid existing POS intake, subject to the restricted-mode operations matrix.

Restricted mode must block or require vendor-signed authorization for:

- new terminal enrollment;
- terminal-token generation or rotation;
- license replacement;
- deployment rebinding;
- location rebinding;
- fingerprint override;
- deployment identity rotation;
- privileged licensing configuration changes.

### 7.4 Enforce Mode

Enforce mode applies full blocking for invalid:

- license;
- client identity;
- deployment identity;
- environment;
- location;
- deployment fingerprint;
- tenant binding;
- terminal binding;
- protected transaction scope.

Enforce mode may be enabled only after all production authorization gates are approved.

## 8. License Artifact Requirements

A signed license artifact must include at least:

```text
schema_version
license_id
client_id
deployment_id
environment
location_code
issued_at
not_before
expires_at
fingerprint_policy
fingerprint_hash or approved fingerprint claims
license_status
issuer
key_id
signature
```

Optional fields may include:

```text
client_label
deployment_label
license_version
previous_license_id
supersedes_license_id
support_reference
approved_dr_deployment
grace_policy
```

### 8.1 Signature Requirements

Recommended algorithm:

```text
Ed25519 / EdDSA
```

Permitted alternative:

```text
ECDSA P-256 / ES256
```

Shared HMAC secrets must not be used for production vendor signing because possession of the verification secret would also grant signing capability.

### 8.2 Validation Requirements

The verifier must:

1. Parse only the supported artifact format.
2. Validate the declared schema version.
3. Resolve the trusted `kid`.
4. Enforce an algorithm allowlist.
5. Verify the signature.
6. Validate issue, not-before, and expiration timestamps.
7. Validate exact environment.
8. Validate client and deployment identity.
9. Validate licensed location.
10. Validate fingerprint rules.
11. Check superseded or revoked license state.
12. Return a safe reason code.
13. Avoid exposing raw license secrets or internal exception details.

## 9. Deployment Fingerprint

The fingerprint must represent the approved deployment and must not rely on one unstable machine attribute.

The fingerprint policy should use a normalized combination of approved attributes, such as:

- application installation identifier;
- operating-system or machine identifier;
- database installation identifier;
- approved server identity;
- environment identifier;
- persistent deployment secret reference;
- infrastructure-specific attributes approved by the architecture owner.

The raw fingerprint components must not be returned by public APIs or written to ordinary logs.

Audit records should use:

```text
fingerprint_hash
fingerprint_version
comparison_result
drift_reason_code
```

### 9.1 Fingerprint Versioning

The fingerprint format must be versioned:

```text
fingerprint_version = 1
```

Changes to fingerprint composition require:

- a new fingerprint version;
- compatibility handling;
- migration testing;
- approved rebinding or replacement license;
- audit trace.

### 9.2 Fingerprint Drift Classification

Fingerprint changes must be classified as:

```text
expected
non_material
material
critical
not_evaluated
```

Examples:

| Change | Suggested Classification |
|---|---|
| Application restart | Expected |
| Application deployment with same infrastructure | Expected or non-material |
| Minor OS patch | Non-material |
| Hostname-only change | Policy-dependent |
| Database restored to another server | Material or critical |
| Deployment secret replaced | Critical |
| Production database copied to staging | Critical |
| VM clone running concurrently | Critical |

## 10. Vendor-Signed Action Authorization

All license-changing actions require a signed vendor action token.

### 10.1 Protected Header

```json
{
  "alg": "EdDSA",
  "kid": "tsms-prod-2026-01",
  "typ": "tsms-vendor-action+jwt"
}
```

### 10.2 Required Claims

```text
iss
aud
jti
request_id
action
client_id
deployment_id
license_id
environment
location_code
current_fingerprint
target_fingerprint, when applicable
reason_code
approved_by
iat
nbf
exp
nonce
schema_version
```

### 10.3 Permitted Action Values

```text
approve_activation
approve_redeployment
approve_recovery
replace_license
clear_binding_quarantine
rotate_deployment_identity
emergency_unlock
```

Generic action values such as `admin_action`, `override`, or arbitrary commands must not be supported.

### 10.4 Maximum Token Lifetimes

| Action | Maximum Validity |
|---|---:|
| Activation | 15 minutes |
| Redeployment | 15 minutes |
| Recovery | 15 minutes |
| Replacement | 15 minutes |
| Emergency unlock | 5 minutes |
| Exceptional offline recovery | Up to 4 hours with elevated approval |

All action tokens are single-use regardless of remaining validity.

### 10.5 Action-Token Validation Flow

1. Validate token structure and `typ`.
2. Resolve the trusted signing key.
3. Enforce the algorithm allowlist.
4. Verify signature.
5. Validate issuer and exact audience.
6. Validate `iat`, `nbf`, and `exp`.
7. Validate environment.
8. Validate client, deployment, and license bindings.
9. Validate current fingerprint.
10. Validate target fingerprint where applicable.
11. Validate permitted action.
12. Atomically consume `jti`, `request_id`, and nonce.
13. Execute only the authorized state transition.
14. Write a complete audit event.
15. Store only a cryptographic token hash, not the reusable token.

## 11. Replay Protection

Replay protection must use a database-backed authoritative consumption ledger.

Recommended table:

```text
license_action_consumptions
```

Required fields:

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

Raw `jti`, request ID, and nonce values should be stored as SHA-256 hashes.

### 11.1 Atomic Consumption

Verification, consumption, and state transition must be executed atomically.

The implementation must not:

```text
check cache
perform action
write consumption record afterward
```

### 11.2 Cache Usage

Redis or application cache may be used only as an optimization.

The database remains the authoritative replay ledger.

### 11.3 Retention

| Record | Minimum Retention |
|---|---:|
| Active replay protection | Token expiration plus 24 hours |
| Action-consumption ledger | 1 year |
| Security audit event | Follow approved audit policy; recommended 5 years |
| Redis replay entry | Token expiration plus 1 hour |

### 11.4 Mode Behavior

| Mode | Replayed Token Behavior |
|---|---|
| Observe | Reject action, log replay, do not change binding |
| Restricted | Reject action and raise high-severity alert |
| Enforce | Reject action, audit event, optionally quarantine the request source |

Observe mode does not permit cryptographically invalid or replayed vendor actions.

## 12. Vendor Key Management Requirements

The vendor must establish a documented key-management process before production restricted mode.

The process must define:

- license request authority;
- technical validator;
- commercial or contractual approver;
- license release authority;
- private-key custodian;
- security auditor;
- key-generation process;
- key-storage process;
- access controls;
- public-key distribution;
- key rotation;
- key suspension;
- emergency revocation;
- key retirement;
- key destruction;
- lifecycle audit records;
- incident communication.

### 12.1 Private-Key Rule

The TSMS application, production server, source repository, deployment package, shared drive, and CI logs must never contain the vendor private signing key.

### 12.2 Preferred Custody

Production signing should use:

- managed KMS;
- hardware security module;
- or another non-exportable asymmetric signing service.

Where unavailable, an approved offline signing process may be used temporarily under dual control.

### 12.3 Rotation

Recommended scheduled key rotation:

```text
Every 12 months
```

Recommended verification overlap:

```text
90 days
```

The verifier must support:

- current active key;
- previous verification-only key;
- emergency replacement key after distribution.

### 12.4 Emergency Revocation

A suspected signing-key compromise must trigger:

1. Immediate signing freeze.
2. Suspension or revocation of the affected `kid`.
3. Security incident creation.
4. Client IT notification.
5. Distribution of replacement trust material.
6. Reissuance of affected licenses.
7. Rejection of new action tokens signed by the revoked key.
8. Audit and post-incident review.

## 13. Tenant Binding

Every active tenant must have an approved association with:

- `client_id`;
- `deployment_id`;
- `license_id` or active license lineage;
- `location_code`;
- binding status;
- binding effective date;
- binding audit history.

Recommended tenant fields:

```text
deployment_id
location_code
license_id
license_binding_status
license_bound_at
license_bound_by
```

Recommended binding statuses:

```text
unbound
observed
valid
mismatched
quarantined
legacy_unbound
```

Observe mode may classify and log incomplete tenant bindings.

Restricted/enforce mode must not be enabled while active tenants have unresolved critical bindings.

## 14. Terminal Binding

Every active terminal must be associated with:

- tenant;
- client;
- deployment;
- licensed location;
- terminal identity;
- terminal-binding epoch, where supported;
- API token or credential;
- binding status;
- audit history.

Recommended terminal fields:

```text
deployment_id
location_code
license_id
terminal_binding_epoch
license_binding_status
license_bound_at
license_bound_by
```

A terminal copied from one deployment to another must not automatically inherit authorization.

Terminal authentication must remain locally verifiable and must not depend on live vendor availability for every transaction.

## 15. Transaction Deployment Attribution

New transaction records must receive the active deployment identity.

Recommended field:

```text
transactions.deployment_id
```

### 15.1 New Writes

Before restricted mode:

```text
100% of new production transactions must receive the expected deployment identity.
```

### 15.2 Historical Records

Historical records must not be blindly assigned to the current deployment when provenance is uncertain.

Permitted approaches:

1. Assign a confirmed historical deployment ID.
2. Assign a designated legacy deployment ID.
3. Preserve null temporarily and classify the record as `legacy_unbound`.
4. Apply another architecture-approved historical attribution model.

### 15.3 Migration Approach

The required sequence is:

```text
profile
expand
dual-write
backfill
validate
index
constrain
```

The migration must:

- add the column as nullable initially;
- deploy dual-write logic;
- backfill in controlled primary-key ranges;
- monitor database load and replication lag;
- validate row counts;
- create only justified indexes;
- rehearse rollback;
- apply `NOT NULL` only after historical handling is approved.

## 16. POS Intake Enforcement

All first-wave POS mutation routes must use:

```text
license.valid
```

The middleware must evaluate:

- active license validity;
- deployment identity;
- environment;
- location;
- tenant binding;
- terminal binding;
- terminal credential validity;
- request deployment scope.

### 16.1 Observe Mode

POS intake continues while violations are:

- assigned safe reason codes;
- audited;
- counted;
- classified;
- alerted where severity is high.

### 16.2 Restricted Mode

The restricted-mode operations matrix determines whether existing valid terminals continue intake during a broader deployment mismatch.

Default recommendation:

- valid terminal credential plus valid local license: continue intake;
- invalid or revoked terminal credential: reject terminal;
- missing vendor heartbeat only: do not reject;
- material deployment mismatch: block protected mutation according to approved restricted policy;
- diagnostics and recovery remain available.

### 16.3 Enforce Mode

Reject any POS mutation outside the valid licensed deployment, location, tenant, and terminal scope.

## 17. Offline and Heartbeat Behavior

Vendor heartbeat is optional telemetry in Release 1 unless explicitly approved through a scope change.

The system must distinguish:

1. local signed-license validity;
2. terminal authentication;
3. vendor connectivity.

Recommended behavior:

| Condition | Behavior |
|---|---|
| Valid local license and fingerprint; vendor unreachable | Continue normal operations |
| Missed heartbeat under 72 hours | Warning only |
| Missed heartbeat from 72 hours to 14 days | Continue; elevated alert and admin banner |
| Missed heartbeat beyond 14 days | Enter approved restricted administrative state |
| Invalid or expired local license | Apply configured enforcement behavior |
| Material fingerprint mismatch | Apply observe, restricted, or enforce behavior |
| Invalid terminal credential | Reject that terminal immediately |
| Vendor outage | Do not revoke valid terminals solely due to outage |

A mandatory real-time online heartbeat would conflict with the current Release 1 exclusion of an online license server.

## 18. API Endpoints

Exact route names must be confirmed through the Laravel route inventory.

Required endpoint categories include:

| Endpoint Category | Purpose | Minimum Control |
|---|---|---|
| License status | Return safe license state | Authenticated and redacted |
| License diagnostics | Support investigation | Vendor/operator permission |
| License installation | Install valid signed artifact | Vendor action token, RBAC, audit |
| License replacement | Replace or supersede license | Vendor action token, replay protection |
| Recovery request | Generate safe recovery package | Authorized operator |
| Recovery execution | Apply approved recovery | Vendor action token |
| Deployment rebind | Update deployment binding | Vendor action token and dual approval |
| Fingerprint validation | Compare current deployment | Internal or strongly authenticated |
| Audit export | Review licensing events | Auditor/admin permission |

### 18.1 Prohibited Production Routes

The following must not remain publicly available:

- force-license-valid routes;
- bypass-license routes;
- fake vendor login routes;
- raw fingerprint-component routes;
- public token-generation routes;
- web-accessible artisan commands;
- unauthenticated migration triggers;
- mock POS routes;
- test transaction routes;
- unrestricted impersonation routes;
- configuration-dump routes;
- routes exposing environment secrets;
- debug stack-trace endpoints.

## 19. Authorization and RBAC

Local RBAC remains necessary but is not sufficient for vendor-controlled operations.

Recommended permissions:

```text
license.status.view
license.diagnostics.view
license.audit.view
license.recovery.request
license.install
license.replace
license.rebind
license.emergency_unlock
```

The following require both local permission and vendor-signed action authorization:

```text
license.install
license.replace
license.rebind
license.emergency_unlock
```

A locally created user assigned a vendor-like role must still fail if no valid vendor-signed authorization is present.

## 20. Audit Logging

The licensing audit log must capture:

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
tenant_id, when applicable
terminal_id, when applicable
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
user_agent or client identifier
correlation_id
```

Audit logs must not contain:

- private keys;
- plaintext signing secrets;
- full reusable action tokens;
- raw deployment secrets;
- raw nonces;
- raw request IDs where hashed values are sufficient;
- complete raw fingerprint components;
- database passwords;
- API tokens;
- environment-secret values.

## 21. Safe Reason Codes

The service must return stable and non-sensitive reason codes.

Recommended minimum set:

```text
LICENSE_VALID
LICENSE_MISSING
LICENSE_MALFORMED
LICENSE_SIGNATURE_INVALID
LICENSE_EXPIRED
LICENSE_NOT_YET_VALID
LICENSE_REVOKED
LICENSE_SUPERSEDED
CLIENT_MISMATCH
DEPLOYMENT_MISMATCH
ENVIRONMENT_MISMATCH
LOCATION_MISMATCH
FINGERPRINT_MISMATCH
TENANT_UNBOUND
TENANT_BINDING_MISMATCH
TERMINAL_UNBOUND
TERMINAL_BINDING_MISMATCH
TERMINAL_CREDENTIAL_INVALID
ACTION_TOKEN_MISSING
ACTION_TOKEN_INVALID
ACTION_TOKEN_EXPIRED
ACTION_TOKEN_AUDIENCE_INVALID
ACTION_TOKEN_REPLAYED
ACTION_NOT_PERMITTED
KEY_ID_UNKNOWN
KEY_REVOKED
RECOVERY_REQUIRED
LICENSE_SERVICE_ERROR
```

API responses must not reveal cryptographic implementation details or raw exception messages.

## 22. Primary Use Cases

### Use Case 1 - Initial Production Activation

1. Vendor approves production deployment.
2. Production deployment identity is generated.
3. Production fingerprint is captured.
4. Vendor issues a production license.
5. Authorized operator installs the license.
6. TSMS validates signature, environment, deployment, location, and fingerprint.
7. The system starts in observe mode.
8. Activation is written to the audit log.

### Use Case 2 - Observe-Mode Violation

1. A tenant, terminal, or request does not match the current license scope.
2. `LicenseService` produces a safe reason code.
3. Middleware records the violation.
4. Request continues.
5. Violation is classified and assigned for review.
6. Binding is corrected before restricted mode.

### Use Case 3 - Unauthorized Copy

1. TSMS database and application are copied to another environment.
2. The copied environment computes a different fingerprint or environment.
3. License verification detects a mismatch.
4. Observe mode logs the event.
5. Restricted/enforce mode blocks protected operations.
6. Diagnostics and recovery remain available to authorized operators.

### Use Case 4 - Approved Server Replacement

1. Operator generates a recovery or redeployment request.
2. Request contains safe deployment and fingerprint hashes.
3. Vendor validates contractual and technical approval.
4. Vendor issues a short-lived signed action token.
5. TSMS verifies the token.
6. Replay identifiers are atomically consumed.
7. Deployment or fingerprint binding is updated.
8. A replacement license is installed where required.
9. Old license or deployment identity is superseded.
10. Complete audit history is preserved.

### Use Case 5 - Replayed Vendor Action

1. A previously consumed vendor action token is submitted again.
2. Signature remains valid, but replay identifiers already exist.
3. The action is rejected.
4. No binding state changes.
5. A high-severity replay event is logged.

### Use Case 6 - Vendor Connectivity Failure

1. TSMS cannot reach optional vendor telemetry.
2. Local license remains cryptographically valid.
3. Existing terminal credentials remain valid.
4. Transaction operations continue under approved offline policy.
5. Alerts are raised according to the heartbeat window.
6. No automatic revocation occurs solely because the vendor is unreachable.

### Use Case 7 - Emergency Rollback

1. Enforcement produces an operational issue.
2. Named release authority approves rollback.
3. Configuration is changed to:

```env
LICENSE_ENFORCEMENT_MODE=disabled
```

4. Application configuration cache is refreshed.
5. Blocking stops.
6. Audit logging and diagnostic evidence are preserved.
7. Incident review determines corrective action.

## 23. Acceptance Criteria

### 23.1 License Validation

- A correctly signed, active, environment-matching, deployment-matching license is accepted.
- A missing license returns `LICENSE_MISSING`.
- A tampered license returns `LICENSE_SIGNATURE_INVALID`.
- An expired license returns `LICENSE_EXPIRED`.
- A staging license used in production returns `ENVIRONMENT_MISMATCH`.
- An unknown signing key returns `KEY_ID_UNKNOWN`.
- A revoked signing key returns `KEY_REVOKED`.
- Validation responses do not expose secrets or cryptographic internals.

### 23.2 Observe Mode

- License checks execute on all protected routes.
- Violations are logged with safe reason codes.
- POS intake continues for license-binding violations.
- Cryptographically invalid vendor actions remain rejected.
- Replayed action tokens remain rejected.
- No request is silently reclassified as valid.

### 23.3 Restricted and Enforce Modes

- Invalid protected operations are blocked according to the approved mode matrix.
- Diagnostics and recovery remain accessible to authorized operators.
- Client-admin-only users cannot execute license-changing operations.
- Locally created fake vendor accounts cannot authorize license changes.
- Valid vendor-signed action authorization is required for every license-changing operation.
- Emergency rollback to disabled mode is documented and tested.

### 23.4 Tenant and Terminal Binding

- All active tenants have approved deployment, location, and license binding before restricted mode.
- All active terminals have approved deployment, location, and license binding before restricted mode.
- Binding mismatches are included in an audit report.
- Critical binding count is zero before restricted-mode promotion.

### 23.5 Vendor Action Tokens

- Tokens with an invalid signature are rejected.
- Tokens with an unexpected algorithm are rejected.
- Tokens with the wrong audience are rejected.
- Expired or not-yet-valid tokens are rejected.
- Tokens with mismatched client, deployment, license, environment, or fingerprint are rejected.
- A consumed token cannot be used again.
- Token consumption and state change are atomic.

### 23.6 Key Management

- The private signing key is not stored in TSMS.
- Key access is limited to approved custodians.
- Every signed license and action token contains `kid`.
- Current and previous verification keys are supported during controlled rotation.
- Emergency revocation is documented and tested.

### 23.7 Database Migration

- New transactions receive `deployment_id`.
- Backfill is chunked and resumable.
- Historical attribution policy is approved.
- Index creation is rehearsed using production-scale data.
- Rollback is tested.
- No `NOT NULL` constraint is added until historical data is resolved.

### 23.8 Routes and Security

- Actual Laravel routes are inventoried.
- Debug and test routes are removed or environment-gated.
- Sensitive license routes require authentication, RBAC, rate limiting, and audit logging.
- No production endpoint returns raw fingerprint components.
- `APP_DEBUG` is disabled in production.
- Unauthorized route tests pass.

## 24. Success Metrics

Before restricted mode:

```text
100% of first-wave POS mutation routes use license.valid
100% of new production transactions receive deployment_id
100% of protected routes have authorization tests
0 active tenants with unresolved critical binding
0 active terminals with unresolved critical binding
0 unreviewed critical observe-mode violations
0 staging licenses accepted in production
0 replay-protection bypasses
0 unsigned or algorithm-confused action tokens accepted
100% recovery drill success
100% key-rotation verification tests passed
```

Before full enforcement:

```text
No unresolved Severity 1 or Severity 2 licensing defect
False-positive binding rate effectively zero for 14 consecutive days
Pilot terminals complete normal operational cycles
Vendor-unavailability test succeeds
Rollback rehearsal succeeds
Migration rehearsal succeeds
Client IT approves operational behavior
Finance approves business impact
Named Release Authority approves full enforcement
```

## 25. Rollout Plan

| Stage | Minimum Duration | Behavior |
|---|---:|---|
| Development validation | Until automated tests pass | Local and test licenses only |
| Staging observe mode | 2 weeks | Detect and log; never block |
| Production observe mode | 30 calendar days | Detect, classify, alert, and measure false positives |
| Restricted pilot | 14 calendar days | Block selected admin operations or pilot scope |
| Controlled enforcement | 14-30 days | Progressive provider or tenant rollout |
| Full enforcement | After executive approval | Apply to all applicable deployments |

A production observe window shorter than 30 calendar days requires documented evidence of:

- no unexplained fingerprint changes;
- no false-positive binding violations;
- successful recovery drill;
- successful rollback rehearsal;
- successful migration rehearsal;
- no staging licenses accepted in production;
- no replayed, expired, unsigned, or algorithm-confused action tokens accepted.

## 26. Production Promotion Gates

| Gate | Evidence | Approver |
|---|---|---|
| Architecture | Final identity, fingerprint, token, replay, heartbeat, and restricted-mode specifications | Solution Architect |
| Security | Key-management plan, route cleanup, negative tests, replay tests, redaction tests | Security Owner |
| Data | Production profiling, backfill rehearsal, index plan, rollback result, historical attribution | DBA/DevOps |
| Operations | Monitoring, support contacts, recovery procedure, emergency rollback drill | IT Operations |
| Business | Finance and POS effects accepted | Finance Head and PITX IT Head |
| Contract | Vendor and client support responsibilities documented | Vendor Management and Client |
| Go/no-go | All evidence complete and release window approved | Named Release Authority |

## 27. Required Authorization Artifacts

Restricted or enforce mode remains blocked until the following are approved:

| Artifact | Purpose |
|---|---|
| `LIC-POL-001` | License Identity and Environment Policy |
| `LIC-KMP-001` | Vendor Key Management Plan |
| `LIC-TKN-001` | Vendor Action Token Profile |
| `LIC-RPL-001` | Replay Prevention and Audit Retention Policy |
| `LIC-OPS-001` | Restricted Mode and Offline Operations Matrix |
| `LIC-MIG-001` | Production Migration and Rollback Runbook |
| `LIC-RTE-001` | Production Route Disposition Register |
| `LIC-ROL-001` | Observe-to-Enforce Rollout and RACI |

## 28. Blockers and Dependencies

| Priority | Dependency or Blocker |
|---:|---|
| Critical | Canonical production identity values not formally approved |
| Critical | Vendor private-key custody process not operational |
| Critical | Vendor action-token profile not implemented |
| Critical | Replay-consumption ledger not implemented |
| Critical | Restricted-mode operations matrix not approved |
| Critical | Production database size and migration impact not profiled |
| Critical | Laravel route inventory not reviewed |
| Critical | Named release authority not assigned |
| High | Historical transaction attribution policy not approved |
| High | Staging and production trust stores not separated |
| High | Emergency key-revocation procedure not tested |
| High | Client and vendor support ownership after contract expiry not defined |
| High | Role and permission seeding not finalized |
| High | Production monitoring and alert ownership not assigned |

## 29. Conflict Flags

### 29.1 Guiding Rule vs. Observe Mode

The original guiding rule states:

```text
No tenant, terminal, transaction intake, report, export, or protected operation may run unless it belongs to the current valid license, licensed deployment, and licensed location.
```

This is too absolute for observe mode and may unintentionally authorize immediate blocking.

Revised rule:

```text
Every tenant, terminal, transaction intake, report, export, and protected operation must be evaluated against the active license, deployment, environment, location, and binding scope.

Blocking occurs only according to the approved enforcement mode and operations matrix.
```

### 29.2 Vendor Heartbeat

Making live vendor heartbeat mandatory would conflict with:

```text
Online license server - excluded
```

Release 1 may support optional telemetry, but transaction intake must not depend on continuous vendor availability unless scope is formally changed.

### 29.3 Full Transaction Backfill

Blindly assigning all historical transactions to the current deployment conflicts with accurate provenance.

Historical attribution requires explicit policy approval.

### 29.4 Vendor-Only Operations

Vendor-only cannot rely exclusively on local role names because local administrators may create or assign equivalent roles.

Vendor-controlled actions require cryptographic vendor authorization in addition to local RBAC.

### 29.5 Disabled-Mode Rollback

Changing the environment variable alone may not immediately change behavior where Laravel configuration is cached.

The rollback runbook must include configuration-cache refresh and verification steps.

## 30. Feature Authorization Result

The feature is structurally and technically suitable for Release 1 observe-mode implementation.

The following may proceed:

- license-domain models;
- canonical identity support;
- signature verification;
- fingerprint generation;
- `LicenseService`;
- observe-mode middleware;
- tenant and terminal binding;
- transaction deployment attribution;
- audit logging;
- safe diagnostics;
- recovery-request generation;
- vendor action-token verification;
- replay-prevention implementation;
- production profiling;
- route inventory;
- automated tests.

Production restricted or enforce mode is not authorized until:

- all eight authorization artifacts are approved;
- production database migration is rehearsed;
- route cleanup is complete;
- key custody is operational;
- recovery, key-rotation, vendor-outage, and rollback drills pass;
- tenant and terminal critical binding issues are zero;
- production observe-mode findings are reviewed;
- PITX IT and Finance approve operational impact;
- the Vendor License Release Authority and named Release Manager sign the promotion gate.

Final architectural position:

```text
A valid locally signed license permits continued transaction operations.

Vendor authorization is required only for security-sensitive state changes such as activation, rebinding, recovery, replacement, reinstall, and redeployment.

Every protected request is evaluated against the active license and deployment perimeter, but blocking is controlled by the approved enforcement mode.
```
