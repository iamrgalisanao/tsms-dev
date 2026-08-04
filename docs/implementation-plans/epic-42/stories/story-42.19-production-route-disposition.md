# Story 42.19: Production Route Disposition

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As security reviewer, I need all production routes inventoried and disposed so debug, legacy, or test endpoints cannot bypass the license perimeter.

## Business Context

Route classification is not enough; unsafe production routes must be removed, gated, or explicitly protected before restricted pilot.

## Scope

Included:

- actual Laravel route inventory;
- production disposition register;
- removal/gating/protection plan;
- unauthorized route tests.

Excluded:

- broad API redesign unrelated to license security.

## Architecture Locks

1. No debug/test bypass routes remain publicly accessible.
2. No raw fingerprint, config dump, token generation, or web-artisan routes in production.
3. Retained sensitive routes require auth, permission, rate limit, and audit.

## Dependencies

- Story 42.7
- `LIC-RTE-001`

## Data Model Changes

None.

## Service and Component Changes

- Optional route validation checks.

## API and Route Changes

Each retained license route must document method, route, auth, permission, vendor token requirement, rate limit, audit event, classification, and restricted-mode availability.

## Processing Flow

1. Generate route list.
2. Compare against source files.
3. Mark keep/protect/remove/environment-gate.
4. Implement changes.
5. Test unauthorized access.

## Failure and Reason-Code Behavior

Unauthorized sensitive route access should return safe authorization failure, not license internals.

## Security Requirements

- `APP_DEBUG=false` in production.
- No stack traces or env values in production responses.

## Acceptance Criteria

### AC1 - Route Register Approved

Given route inventory is complete, when security review finishes, then every route has disposition.

### AC2 - Prohibited Routes Gone

Given production route list is checked, when prohibited categories are searched, then none are publicly accessible.

## Test Requirements

Route-list checks and unauthorized access tests.

## Migration and Rollback

Route removals may need provider compatibility rollback plan.

## Observability

Audit attempts on retained sensitive routes.

## Definition of Done

- [ ] `LIC-RTE-001` approved.
- [ ] Route changes implemented.
- [ ] Unauthorized tests pass.

## Out-of-Scope Follow-Ups

- Full public API versioning program.

