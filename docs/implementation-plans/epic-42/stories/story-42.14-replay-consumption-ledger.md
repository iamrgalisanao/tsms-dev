# Story 42.14: Replay Consumption Ledger

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As TSMS, I need a replay consumption ledger so vendor action tokens are single-use and cannot be replayed.

## Business Context

Valid signatures alone do not prevent reuse. Replay protection must be atomic with the state change.

## Scope

Included:

- `license_action_consumptions`;
- hashed `jti`, request ID, nonce, token hash;
- unique constraints;
- transaction-safe repository.

Excluded:

- action-token signing.

## Architecture Locks

1. Database ledger is authoritative.
2. Cache is optimization only.
3. Consumption and state mutation are atomic.
4. Observe mode rejects replayed vendor actions.

## Dependencies

- Story 42.11

## Data Model Changes

- `license_action_consumptions`
- `UNIQUE(jti_hash)`
- `UNIQUE(request_id_hash)`
- `UNIQUE(nonce_hash)`

## Service and Component Changes

- `LicenseActionConsumptionRepository`

## API and Route Changes

Used by all state-changing license endpoints.

## Processing Flow

1. Verify token and claims.
2. Start DB transaction.
3. Insert consumption hashes.
4. Execute state change.
5. Commit.
6. Audit result.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Duplicate token | `ACTION_TOKEN_REPLAYED` | Deny action | Deny/alert | Deny/alert |

## Security Requirements

- Raw nonce/request ID/JTI not stored.
- Concurrent replay produces at most one success.

## Acceptance Criteria

### AC1 - Single Use

Given a valid action token is consumed once, when it is submitted again, then `ACTION_TOKEN_REPLAYED` is returned.

### AC2 - Atomicity

Given state mutation fails, when transaction rolls back, then consumption behavior follows approved transaction design and cannot create partial unsafe state.

## Test Requirements

Unit/integration/concurrency tests for uniqueness, transaction rollback, and replay.

## Migration and Rollback

Do not drop replay ledger in production without security approval.

## Observability

High-severity replay audit event.

## Definition of Done

- [ ] Migration implemented.
- [ ] Repository implemented.
- [ ] Concurrency tests pass.

## Out-of-Scope Follow-Ups

- Central online replay service.

