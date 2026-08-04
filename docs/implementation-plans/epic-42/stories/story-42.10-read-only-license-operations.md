# Story 42.10: Read-Only License Operations

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As a vendor/operator, I need safe license status, diagnostics, audit, and recovery-request endpoints so I can support observe/restricted deployments.

## Business Context

Diagnostics and recovery request generation must remain available even when protected business operations are restricted.

## Scope

Included:

- status;
- capabilities;
- diagnostics;
- audit view/export;
- recovery request generation.

Excluded:

- install/replacement/rebind/recovery execution, owned by Story 42.11 and 42.13.

## Architecture Locks

1. Read-only/request-generation endpoints require local permission but not vendor action token.
2. Endpoints remain outside `license.valid`.
3. Responses are redacted.

## Dependencies

- Story 42.4
- Story 42.5
- Story 42.7

## Data Model Changes

Reads audit/license data.

## Service and Component Changes

- `LicenseController`
- local operator middleware
- recovery request package service

## API and Route Changes

Preferred:

| Method | Route | Auth | Permission | Vendor Token | License Middleware |
|---|---|---|---|---|---|
| GET | `/api/v1/license/status` | yes | `license.status.view` | no | no |
| GET | `/api/v1/license/capabilities` | yes | `license.status.view` | no | no |
| GET | `/api/v1/license/diagnostics` | yes | `license.diagnostics.view` | no | no |
| POST | `/api/v1/license/recovery-requests` | yes | `license.recovery.request` | no | no |

## Processing Flow

1. Authenticate local operator.
2. Check permission.
3. Read safe license status or generate recovery request.
4. Redact sensitive fields.
5. Audit access where appropriate.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Unauthorized local operator | `ACTION_NOT_PERMITTED` | Deny | Deny | Deny |

## Security Requirements

- No raw fingerprint components.
- No full tokens or env secrets.
- Rate limit sensitive endpoints.

## Acceptance Criteria

### AC1 - Client Admin Denied

Given a client-admin-only user, when they call license diagnostics, then access is denied.

### AC2 - Authorized Operator Allowed

Given an authorized operator, when they call status, then safe status is returned.

## Test Requirements

Feature tests for auth, permission, redaction, rate limit, restricted-mode availability.

## Migration and Rollback

No migration.

## Observability

Audit diagnostic access and recovery request generation.

## Definition of Done

- [ ] Endpoints implemented.
- [ ] Permissions tested.
- [ ] Redaction verified.

## Out-of-Scope Follow-Ups

- License dashboard UI.

