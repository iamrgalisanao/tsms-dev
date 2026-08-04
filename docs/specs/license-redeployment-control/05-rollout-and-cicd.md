# Rollout and CI/CD Plan

Document ID: `TSMS-LIC-RDC-ROLLOUT`
Status: Reviewed - aligned to feature specification
Last updated: 2026-07-20

## Rollout Sequence

1. Approve `LIC-POL-001` canonical identity and environment policy.
2. Approve `LIC-KMP-001` key-management plan.
3. Approve `LIC-TKN-001` vendor action-token profile.
4. Approve `LIC-RPL-001` replay prevention and audit-retention policy.
5. Approve `LIC-OPS-001` restricted-mode and offline operations matrix.
6. Approve `LIC-MIG-001` production migration and rollback runbook.
7. Approve `LIC-RTE-001` production route disposition register.
8. Approve `LIC-ROL-001` observe-to-enforce rollout and RACI.
9. Deploy schema and code with `LICENSE_ENFORCEMENT_MODE=observe`.
10. Install vendor trust store and signed license file.
11. Run binding audit dry-run.
12. Run binding backfill dry-run.
13. Review candidate records and approve production backfill.
14. Apply production backfill.
15. Monitor `license_audit_logs` for production observe window.
16. Resolve and classify all critical observed violations.
17. Enable restricted mode in staging.
18. Run restricted pilot in production.
19. Run controlled production enforcement.
20. Enable full enforcement only after executive gate.

## Rollout Durations

| Stage | Minimum Duration | Behavior |
|---|---:|---|
| Development validation | Until automated tests pass | Local/test licenses only |
| Staging observe mode | 2 weeks | Detect and log; never block |
| Production observe mode | 30 calendar days | Detect, classify, alert, and measure false positives |
| Restricted pilot | 14 calendar days | Enforce selected admin operations or pilot scope |
| Controlled enforcement | 14-30 days | Progressive provider or tenant rollout |
| Full enforcement | After executive approval | Apply to all applicable deployments |

## Deployment Commands

Audit:

```bash
php artisan license:bindings:audit --json
```

Backfill dry-run:

```bash
php artisan license:bindings:backfill --json
```

Backfill apply:

```bash
php artisan license:bindings:backfill --apply --json
```

Rollback mode:

```env
LICENSE_ENFORCEMENT_MODE=disabled
```

Rollback runbook must also include:

```bash
php artisan config:clear
php artisan config:cache
```

Then verify route behavior and audit preservation.

## CI Requirements

- Run focused licensing unit tests on every PR touching:
  - `config/license.php`
  - license trust-store loading
  - `app/Services/Licensing/`
  - `app/Http/Middleware/LicenseMiddleware.php`
  - local license-operator middleware
  - `app/Http/Controllers/API/V1/LicenseController.php`
  - licensing migrations
  - license tests

- Run route-list smoke check:

```bash
php artisan route:list --path=license -v
php artisan route:list --path=v1/transactions -v
```

- Verify license diagnostic routes are outside `license.valid`.
- Verify first-wave POS mutation routes include `LicenseMiddleware`.
- Verify prohibited/debug/test routes are absent or environment-gated.
- Verify production config does not include staging license, staging trust store, or private signing keys.

## Release Gates

Restricted/enforce mode cannot be enabled unless:

- all eight authorization artifacts are approved;
- production identity values are confirmed;
- staging/production trust separation is tested;
- production table migration impact has been profiled;
- production-scale migration rehearsal has passed;
- vendor trust store and key revocation handling are operational;
- vendor-signed action authorization is implemented for license-changing operations;
- replay-consumption ledger is implemented and tested;
- binding audit returns zero critical active tenant/terminal issues;
- historical transaction attribution policy is approved;
- production observe-mode logs have been reviewed;
- debug/test/legacy route disposition is complete;
- vendor-outage test passes;
- key-rotation and key-revocation drills pass;
- recovery drill passes;
- rollback procedure, including config-cache refresh, is documented and tested.

## Accountable Promotion Owners

| Gate | Approver |
|---|---|
| Architecture | Solution Architect |
| Security | Security Owner |
| Data | DBA/DevOps |
| Operations | IT Operations |
| Business | Finance Head and PITX IT Head |
| Contract | Vendor Management and Client |
| Go/no-go | Named Release Authority |

