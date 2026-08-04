# Story 42.6: License Middleware and Enforcement Modes

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As TSMS, I need license middleware and enforcement modes so protected routes can be observed or blocked according to rollout policy.

## Business Context

TSMS must start safely in observe mode and avoid production disruption while still exercising enforcement logic.

## Scope

Included:

- `license.valid` middleware;
- `disabled`, `observe`, `restricted`, `enforce`;
- route-safe responses;
- mode-aware audit behavior.

Excluded:

- global middleware attachment.

## Architecture Locks

1. Do not attach `license.valid` globally.
2. Observe mode validates and audits but does not block normal POS intake for license-binding violations.
3. Cryptographically invalid vendor actions are rejected in every mode.
4. Diagnostics/recovery remain accessible according to route policy.

## Dependencies

- Story 42.4
- Story 42.5

## Data Model Changes

None.

## Service and Component Changes

- `LicenseMiddleware`
- `LicenseMode`

## API and Route Changes

Middleware alias: `license.valid`.

## Processing Flow

1. Resolve mode.
2. Validate current license.
3. In observe, audit violation and continue.
4. In restricted/enforce, deny invalid protected operation.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Invalid license | service reason | Audit/allow | Deny protected | Deny protected |
| Unknown mode | `LICENSE_SERVICE_ERROR` | Fail safe per config | Deny protected | Deny protected |

## Security Requirements

- Responses must be generic and safe.
- No license secrets in denial payloads.

## Acceptance Criteria

### AC1 - Observe Allows

Given observe mode and invalid license, when a protected POS request arrives, then the request continues and an audit event is written.

### AC2 - Enforce Blocks

Given enforce mode and invalid license, when a protected request arrives, then it is denied with a safe response.

## Test Requirements

Unit/feature tests for all four modes and diagnostic route exclusion.

## Migration and Rollback

Rollback is mode switch to `disabled` plus config-cache refresh.

## Observability

Audit counts by mode and reason.

## Definition of Done

- [ ] Middleware implemented.
- [ ] Tests pass.
- [ ] Route alias registered.

## Out-of-Scope Follow-Ups

- Frontend license banners.

