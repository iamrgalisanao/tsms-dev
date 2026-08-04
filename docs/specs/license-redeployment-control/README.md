# TSMS License Redeployment Control Specification Package

Document ID: `TSMS-LIC-RDC`
Status: Draft for implementation planning
Last updated: 2026-07-20
Owner: TSMS Engineering / Vendor Licensing

## Purpose

This folder contains the version-controlled specification package for the TSMS License Redeployment Control Epic. The feature prevents copied, restored, reinstalled, redeployed, or reused TSMS deployments from operating outside the vendor-approved client, deployment, and licensed location.

## Directory Structure

```text
docs/specs/license-redeployment-control/
├── README.md
├── 00-epic-validation.md
├── 01-feature-specification.md
├── 02-user-stories.md
├── 03-technical-design.md
├── 04-test-plan.md
├── 05-rollout-and-cicd.md
└── 06-risk-register.md
```

## Naming Convention

Use `TSMS-LIC-RDC-###` identifiers for trackable work items.

Examples:

- `TSMS-LIC-RDC-001` License policy confirmation
- `TSMS-LIC-RDC-004` LicenseService validation
- `TSMS-LIC-RDC-011` Cryptographic vendor action authorization

## Source References

- `docs/LICENSE_REDEPLOYMENT_CONTROL_TASK_TRACKER.md`
- `docs/LICENSE_ROUTE_CLASSIFICATION.md`
- `config/license.php`
- `security.env.example`
- `routes/api.php`
- `app/Services/Licensing/`
- `app/Http/Middleware/LicenseMiddleware.php`
- `app/Http/Middleware/EnsureVendorLicenseAuthority.php`

