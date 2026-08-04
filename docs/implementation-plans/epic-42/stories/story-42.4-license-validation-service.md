# Story 42.4: License Validation Service

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As TSMS, I need a central `LicenseService` so all controllers and middleware rely on one validation contract.

## Business Context

License policy in controllers creates drift and missed checks. A service provides consistent validation and safe status reporting.

## Scope

Included:

- license file/read result validation;
- schema/signature/date/environment/deployment/location/fingerprint checks;
- key revoked/superseded state checks;
- safe status response;
- canonical reason codes.

Excluded:

- route enforcement behavior, owned by Story 42.6.

## Architecture Locks

1. Controllers never read license files directly.
2. Service returns safe reason codes, not raw exceptions.
3. License status responses never expose secrets.

## Dependencies

- Story 42.2
- Story 42.3
- Story 42.16 for complete key status

## Data Model Changes

May read `deployment_metadata`.

## Service and Component Changes

- `LicenseService`
- `LicenseValidationResult`
- `LicenseReasonCode`

## API and Route Changes

Consumed by status endpoint in Story 42.10.

## Processing Flow

1. Read signed license.
2. Validate trust and claims.
3. Compare expected runtime constraints.
4. Compare deployment fingerprint.
5. Return structured result.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Valid license | `LICENSE_VALID` | Allow | Allow | Allow |
| Client mismatch | `CLIENT_MISMATCH` | Log | Block protected | Block protected |
| Location mismatch | `LOCATION_MISMATCH` | Log | Block protected | Block protected |
| Service exception | `LICENSE_SERVICE_ERROR` | Log | Block protected | Block protected |

## Security Requirements

- Safe diagnostics only.
- Exception messages not returned to clients.

## Acceptance Criteria

### AC1 - Central Validation

Given a protected route needs license status, when it calls the service, then no controller reads the license file directly.

### AC2 - Safe Diagnostics

Given license validation fails, when status is returned, then response contains safe status and reason code only.

## Test Requirements

Unit tests:

- valid status;
- environment mismatch;
- deployment mismatch;
- fingerprint mismatch;
- service exception handling.

## Migration and Rollback

No migration.

## Observability

Validation failures are audited after Story 42.5.

## Definition of Done

- [ ] Service implemented.
- [ ] Reason codes stable.
- [ ] Unit tests pass.

## Out-of-Scope Follow-Ups

- Long-lived online license checks.

