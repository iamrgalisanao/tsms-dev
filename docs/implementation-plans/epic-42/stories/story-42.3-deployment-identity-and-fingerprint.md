# Story 42.3: Deployment Identity and Fingerprint

## Status

Placeholder - pending dev-team review and story-detail workshop

> This file is intentionally a placeholder. The architecture locks and starter acceptance criteria are copied from the approved Epic 42 planning docs, but final scope, estimates, implementation notes, and owner assignments must be completed by the development team before work starts.

## Story

As the vendor, I need TSMS to identify the approved deployment so that copied databases or applications can be detected.

## Business Context

License verification alone is insufficient if the same artifact is copied. Deployment identity and fingerprinting provide copy/redeployment detection.

## Scope

Included:

- `deployment_metadata`;
- installation UUIDs;
- database instance UUID;
- fingerprint hash;
- fingerprint version;
- drift classification.

Excluded:

- hard blocking on IP, hostname, MAC address, or public IP by default.

## Architecture Locks

1. Fingerprints are versioned.
2. Raw components are not exposed.
3. Unstable signals are diagnostics unless approved by architecture.
4. Copy-suspected conditions use safe reason codes.

## Dependencies

- Story 42.1

## Data Model Changes

- `deployment_metadata` table with client, deployment, environment, location, installation UUIDs, fingerprint version/hash.

## Service and Component Changes

- `DeploymentFingerprintService`
- fingerprint assessment DTO

## API and Route Changes

None directly.

## Processing Flow

1. Load or create installation identity.
2. Load or create database identity.
3. Normalize approved fingerprint inputs.
4. Hash fingerprint.
5. Compare with license policy.
6. Classify drift.

## Failure and Reason-Code Behavior

| Condition | Reason Code | Observe | Restricted | Enforce |
|---|---|---|---|---|
| Deployment mismatch | `DEPLOYMENT_MISMATCH` | Log | Block protected | Block protected |
| Fingerprint mismatch | `FINGERPRINT_MISMATCH` | Log | Policy block | Block protected |
| Copy suspected | `RECOVERY_REQUIRED` | Log high | Restricted recovery | Block protected |

## Security Requirements

- Do not expose raw fingerprint inputs.
- Store only hashes in audit events.

## Acceptance Criteria

### AC1 - Stable Fingerprint

Given the same approved deployment identity, when fingerprinting runs repeatedly, then the same fingerprint hash is produced.

### AC2 - Copy Detection

Given the database is restored under different stable deployment identity, when fingerprinting runs, then mismatch/copy-suspected result is produced.

## Test Requirements

Unit tests:

- stable same-deployment fingerprint;
- changed database UUID;
- changed deployment ID;
- hostname-only changes do not hard-block by default.

## Migration and Rollback

Forward migration creates nullable metadata and UUIDs. Rollback must preserve evidence where possible.

## Observability

Metrics:

- fingerprint comparison result;
- drift classification count.

## Definition of Done

- [ ] Migration/model implemented.
- [ ] Fingerprint tests pass.
- [ ] Raw input redaction verified.

## Out-of-Scope Follow-Ups

- Cloud-specific hardware attestation.

