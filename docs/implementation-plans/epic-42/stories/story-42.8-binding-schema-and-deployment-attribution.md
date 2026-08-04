# Story 42.8: Binding Schema and Deployment Attribution

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As TSMS, I need schema fields for tenants, terminals, and transactions so records can be tied to the licensed deployment.

## Business Context

Enforcement needs data. Nullable/backfillable fields allow safe rollout before blocking.

## Scope

Included:

- tenant binding fields;
- terminal binding fields;
- transaction deployment attribution;
- activation history where required.

Excluded:

- production backfill execution.

## Architecture Locks

1. New fields are nullable until validation/backfill is complete.
2. Historical attribution is not guessed.
3. Schema supports binding status and auditability.

## Dependencies

- Story 42.1

## Data Model Changes

- `tenants.deployment_id`, `location_code`, `license_id`, `license_binding_status`, `license_bound_at`, `license_bound_by`
- `pos_terminals.deployment_id`, `location_code`, `license_id`, `activation_status`, `terminal_binding_epoch`, `license_binding_status`, `license_bound_at`, `license_bound_by`, `activated_at`, `revoked_at`
- `transactions.deployment_id`
- `terminal_activations`

## Service and Component Changes

- model fillables/casts/relations.

## API and Route Changes

None directly.

## Processing Flow

1. Add nullable columns/tables.
2. Update models.
3. Verify migrations.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Tenant unbound | `TENANT_UNBOUND` | Log | Deny after gate | Deny |
| Terminal unbound | `TERMINAL_UNBOUND` | Log | Deny after gate | Deny |

## Security Requirements

- Avoid destructive migration behavior.
- Preserve current POS intake before enforcement.

## Acceptance Criteria

### AC1 - Schema Exists

Given migrations run, when schema is inspected, then binding fields exist and are nullable.

### AC2 - Models Updated

Given models are used, when binding fields are filled/cast, then values persist correctly.

## Test Requirements

Migration/model tests.

## Migration and Rollback

Forward migration adds nullable fields. Rollback must be assessed carefully after production data is populated.

## Observability

Schema readiness reported by Story 42.9.

## Definition of Done

- [ ] Migrations implemented.
- [ ] Models updated.
- [ ] Tests pass.

## Out-of-Scope Follow-Ups

- Enforcement policy.

