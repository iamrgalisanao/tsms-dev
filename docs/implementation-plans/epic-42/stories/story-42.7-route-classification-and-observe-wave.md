# Story 42.7: Route Classification and Observe Wave

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As a technical lead, I need routes classified so license enforcement can be attached safely and recovery paths remain available.

## Business Context

Global enforcement risks locking users out of recovery and diagnostics. Route-by-route classification protects production availability.

## Scope

Included:

- route classification;
- first observe wave;
- actual Laravel route inventory before production observe.

Excluded:

- final debug/legacy cleanup, owned by Story 42.19.

## Architecture Locks

1. No global license middleware.
2. Login, health, diagnostics, recovery request, and approved recovery execution are not license-blocked.
3. First observe wave is limited to POS mutation routes.

## Dependencies

- Story 42.6

## Data Model Changes

None.

## Service and Component Changes

- Optional `LicenseRouteClassifier`

## API and Route Changes

First wave:

| Method | Route | License Middleware |
|---|---|---|
| POST | `/api/v1/transactions/official` | `license.valid` |
| POST | `/api/v1/transactions/batch` | `license.valid` |
| POST | `/api/v1/transactions/{transaction_id}/refund` | `license.valid` |
| POST | `/api/v1/transactions/{transaction_id}/void` | `license.valid` |

## Processing Flow

1. Generate route inventory.
2. Classify routes.
3. Attach first-wave middleware.
4. Verify route list.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Protected route invalid | service reason | Audit/allow | Deny | Deny |

## Security Requirements

- Diagnostics must require authentication/permission even when outside `license.valid`.

## Acceptance Criteria

### AC1 - First Wave Attached

Given route list is generated, when POS mutation routes are inspected, then each includes `LicenseMiddleware`.

### AC2 - Diagnostics Excluded

Given license diagnostic routes are inspected, when route middleware is shown, then `license.valid` is absent.

## Test Requirements

Route-list smoke tests and unauthorized diagnostic route tests.

## Migration and Rollback

Remove middleware or set mode `disabled`.

## Observability

Route inventory artifact.

## Definition of Done

- [ ] Route classification updated.
- [ ] First wave attached.
- [ ] Route-list evidence captured.

## Out-of-Scope Follow-Ups

- Removal of all legacy/debug routes.

