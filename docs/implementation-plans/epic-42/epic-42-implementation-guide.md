# Epic 42 Implementation Guide

Status: Draft
Last updated: 2026-07-20
Source spec: `docs/specs/license-redeployment-control/`

## 1. Purpose

This guide converts the TSMS License Redeployment Control specification into implementation sequencing for Epic 42.

## 2. Implementation Sequence

Phase 1 - Architecture and Trust Foundation:

- 42.1 Canonical License Identity Policy
- 42.2 Signed License Verification
- 42.3 Deployment Identity and Fingerprint
- 42.4 License Validation Service
- 42.15 Environment Trust Separation
- 42.16 Signing-Key Trust Store and Rotation

Phase 2 - Observe-Mode Perimeter:

- 42.5 License Audit Logging
- 42.6 License Middleware and Enforcement Modes
- 42.7 Route Classification and Observe Wave
- 42.10 Read-Only License Operations

Phase 3 - Deployment Binding:

- 42.8 Binding Schema and Deployment Attribution
- 42.9 Binding Audit and Safe Backfill
- 42.12 POS Binding Enforcement

Phase 4 - Protected Vendor Operations:

- 42.11 Vendor-Signed Action Authorization
- 42.14 Replay Consumption Ledger
- 42.13 Vendor-Approved Recovery Flow

Phase 5 - Production Authorization:

- 42.17 Production Migration Profiling and Rehearsal
- 42.18 Restricted-Mode and Offline Operations
- 42.19 Production Route Disposition
- 42.20 Pilot, UAT, and Production Enforcement Readiness

## 3. Story Dependency Graph

```text
42.1
 ├── 42.2
 ├── 42.3
 └── 42.15

42.2 + 42.3
 └── 42.4
      ├── 42.5
      └── 42.6
           ├── 42.7
           └── 42.12

42.8
 └── 42.9
      └── 42.12

42.15 + 42.16
 └── 42.11
      ├── 42.14
      └── 42.13

42.8 + 42.9
 └── 42.17

42.6 + 42.12
 └── 42.18

42.7
 └── 42.19

42.13 + 42.17 + 42.18 + 42.19
 └── 42.20
```

Story 42.14 may be developed in parallel with 42.11, but no production state-changing vendor action may be enabled until the replay ledger is integrated.

## 4. Recommended Branch and Commit Strategy

Use small branches by phase or story. Keep policy-only commits separate from code commits. Migrations should be committed with matching command/test updates and rollback notes.

## 5. Shared Enums and Contracts

Shared contracts must be established early:

- `LicenseEnvironment`
- `LicenseMode`
- `LicenseReasonCode`
- `LicenseIdentity`
- `VendorAction`
- `FingerprintVersion`
- `LicenseBindingStatus`

## 6. Database Migration Order

1. Add deployment metadata and license audit logs.
2. Add binding columns nullable.
3. Add transaction deployment attribution nullable.
4. Add replay ledger.
5. Add trust-store registry only if not file/config-backed.
6. Backfill in controlled chunks.
7. Add or activate indexes after profiling.
8. Add constraints only after historical attribution approval.

## 7. Route Rollout Waves

Wave 1 observe:

- `POST /api/v1/transactions/official`
- `POST /api/v1/transactions/batch`
- `POST /api/v1/transactions/{transaction_id}/refund`
- `POST /api/v1/transactions/{transaction_id}/void`

Later waves cover terminal management, tenant management, reports/exports, transaction logs, and admin settings after route disposition.

## 8. Observe-Mode Deployment Procedure

Deploy with `LICENSE_ENFORCEMENT_MODE=observe`, trust store installed, audit migrations complete, and route inventory captured. Observe mode must validate and audit but not block normal POS intake due to license-binding violations.

## 9. Test Execution Strategy

Run focused unit tests on every license PR. Add feature/integration tests for route middleware, action-token verification, replay, staging/production separation, binding, outage behavior, and rollback.

## 10. Production Profiling and Backfill

Profile production table sizes, index strategy, write volume, MySQL version, backup/restore readiness, and historical attribution before production backfill. Use dry-run first and apply only with approved scope.

## 11. Restricted Pilot Procedure

Restricted pilot starts only after production observe evidence is reviewed, critical findings are resolved, and the restricted-mode operations matrix is approved.

## 12. Rollback Procedure

Rollback uses:

```env
LICENSE_ENFORCEMENT_MODE=disabled
```

Then refresh Laravel configuration cache and verify protected route behavior and audit preservation:

```bash
php artisan config:clear
php artisan config:cache
```

## 13. Required Approval Artifacts

- `LIC-POL-001`
- `LIC-KMP-001`
- `LIC-TKN-001`
- `LIC-RPL-001`
- `LIC-OPS-001`
- `LIC-MIG-001`
- `LIC-RTE-001`
- `LIC-ROL-001`

## 14. Completion and Retrospective Criteria

Epic 42 is complete when all implementation stories are accepted, production enforcement readiness is approved, rollback/recovery drills pass, and the retrospective captures delivered scope, deferred scope, incidents, follow-ups, and Release 2 candidates.

