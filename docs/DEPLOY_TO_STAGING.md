# Deploy to Staging — Webapp API rollout checklist

This document describes the minimal safe steps to roll the Webapp read-only API (feature/webapp-api-mvp) into the staging environment and retire the previous forwarding integration. Follow these steps in order. Keep an on-call engineer available during the rollout.

## Preconditions

- You have a CI pipeline that runs `composer install` and `php artisan test` on the branch.
- You have admin access to the staging host or CI environment where secrets can be configured (do not commit secrets to git).
- Redis is available for cache and rate limiting in staging (recommended). If not, be aware caching/rate-limiter fallbacks may use file DB.
- Ensure the `feature/webapp-api-mvp` branch is merged to the staging branch (or deployed directly from the branch after code review).

## 1) Code / CI checks (pre-deploy)

1. Open the PR for `feature/webapp-api-mvp` and run the project's CI.
2. Confirm all tests pass, especially `tests/Feature/WebappApi/*` and any regression tests.
3. Confirm database migrations included in the change are reviewed (summary reporting tables). Decide whether to run them now or schedule a maintenance window.

Commands (local / CI):

```bash
# run unit + feature tests relevant to Webapp API
php artisan test --filter WebappApi

# run the full test suite in CI (if desired)
php artisan test
``` 

## 2) Staging environment configuration (secrets & env)

Important: never store plain tokens in the repository. Use the hosting provider's secrets manager.

Required environment variables (staging) — set in secrets manager / CI variables:

- WEBAPP_API_ENABLED=true
- WEBAPP_API_USE_STATIC_TOKENS=false    # prefer false in staging once using Sanctum tokens
- WEBAPP_API_ALLOWED_IPS=<webapp-client-ip-or-range>
- WEBAPP_API_CACHE_TTL=10
- DB_REPORTING_CONNECTION and reporting DB credentials (if you use a read-only reporting DB)

If you must use a static token for quick testing, set it temporarily in secrets and then turn `WEBAPP_API_USE_STATIC_TOKENS=false` before production.

Create a Sanctum machine token for the Webapp service account and store the plain token in the staging secrets store (do not commit):

```bash
# locally (example) - create token and copy output to secrets manager
php artisan webapp:token create --email=service-webapp@tsms.local --name=webapp-machine-token --abilities=webapp:read
``` 

Record the token securely and add it to the staging host's secrets as `WEBAPP_API_STATIC_TOKENS` only if you cannot use a secrets provider that injects the token as a runtime secret.

## 3) Deploy code to staging

1. Deploy the code (merge PR or deploy branch). Ensure the `config:cache` and `route:cache` steps are run in the deployment script if you use them.
2. Run database migrations if the new summary tables are needed for reporting features:

```bash
php artisan migrate --force
``` 

3. Clear and warm caches (recommended):

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:cache
php artisan view:clear
``` 

4. Restart queue workers and Horizon to pick up new code/config:

```bash
php artisan horizon:terminate
# Your process manager (supervisor/systemd) should restart workers, or you may restart services manually.
``` 

## 4) Sanity checks on staging (smoke tests)

1. Verify route registration and middleware:

```bash
php artisan route:list --path=api/v1/webapp
``` 

2. Perform an authenticated request using the machine token:

```bash
curl -H "Authorization: Bearer <plain-token>" "https://stagingtsms.pitx.com.test/api/v1/webapp/transactions/count"
``` 

Expected: 200 with a JSON count. If 401/403, check `WEBAPP_API_ALLOWED_IPS` and verify the token has the `webapp:read` ability.

3. Confirm logs for 401/403 spikes and any errors in the app log. Monitor Horizon logs for jobs and errors.

## 5) Rolling switch: disable forwarding and cutover

Only perform this after Webapp client confirms successful integration and traffic has been tested in staging.

1. Update Webapp client to call the TSMS API endpoint (`/api/v1/webapp/transactions`) using the issued token.
2. Once Webapp is successfully using the API, disable forwarding in TSMS staging:

```bash
# on staging config / secrets
WEBAPP_FORWARDING_ENABLED=false
``` 

3. Revoke forwarding auth token (if stored in DB or secrets) to avoid accidental use.

4. Keep forwarding logs enabled for a short period (audit) but do not forward live traffic.

## 6) Post-deploy monitoring & rollback plan

Monitoring for 24–48 hours:

- Track API error rates (5xx), 401/403 spikes, and rate-limit events. 
- Track cache hit/miss ratio for count endpoint and adjust `WEBAPP_API_CACHE_TTL` if counts are stale.
- Monitor DB load on reporting tables and adjust indexes, or use a read-only reporting replica as necessary.

Rollback plan (if serious issue):

1. Re-enable forwarding by setting `WEBAPP_FORWARDING_ENABLED=true` and restore forwarding auth token from secrets (or rotate if revoked).
2. Revert to previous code (rollback deployment) and re-run migrations rollback if necessary.
3. If token-based auth misbehaves, revoke the problematic token and issue a replacement token and update Webapp client.

## 7) Operational notes & follow-ups

- Rotate service tokens regularly and consider short-lived tokens + dynamic provisioning in production.
- Add an automated smoke test in CI or a scheduled synthetic check that hits `/api/v1/webapp/transactions/count` using the staging secret.
- Add tests for IP allowlist behavior, static-token fallback, and `webapp:token` command to prevent regressions.

## Contacts

- On-call / Ops: the team Slack channel or listed admin email in `.env` (ADMIN_EMAIL)
- Security: the security owner listed in project docs

---
Small and safe is better than fast. If you want, I can also:

- Generate a ready-to-paste PR description summarizing these steps.
- Add a CI smoke-test that runs on deploy and verifies the count endpoint.

Choose one of the follow-ups and I will implement it for you.
