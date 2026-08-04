# Story 42.15: Environment Trust Separation

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As a release engineer, I need staging and production trust separated so staging licenses and action tokens cannot operate production.

## Business Context

Cross-environment token confusion is a high-risk failure mode in deployment licensing.

## Scope

Included:

- exact environment match;
- separate deployment/license IDs;
- separate action-token audiences;
- separated license files/trust stores/secrets.

Excluded:

- online promotion of licenses between environments.

## Architecture Locks

1. Staging licenses are never promoted to production.
2. Production rejects staging audience.
3. Production should not trust staging signing keys unless explicitly approved.

## Dependencies

- Story 42.1

## Data Model Changes

Environment fields in license/deployment metadata.

## Service and Component Changes

- environment validator
- trust-store environment scoping

## API and Route Changes

None directly.

## Processing Flow

1. Resolve runtime expected environment.
2. Validate license environment.
3. Validate action-token audience.
4. Reject cross-environment artifacts.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| License env mismatch | `ENVIRONMENT_MISMATCH` | Log | Deny protected | Deny |
| Token audience mismatch | `ACTION_TOKEN_AUDIENCE_INVALID` | Deny action | Deny | Deny |

## Security Requirements

- CI/CD must not copy staging license/trust-store files to production.

## Acceptance Criteria

### AC1 - Production Rejects Staging License

Given production runtime, when staging license is installed, then `ENVIRONMENT_MISMATCH` is returned.

### AC2 - Production Rejects Staging Token

Given production runtime, when staging-audience action token is submitted, then it is rejected.

## Test Requirements

Cross-environment negative tests.

## Migration and Rollback

No migration.

## Observability

Audit cross-environment artifact attempts.

## Definition of Done

- [ ] Environment separation implemented.
- [ ] CI/CD guard documented.
- [ ] Tests pass.

## Out-of-Scope Follow-Ups

- Multi-environment license management UI.

