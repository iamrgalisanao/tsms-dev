# Story 42.5: License Audit Logging

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As vendor support, I need sanitized license audit logs so violations, denials, recovery requests, replacements, and vendor actions are traceable.

## Business Context

Observe mode is only useful if it creates actionable evidence without exposing secrets.

## Scope

Included:

- audit table/model;
- audit logger;
- sanitizer;
- safe context fields;
- violation and action events.

Excluded:

- polished dashboard.

## Architecture Locks

1. Audit logging must not leak secrets.
2. Every violation/denial/action attempt has a reason code.
3. Token/request/fingerprint identifiers are hashed where feasible.

## Dependencies

- Story 42.4

## Data Model Changes

- `license_audit_logs`

## Service and Component Changes

- `LicenseAuditLogger`
- redaction/sanitizer helper

## API and Route Changes

Audit view/export is later surfaced in Story 42.10.

## Processing Flow

1. Receive event type and context.
2. Sanitize sensitive values.
3. Persist audit event.
4. Return event ID where useful.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Audit write failure | `LICENSE_SERVICE_ERROR` | Log app error | Fail closed for security actions | Fail closed for security actions |

## Security Requirements

- Do not store raw action tokens, nonces, request IDs, signatures, env secrets, terminal secrets, or raw fingerprint inputs.

## Acceptance Criteria

### AC1 - Violation Audited

Given a license violation occurs, when middleware/service logs it, then the audit row includes safe reason code and context.

### AC2 - Secrets Redacted

Given sensitive input is passed to logger, when stored, then secrets are absent.

## Test Requirements

Unit tests:

- sanitizer removes sensitive keys;
- event persistence;
- token hash/request hash behavior.

## Migration and Rollback

Forward migration creates audit table. Rollback should be avoided after production events unless data export/retention decision is approved.

## Observability

Metrics:

- audit events by severity;
- observe violations by reason code.

## Definition of Done

- [ ] Migration/model/logger implemented.
- [ ] Redaction tests pass.
- [ ] Audit schema reviewed.

## Out-of-Scope Follow-Ups

- Audit analytics dashboard.

