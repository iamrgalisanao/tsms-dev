# Story 42.9: Binding Audit and Safe Backfill

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As a deployment engineer, I need audit and backfill commands so data can be prepared without unsafe production changes.

## Business Context

Restricted/enforce mode cannot start until active tenant and terminal bindings are clean and historical attribution is approved.

## Scope

Included:

- dry-run audit;
- dry-run/apply backfill;
- explicit scope preconditions;
- historical attribution safeguards.

Excluded:

- production execution without approval.

## Architecture Locks

1. Backfill defaults to dry-run.
2. Apply requires explicit `--apply`.
3. Historical transactions are not blindly assigned to current deployment.

## Dependencies

- Story 42.8

## Data Model Changes

Uses Story 42.8 schema.

## Service and Component Changes

- `LicenseBindingAuditCommand`
- `LicenseBindingBackfillCommand`
- `LicenseBindingService`

## API and Route Changes

None.

## Processing Flow

1. Validate expected scope config.
2. Audit missing/out-of-scope bindings.
3. Preview backfill candidates.
4. Apply only with explicit approval.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Binding mismatch | `TENANT_BINDING_MISMATCH` / `TERMINAL_BINDING_MISMATCH` | Log | Deny after gate | Deny |

## Security Requirements

- Commands must not infer production values without config.
- Output must avoid secrets.

## Acceptance Criteria

### AC1 - Dry Run

Given expected scope is configured, when dry-run runs, then candidate counts and samples are shown without writes.

### AC2 - Apply Guard

Given `--apply` is omitted, when backfill runs, then no writes occur.

## Test Requirements

Command tests for dry-run, apply, missing config, mismatch detection, historical skip behavior.

## Migration and Rollback

Backfill must be resumable; rollback notes must preserve evidence and avoid data destruction.

## Observability

Metrics:

- missing binding counts;
- out-of-scope counts;
- backfill progress.

## Definition of Done

- [ ] Commands implemented.
- [ ] Dry-run/apply tests pass.
- [ ] Historical attribution policy referenced.

## Out-of-Scope Follow-Ups

- Automated production scheduler.

