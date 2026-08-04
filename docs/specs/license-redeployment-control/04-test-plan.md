# Test Plan

Document ID: `TSMS-LIC-RDC-TEST`
Status: Reviewed - aligned to feature specification
Last updated: 2026-07-20

## Required Automated Tests

Focused unit test command:

```bash
vendor/bin/phpunit \
  tests/Unit/SignedLicenseReaderTest.php \
  tests/Unit/LicenseServiceTest.php \
  tests/Unit/DeploymentFingerprintServiceTest.php \
  tests/Unit/LicenseAuditLoggerTest.php \
  tests/Unit/LicenseControllerTest.php \
  tests/Unit/LicenseRecoveryRequestServiceTest.php \
  tests/Unit/LicenseReplacementServiceTest.php \
  tests/Unit/LicenseMiddlewareTest.php \
  tests/Unit/EnsureVendorLicenseAuthorityTest.php
```

Current focused result:

```text
24 tests, 80 assertions
```

This result covers the current staging implementation only. Additional tests below are required before restricted/enforce implementation can be considered complete.

## Required Coverage

### License Validation

- Valid signed license allows protected route.
- Missing license logs only in observe mode.
- Missing license blocks in restricted/enforce mode.
- Expired license blocks protected route.
- Not-yet-valid license is rejected.
- Tampered license returns `LICENSE_SIGNATURE_INVALID`.
- Unknown `kid` returns `KEY_ID_UNKNOWN`.
- Revoked key returns `KEY_REVOKED`.
- Wrong environment returns `ENVIRONMENT_MISMATCH`.
- Staging license in production is rejected.
- Wrong deployment ID returns `DEPLOYMENT_MISMATCH`.
- Location mismatch returns `LOCATION_MISMATCH`.
- Fingerprint mismatch returns `FINGERPRINT_MISMATCH` or copy-suspected reason.

### Environment Trust Separation

- Production rejects staging license files.
- Production rejects staging action-token audience.
- Staging and production use separate trust stores or separate `kid` namespaces.
- CI/CD does not copy staging license files into production artifact paths.

### Vendor Action Tokens

- Token with invalid signature is rejected.
- Token with unexpected algorithm is rejected.
- Token with wrong `typ` is rejected.
- Token with wrong issuer or audience is rejected.
- Expired token is rejected.
- Not-yet-valid token is rejected.
- Missing required claim is rejected.
- Generic action such as `admin_action` is rejected.
- Mismatched client, deployment, license, environment, location, or fingerprint is rejected.
- Fake locally created vendor account without vendor-signed token is denied license-changing operations.
- Status/diagnostics/recovery-request endpoints do not require vendor action token.
- Install, replacement, rebind, recovery execution, and emergency unlock require vendor action token.

### Replay Protection

- Replayed vendor action token is denied.
- Concurrent replay attempts result in one atomic success at most.
- Replay identifiers are stored as hashes.
- `jti_hash`, `request_id_hash`, and `nonce_hash` uniqueness is enforced.
- Observe mode rejects replayed vendor actions.

### Tenant, Terminal, and POS Binding

- Tenant missing deployment binding is logged/blocked according to mode.
- Terminal missing deployment binding is logged/blocked according to mode.
- Terminal from unlicensed location is denied in restricted/enforce mode.
- POS intake rejects wrong-deployment terminal in restricted/enforce mode.
- POS intake rejects invalid/revoked terminal credentials in every mode where credentials are invalid.
- POS intake does not reject valid terminals solely because optional vendor heartbeat is unavailable.
- New transactions receive expected `deployment_id`.

### Migration and Historical Attribution

- Backfill dry-run reports candidate counts and sample IDs.
- Backfill apply requires explicit `--apply`.
- Backfill refuses to run without expected scope config.
- Historical records are not blindly assigned to the current deployment when provenance is unknown.
- Chunked backfill is resumable.
- Rollback rehearsal preserves populated data and disables blocking safely.

### Route and Security

- Actual Laravel route inventory is generated and tested.
- First-wave POS mutation routes include `license.valid`.
- License diagnostics remain outside `license.valid`.
- Debug/test routes are removed, disabled, or environment-gated.
- Prohibited production routes are not publicly accessible.
- Sensitive routes require authentication, permission, rate limit, and audit.
- No production endpoint returns raw fingerprint components.
- `APP_DEBUG=false` in production.

### Audit and Redaction

- Recovery request excludes secrets.
- Audit logs do not contain raw tokens, signatures, env values, raw request IDs, nonces, or fingerprint inputs.
- Vendor action attempts log token hash, request ID hash, reason code, actor, and result.

### Restricted Mode, Offline Behavior, and Rollback

- Restricted-mode operations matrix is covered by integration tests.
- Vendor outage test succeeds without revoking valid local license or terminal credentials.
- Missed heartbeat behavior follows approved windows.
- Emergency rollback to `LICENSE_ENFORCEMENT_MODE=disabled` includes Laravel configuration-cache refresh and verification.
- Recovery drill succeeds.
- Key-rotation and key-revocation drills pass.

## Manual/UAT Scenarios

1. Observe-mode POS intake with missing license:
   - Request succeeds.
   - Audit log records violation.

2. Valid license installed:
   - Status endpoint reports valid status without exposing secrets.
   - POS intake succeeds.

3. Tampered license installed:
   - Status endpoint returns safe invalid reason.
   - Observe mode allows protected route and logs.
   - Restricted/enforce blocks protected route.

4. Client admin attempts state-changing license operation:
   - Request is denied without vendor action token.

5. Vendor operator installs replacement license:
   - Requires local permission.
   - Requires vendor-signed action authorization.
   - Token is consumed once.
   - Replacement validates before activation.
   - Audit log records replacement.

6. Vendor connectivity failure:
   - Valid local license and valid terminal credentials continue according to approved offline policy.
   - Alerts are raised according to heartbeat window.

7. Emergency rollback:
   - Enforcement mode is changed to `disabled`.
   - Laravel config cache is refreshed.
   - Blocking stops.
   - Audit evidence remains intact.

