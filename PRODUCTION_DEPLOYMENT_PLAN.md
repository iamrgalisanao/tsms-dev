# Production Deployment Plan

## Purpose
This document describes the end-to-end production deployment plan for the TSMS application: objectives, prerequisites, deployment steps, verification, rollback, and post-deploy responsibilities.

## Scope

Covers API, MCP, CLI, UI services, queue workers (Horizon/Redis), database schema migrations, and monitoring/alerting configuration.

## Goals
- Reduce downtime and risk during releases.
- Ensure repeatable, auditable deployments.
- Provide clear rollback and post-deploy verification steps.

## Prerequisites (Pre-deploy)
- **Repository**: Up-to-date main branch and green CI pipeline.
- **Secrets**: Production env vars and secrets stored in a secrets manager.
- **Backups**: Recent DB backup and verification of backup integrity.
- **Migration Dry-run**: Tested locally or in staging; review migration plan.
- **Dependencies**: Composer and npm packages resolved (`composer install --no-dev --optimize-autoloader`, `npm ci --production`).
- **Workers**: Horizon/queue workers paused or configured for zero-downtime deploy.

## Environments & Releases
- **Staging**: Run a full smoke validation (feature toggles enabled as in prod).
- **Production**: Deploy from tagged release (semantic version tag) or a CI pipeline promoting a build artifact.

## CI/CD Pipeline (Recommended)
- **Build stage**: Run tests (`php artisan test`), lint, and build UI assets.
- **Package stage**: Create deployable artifact (vendor, compiled assets, env sample).
- **Deploy stage**: Run on orchestrator (Ansible/Kubernetes/Capistrano) using the artifact.
- **Promote**: Post-deploy smoke tests then promote traffic (for blue/green or canary strategies).

## Deployment Strategy
- Preferred: Blue/Green or Rolling updates with readiness probes.
- Alternative: Brief maintenance window with pre-notified stakeholders.
- Use feature flags for risky changes; disable by default until validated.

## Step-by-step Production Deploy
1. Merge release branch and create a signed tag in Git.
2. Trigger CI to build and publish artifact to a registry/storage.
3. Put a short maintenance note in status page (if applicable).
4. Pause or drain queue workers: `php artisan horizon:terminate` or `QUEUE_CONNECTION=sync` for critical periods.
5. Deploy new app code to app servers (or update containers).
6. Run database migrations with caution:
   - Run non-destructive migrations first.
   - If destructive change required, run a compatibility migration and schedule downtime.
   - Command: `php artisan migrate --force`
7. Publish new UI assets (if any) and clear caches:
   - `php artisan view:clear`
   - `php artisan route:cache`
   - `php artisan config:cache`
8. Restart PHP-FPM / app processes and start queue workers.
9. Run smoke tests and end-to-end checks against production endpoints.
10. Monitor metrics and logs for at least 30 minutes before marking complete.

## Verification & Smoke Tests
- Health checks: `GET /health` and `GET /api/v1/health` (or equivalent).
- Background job processing: Submit test job(s) and confirm completion.
- End-to-end transaction: Submit a sample POS transaction through the normal flow and confirm persistence and forwarding.
- Monitoring: Verify no error-rate spike in logs and APM.

## Rollback Plan
- If critical failures occur, rollback to the last known-good tag/artifact.
- Steps:
  - Put system into maintenance mode.
  - Re-deploy previous artifact.
  - Revert any incompatible DB changes (if reversible) or restore DB from backup.
  - Restart services and re-run verification checks.

## Backups & Data Migration
- Always take a full DB backup before migration window.
- Snapshot queues/redis persistence where possible.
- Document migration scripts and provide a revert script when possible.

## Monitoring, Alerts & Observability
- Ensure logs are centralized and searchable (ELK/Datadog/Sentry).
- Configure alerts for error rate, latency, job-failure rate, and queue backlog.
- Dashboard: deployment health, queue size, failed jobs, and key business metrics (transactions/minute).

## Security & Compliance
- Verify secrets are not stored in repo; use secrets manager.
- Confirm production-only debug/logging disabled.
- Run vulnerability scans on images/artifacts prior to promotion.

## Post-deploy Checklist
- Confirm all services are running and healthy.
- Confirm scheduled jobs and workers are processing normally.
- Remove maintenance notice and inform stakeholders.
- Create a short deployment note with tag, time, and roll-forward/rollback owner.

## Runbook Commands (examples)
```
# Build & tests (CI equivalent)
composer install --no-dev --optimize-autoloader
npm ci --production && npm run build

# Deploy: example commands (may be orchestrator-specific)
php artisan migrate --force
php artisan horizon:terminate
php artisan config:cache

# Rollback (example)
# checkout previous tag and redeploy
git checkout <previous-tag>
./scripts/deploy.sh --artifact previous
```

## Ownership & Schedule
- **Release owner**: person responsible for coordinating the window.
- **Rollback owner**: person authorized to execute rollback.
- **SRE/Oncall**: monitors alerts during and after deploy.

## Postmortem
- If issues occurred, run a blameless postmortem within 72 hours and record corrective actions.

---
Last updated: 2025-12-20
