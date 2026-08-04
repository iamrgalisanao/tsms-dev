# Story 42.17: Production Migration Profiling and Rehearsal

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As DBA/DevOps, I need production-scale migration profiling and rehearsal so deployment attribution can be added without unacceptable downtime or data risk.

## Business Context

Adding/indexing `transactions.deployment_id` may affect a large ingestion table. Enforcement cannot proceed without migration evidence.

## Scope

Included:

- DB profiling;
- production-scale rehearsal;
- chunked backfill plan;
- index plan;
- rollback plan;
- historical attribution policy.

Excluded:

- blind production apply without approval.

## Architecture Locks

1. Use expand, dual-write, backfill, validate, index, constrain.
2. Do not add `NOT NULL` until historical handling is approved.
3. Do not run unbounded transaction-table updates.

## Dependencies

- Story 42.8
- Story 42.9
- `LIC-MIG-001`

## Data Model Changes

Uses deployment attribution schema.

## Service and Component Changes

- `DeploymentMigrationMetrics`

## API and Route Changes

None.

## Processing Flow

1. Profile production.
2. Rehearse on production-scale data.
3. Tune batch/index strategy.
4. Prove rollback.
5. Attach evidence to gate.

## Failure and Reason-Code Behavior

Not request-facing.

## Security Requirements

- Production exports for rehearsal must follow data-handling policy.

## Acceptance Criteria

### AC1 - Profiling Complete

Given production DB is assessed, when report is complete, then row count, size, version, indexes, write volume, disk, and backup/restore timing are known.

### AC2 - Rehearsal Passed

Given a production-scale dataset, when migration rehearsal runs, then backfill and rollback complete within approved thresholds.

## Test Requirements

Migration rehearsal, backfill dry-run/apply rehearsal, index plan validation.

## Migration and Rollback

Document forward and rollback steps in `LIC-MIG-001`.

## Observability

Metrics:

- historical rows remaining;
- new writes missing deployment ID;
- batch latency;
- replication lag.

## Definition of Done

- [ ] Profiling report complete.
- [ ] Rehearsal evidence attached.
- [ ] Rollback tested.

## Out-of-Scope Follow-Ups

- Online schema-change tooling procurement.

