# Story 42.11: Vendor-Signed Action Authorization

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As the vendor, I need license-changing actions to require signed vendor authorization so locally created roles cannot approve redeployment or recovery.

## Business Context

Client admins may have full local system access. Local RBAC identifies an operator but cannot prove vendor authority.

## Scope

Included:

- vendor action token profile;
- signature verification;
- action-specific claim validation;
- local permission plus vendor token requirement.

Excluded:

- replay ledger implementation, owned by Story 42.14.

## Architecture Locks

1. Local RBAC alone never establishes vendor authority.
2. Vendor actions use asymmetric signature verification.
3. Algorithm selection is controlled by the verifier.
4. Tokens are action-specific and environment-specific.
5. Verification completes before any state mutation.
6. Production enablement requires replay ledger integration.

## Dependencies

- Story 42.15
- Story 42.16
- Story 42.14 before production state-changing use

## Data Model Changes

Reads trust-store/key state. Writes action audit events.

## Service and Component Changes

- `VendorActionTokenReader`
- `VendorActionAuthorizationService`
- `VendorAction` enum

## API and Route Changes

State-changing endpoints require local permission plus vendor token:

| Operation | Local Permission | Vendor Token |
|---|---|---|
| install | `license.install` | yes |
| replace | `license.replace` | yes |
| rebind | `license.rebind` | yes |
| recovery execution | `license.rebind` or recovery permission | yes |
| emergency unlock | `license.emergency_unlock` | yes |

## Processing Flow

1. Authenticate local operator.
2. Check local permission.
3. Parse token.
4. Resolve `kid`.
5. Verify signature and algorithm.
6. Validate claims and action.
7. Pass to replay consumption before mutation.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Missing token | `ACTION_TOKEN_MISSING` | Deny action | Deny | Deny |
| Invalid token | `ACTION_TOKEN_INVALID` | Deny action | Deny | Deny |
| Expired token | `ACTION_TOKEN_EXPIRED` | Deny action | Deny | Deny |
| Wrong audience | `ACTION_TOKEN_AUDIENCE_INVALID` | Deny action | Deny | Deny |
| Not permitted | `ACTION_NOT_PERMITTED` | Deny action | Deny | Deny |

## Security Requirements

- Never log full reusable token.
- Store token hash only.
- Reject generic action values.

## Acceptance Criteria

### AC1 - Fake Vendor Denied

Given a locally created vendor-like account, when it attempts a license-changing action without a valid vendor token, then the action is denied.

### AC2 - Valid Action Authorized

Given local permission and a valid vendor token for one action, when the endpoint is called, then only that action is authorized.

## Test Requirements

Unit and feature tests for invalid signature, wrong alg, wrong audience, expired, missing claim, mismatched fingerprint, not permitted action.

## Migration and Rollback

No mutation may be enabled until Story 42.14 is integrated.

## Observability

Audit action attempts with actor, vendor approver, request hash, token hash, and result.

## Definition of Done

- [ ] Token reader implemented.
- [ ] Authorization service implemented.
- [ ] Negative tests pass.
- [ ] Security review completed.

## Out-of-Scope Follow-Ups

- Public online action-token issuance service.

