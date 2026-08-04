# Story 42.18: Restricted-Mode and Offline Operations

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As PITX IT and vendor operations, we need an approved restricted-mode and offline operations matrix so availability is preserved without weakening redeployment control.

## Business Context

Release 1 must not turn vendor connectivity into a single point of failure for POS transactions.

## Scope

Included:

- restricted operation matrix;
- heartbeat thresholds;
- vendor-outage behavior;
- report access decision;
- terminal authentication behavior;
- implementation-facing `RestrictedModePolicy`.

Excluded:

- mandatory online license server.

## Architecture Locks

1. Vendor outage does not revoke valid local license or valid terminal credentials.
2. Security-sensitive state changes require vendor action token.
3. Existing valid POS intake behavior is explicitly decided by matrix.

## Dependencies

- Story 42.6
- Story 42.12
- `LIC-OPS-001`

## Data Model Changes

Optional policy config only.

## Service and Component Changes

- `RestrictedModePolicy`
- heartbeat threshold config

## API and Route Changes

Routes classified as continue/block/token-required by matrix.

## Processing Flow

1. Classify operation.
2. Evaluate license state.
3. Evaluate vendor connectivity state.
4. Apply matrix result.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Recovery required | `RECOVERY_REQUIRED` | Log | Recovery only | Recovery only |
| Vendor offline only | none or warning | Warn | Follow matrix | Follow matrix |

## Security Requirements

- No POS transaction depends on live vendor heartbeat unless scope changes.

## Acceptance Criteria

### AC1 - Matrix Approved

Given stakeholders review restricted behavior, when approved, then operations are classified continue/block/vendor-token-required.

### AC2 - Vendor Outage Test

Given valid local license and vendor unreachable, when POS intake runs, then approved behavior is followed and no automatic revocation occurs.

## Test Requirements

Integration/UAT vendor outage, missed heartbeat windows, read-only report decision, terminal auth behavior.

## Migration and Rollback

Policy rollout can be reverted by config.

## Observability

Alerts for missed heartbeat windows and restricted-mode entry.

## Definition of Done

- [ ] `LIC-OPS-001` approved.
- [ ] `RestrictedModePolicy` defined.
- [ ] Vendor-outage tests pass.

## Out-of-Scope Follow-Ups

- Continuous remote revocation service.

