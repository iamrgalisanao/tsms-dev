# Story 42.2: Signed License Verification

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As TSMS, I need to verify signed vendor license artifacts so that only authentic licenses can establish deployment trust.

## Business Context

Unsigned or tampered license files would let copied deployments self-authorize. TSMS must verify licenses using vendor public keys only.

## Scope

Included:

- signed license DTO;
- license reader;
- schema validation;
- signature verification;
- algorithm allowlist;
- `kid` lookup through trust store contract.

Excluded:

- vendor signing service;
- online activation server.

## Architecture Locks

1. TSMS verifies with public keys only.
2. Production HMAC signing is prohibited.
3. The verifier controls accepted algorithms.
4. License schema validation happens before business trust is granted.

## Dependencies

- Story 42.1
- Story 42.16 trust-store contract may be stubbed but must not be bypassed.

## Data Model Changes

None directly.

## Service and Component Changes

- `SignedLicense`
- `SignedLicenseReader`
- `LicenseTrustStore` interface usage

## API and Route Changes

None.

## Processing Flow

1. Read license file from private path.
2. Parse supported artifact format.
3. Validate schema.
4. Resolve `kid`.
5. Verify signature.
6. Return safe read result.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Missing license | `LICENSE_MISSING` | Log | Block protected | Block protected |
| Malformed license | `LICENSE_MALFORMED` | Log | Block protected | Block protected |
| Invalid signature | `LICENSE_SIGNATURE_INVALID` | Log | Block protected | Block protected |
| Expired license | `LICENSE_EXPIRED` | Log | Block protected | Block protected |
| Not yet valid | `LICENSE_NOT_YET_VALID` | Log | Block protected | Block protected |

## Security Requirements

- Never log private keys, full signatures, raw file contents, or internal exceptions.
- Reject unknown algorithms even if cryptographic library accepts them.

## Acceptance Criteria

### AC1 - Valid License Verifies

Given a valid license artifact, when the reader validates it, then the signature verifies and a structured license object is returned.

### AC2 - Tampered License Fails

Given a modified license payload, when validation runs, then `LICENSE_SIGNATURE_INVALID` is returned.

## Test Requirements

Unit tests:

- valid signed license;
- missing license;
- malformed schema;
- tampered payload;
- wrong algorithm;
- expired and not-yet-valid dates.

## Migration and Rollback

No migration.

## Observability

Read failures are logged through Story 42.5 after audit logging exists.

## Definition of Done

- [ ] Code implemented.
- [ ] Automated tests pass.
- [ ] Private signing key absent from runtime.
- [ ] Security requirements verified.

## Out-of-Scope Follow-Ups

- Vendor license generation tooling.

