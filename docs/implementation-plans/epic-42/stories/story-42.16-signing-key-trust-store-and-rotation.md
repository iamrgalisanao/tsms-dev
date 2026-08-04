# Story 42.16: Signing-Key Trust Store and Rotation

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As vendor security, I need TSMS to resolve trusted public keys by `kid` and environment so signing keys can rotate and revoked keys can be rejected.

## Business Context

A single public-key path does not support production key rotation, overlap, emergency replacement, or revocation.

## Scope

Included:

- trust store;
- `kid` resolution;
- environment scoping;
- active/previous/emergency key states;
- revocation handling.

Excluded:

- vendor KMS implementation.

## Architecture Locks

1. TSMS never stores private signing keys.
2. Verification keys are resolved by `kid`.
3. Revoked keys are rejected.
4. Production rotation has overlap and test evidence.

## Dependencies

- Story 42.1
- `LIC-KMP-001`

## Data Model Changes

Trust store may be file/config-backed or table-backed with `kid`, environment, algorithm, public key, status, validity dates, revoked timestamp.

## Service and Component Changes

- `LicenseTrustStore`
- `LicenseKeyRevocationRegistry`

## API and Route Changes

None directly.

## Processing Flow

1. Read `kid` from license/action token.
2. Resolve key for current environment.
3. Confirm algorithm and key status.
4. Reject unknown/revoked keys.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Unknown key | `KEY_ID_UNKNOWN` | Log/action deny | Deny | Deny |
| Revoked key | `KEY_REVOKED` | Log/action deny | Deny | Deny |

## Security Requirements

- Private signing key absent from source, server, CI logs, and deploy package.
- Emergency revocation procedure documented.

## Acceptance Criteria

### AC1 - Key Resolution

Given a license with known `kid`, when verification runs, then the correct environment-scoped public key is used.

### AC2 - Revoked Key Rejected

Given a license signed by revoked `kid`, when verification runs, then `KEY_REVOKED` is returned.

## Test Requirements

Unit tests for active, previous, unknown, revoked, wrong-environment key, and rotation overlap.

## Migration and Rollback

If table-backed, trust-store changes require emergency rollback procedure and audit.

## Observability

Audit unknown/revoked key attempts.

## Definition of Done

- [ ] Trust store implemented.
- [ ] Rotation tests pass.
- [ ] `LIC-KMP-001` approved.

## Out-of-Scope Follow-Ups

- Self-service key management portal.

