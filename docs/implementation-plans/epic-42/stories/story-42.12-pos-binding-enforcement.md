# Story 42.12: POS Binding Enforcement

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As TSMS, I need POS intake to evaluate tenant and terminal deployment bindings so unauthorized terminals cannot submit under copied deployments.

## Business Context

POS intake is the primary value path. Enforcement must be scoped carefully and observe-first to avoid transaction loss.

## Scope

Included:

- tenant binding check;
- terminal binding check;
- terminal credential validity;
- transaction `deployment_id` stamping;
- mode-aware behavior.

Excluded:

- mandatory vendor heartbeat per transaction.

## Architecture Locks

1. Do not heavily rewrite existing POS transaction logic.
2. Valid terminals are not rejected solely because optional vendor telemetry is unavailable.
3. Observe mode logs binding mismatches and allows normal intake.
4. Restricted/enforce behavior follows approved operations matrix.

## Dependencies

- Story 42.6
- Story 42.8
- Story 42.9
- Story 42.18 for restricted policy

## Data Model Changes

Uses tenant, terminal, and transaction binding fields.

## Service and Component Changes

- `LicenseBindingService`
- POS intake integration

## API and Route Changes

First-wave POS mutation routes use `license.valid`.

## Processing Flow

1. Resolve terminal and tenant.
2. Validate terminal credential.
3. Compare tenant/terminal deployment and location.
4. In observe, audit mismatches.
5. In restricted/enforce, deny invalid scope.
6. Stamp new transactions with deployment ID.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Tenant unbound | `TENANT_UNBOUND` | Log/allow | Matrix | Deny |
| Terminal unbound | `TERMINAL_UNBOUND` | Log/allow | Matrix | Deny |
| Terminal mismatch | `TERMINAL_BINDING_MISMATCH` | Log/allow | Deny | Deny |
| Invalid credential | `TERMINAL_CREDENTIAL_INVALID` | Deny | Deny | Deny |

## Security Requirements

- Credential invalidity is not softened by observe mode.
- Binding failures use safe messages.

## Acceptance Criteria

### AC1 - Observe Continues

Given observe mode and binding mismatch, when POS submits, then intake continues and audit event is created.

### AC2 - Enforce Denies

Given enforce mode and terminal from wrong deployment, when POS submits, then request is denied.

## Test Requirements

Feature tests for valid terminal, wrong deployment, wrong location, invalid credential, vendor outage, deployment ID stamping.

## Migration and Rollback

Rollback mode disables blocking while preserving transaction deployment IDs.

## Observability

Metrics:

- POS binding violations by reason;
- new transactions missing deployment ID.

## Definition of Done

- [ ] Binding service integrated.
- [ ] Tests pass.
- [ ] POS path minimally changed.

## Out-of-Scope Follow-Ups

- Terminal re-enrollment UX.

