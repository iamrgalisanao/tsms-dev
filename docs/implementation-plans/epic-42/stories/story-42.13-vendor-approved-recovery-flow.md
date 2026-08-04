# Story 42.13: Vendor-Approved Recovery Flow

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As vendor support, I need a controlled recovery flow so approved reinstall, redeploy, server replacement, and DR events can restore operations safely.

## Business Context

Recovery must not become self-reactivation. Vendor approval authorizes state changes; a replacement signed license establishes the final valid state where needed.

## Scope

Included:

- recovery request generation;
- recovery execution with vendor action token;
- replacement license requirement when binding/fingerprint changes;
- supersession audit.

Excluded:

- self-service recovery portal.

## Architecture Locks

1. Recovery execution requires vendor-signed action authorization.
2. Replacement license establishes final state when deployment identity/fingerprint changes.
3. `license_type=emergency_recovery` must not behave as both command and standing license.
4. No automatic self-reactivation.

## Dependencies

- Story 42.10 for request generation
- Story 42.11
- Story 42.14

## Data Model Changes

May update deployment metadata/license state; writes audit events.

## Service and Component Changes

- `LicenseRecoveryService`
- `LicenseReplacementService`

## API and Route Changes

Preferred:

| Method | Route | Auth | Permission | Vendor Token | License Middleware |
|---|---|---|---|---|---|
| POST | `/api/v1/license/recovery-actions` | yes | recovery/rebind permission | yes | no |

## Processing Flow

1. Operator generates safe recovery request.
2. Vendor validates request.
3. Vendor issues action token.
4. TSMS verifies and consumes token.
5. Recovery transition executes.
6. Replacement license is installed where required.
7. Audit trail records supersession.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Recovery needed | `RECOVERY_REQUIRED` | Log | Allow recovery only | Allow recovery only |
| Invalid action | `ACTION_TOKEN_INVALID` | Deny | Deny | Deny |
| Replay | `ACTION_TOKEN_REPLAYED` | Deny | Deny | Deny |

## Security Requirements

- Recovery package excludes secrets.
- Recovery action is audited with vendor approver.

## Acceptance Criteria

### AC1 - Safe Request

Given a recovery request is generated, when payload is inspected, then no secrets or raw fingerprint components are present.

### AC2 - Approved Recovery

Given valid vendor action token and replacement license where required, when recovery executes, then protected operations can resume.

## Test Requirements

Feature/integration tests for recovery request, invalid token, replayed token, replacement license validation, supersession.

## Migration and Rollback

Rollback must preserve previous license and audit trail.

## Observability

Audit recovery request, recovery execution, superseded license, and failure reason.

## Definition of Done

- [ ] Recovery flow implemented.
- [ ] Replay integrated.
- [ ] Recovery drill passes.

## Out-of-Scope Follow-Ups

- Advanced recovery UX.

