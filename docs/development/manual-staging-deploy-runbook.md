# Manual Staging Deploy Runbook

Use this runbook when deploying updates to the live staging server by pulling changes from GitHub over SSH.

## Before Deploying

- Confirm the PR is merged.
- Confirm GitHub Actions passed for the commit being deployed.
- Confirm the work item has a status of `merged`.
- Confirm any migration, config, feature flag, or license enforcement mode changes are documented in the PR.

## Deployment Record

Post this as a GitHub issue or PR comment before starting:

```text
Deployment: staging
Work IDs:
Commit to deploy:
Branch:
Deployed by:
Started at:
Migration expected: yes/no
Config changes expected: yes/no
Rollback commit:
```

## SSH Deployment Steps

Run these on the staging server from the project directory.

```bash
git fetch origin
git status --short
git checkout <branch>
git pull --ff-only origin <branch>
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

If frontend assets are built before deployment and committed or uploaded separately, skip `npm ci` and `npm run build` and note that in the deployment record.

## Smoke Checks

Run or verify:

- App loads successfully.
- Login works.
- `/up` or the configured health endpoint responds successfully.
- Core API health/status endpoint responds successfully.
- Any changed feature path works in staging.
- Queue workers are running if the change touches queued jobs.
- License status is valid or intentionally in the expected rollout mode if the change touches licensing.

## After Deploying

Update the GitHub issue or PR comment:

```text
Deployment: staging
Work IDs:
Commit deployed:
Deployed by:
Completed at:
Migration result:
Smoke checks:
Issues found:
Rollback needed: yes/no
```

Move the work item from `merged` to `deployed_staging` after smoke checks pass.

## Rollback

If rollback is needed:

```bash
git fetch origin
git checkout <previous-branch-or-commit>
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build
php artisan migrate:rollback --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Only run `php artisan migrate:rollback --force` when the migration is known to be reversible and safe. If data backfills or destructive migrations were involved, follow the rollback notes from the PR instead.

