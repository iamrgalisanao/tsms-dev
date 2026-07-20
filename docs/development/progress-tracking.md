# Development Progress Tracking

This project uses GitHub as the development ledger. A work item starts in an issue, moves through a pull request, and is considered complete only when CI passes, review is done, and deployment status is known.

## Work IDs

Use lightweight IDs in issue titles, branch names, commits, and PRs.

- `TSMS-###` for general product and platform work.
- `LRC-##` for the current License Redeployment Control release.

Examples:

```text
TSMS-001 Add provider status filter
LRC-03 Validate deployment fingerprint
```

Branch examples:

```text
feature/LRC-03-license-service
fix/TSMS-014-terminal-heartbeat
deploy/TSMS-020-staging-release
```

Commit examples:

```text
feat(license): validate deployment fingerprint LRC-03
fix(api): return safe license status for missing file LRC-04
docs(deploy): record staging smoke checks TSMS-020
```

## Status Model

Use these statuses in GitHub Projects or labels:

- `planned`: accepted work that has not started.
- `in_progress`: implementation is underway.
- `blocked`: progress needs a decision, credential, environment, dependency, or upstream fix.
- `in_review`: a PR is open and ready for review.
- `merged`: code is merged but not confirmed deployed.
- `deployed_staging`: deployed and smoke-tested in staging.
- `deployed_production`: deployed and smoke-tested in production.
- `done`: no remaining dev action is needed.

## Definition of Done

A work item is done when:

- The issue has clear acceptance criteria.
- All commits and PRs reference the work ID.
- CI passes for backend tests and frontend build.
- Review approval is complete.
- Migrations, backfills, config changes, and rollout modes are documented.
- Deployment status is recorded, or the PR explicitly says no deployment is needed.

## Pull Requests

Every PR should include:

- Work ID and related issue.
- Summary of behavior changed.
- Test evidence.
- Migration, config, feature flag, or rollout notes.
- Manual SSH deployment notes when deployment is required.

PRs should use GitHub keywords such as `Closes TSMS-001` or `Refs LRC-03` where possible.

## CI Gates

The default CI pipeline runs:

- Composer validation.
- PHP dependency install.
- Laravel migrations against MySQL.
- PHPUnit via `php artisan test`.
- Node dependency install.
- Frontend asset build via `npm run build`.

Initial rollout note: the existing PHPUnit suite is currently treated as a visible baseline check rather than a blocking gate. Composer install, migrations, frontend build, and work-item linkage are blocking gates. Promote PHPUnit to blocking once the existing suite is green on the main development branch.

CI passing means the PR is merge-ready from the currently enforced automated checks. It does not replace human review or deployment smoke checks.

## Manual SSH Deployments

Until deployment is automated, create a deployment work item or add deployment notes to the feature PR.

Staging deployments currently happen by SSHing into the live staging server and pulling updates from GitHub. Use this runbook for a repeatable deployment record:

```text
docs/development/manual-staging-deploy-runbook.md
```

Record:

- Environment: staging or production.
- Commit SHA deployed.
- Deployer.
- Date and time in Asia/Manila.
- Commands or runbook used.
- Migration result.
- Smoke checks.
- Rollback notes.

Suggested deployment comment:

```text
Deployment: staging
Work IDs: LRC-03, LRC-04
Commit: abc1234
Deployed by:
Deployed at:
Migration result:
Smoke checks:
Rollback notes:
```

## Dev Team Dashboard

For a dev-only dashboard, use a GitHub Project with these fields:

- Status
- Work ID
- Area
- Type
- Target environment
- Blocked reason
- Linked PR

Recommended views:

- Board by Status.
- Table filtered to `blocked`.
- Table filtered to `merged` but not `deployed_staging` or `deployed_production`.
- Current release view filtered to `LRC-*` while License Redeployment Control is active.

## Current Feature Guidance

The existing license tracker remains the feature-level source of truth:

```text
docs/LICENSE_REDEPLOYMENT_CONTROL_TASK_TRACKER.md
```

Move active checklist items into GitHub issues using `LRC-##` IDs. Keep the document for policy, release boundary, and decision records; use issues and PRs for day-to-day progress.
