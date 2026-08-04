# TSMS License Route Classification

Last updated: 2026-06-29

## Purpose

Classify TSMS routes before attaching `license.valid` middleware.

This protects current TSMS behavior by keeping authentication, health, diagnostics, recovery, and provider support routes available while applying observe-mode checks first to business-critical protected operations.

Client company admins are not license authorities. Reinstall, redeploy, reuse, recovery, and replacement license operations require vendor license authority.

## Classification Types

```text
public
    May remain accessible without authentication and without license validation.

auth-only
    Requires authentication or token validation, but should not be license-blocked.

license-diagnostic
    Must remain available to authorized admins during restricted/enforce mode.

license-protected
    Should receive `license.valid`, starting in observe mode only.

deprecated-or-remove
    Should be disabled, removed, or explicitly protected before restricted/enforce mode.

needs-decision
    Requires product/security decision before middleware is attached.
```

## Non-Negotiable Rule

Do not attach `license.valid` globally.

Attach it only to classified protected route groups. License diagnostics must remain outside enforcement so admins can recover invalid or mismatched deployments.

## API Routes

### Public / Diagnostic Safe

| Route Surface | Classification | Decision |
|---|---|---|
| `POST /api/auth/login` | public | Keep accessible. Users must be able to log in during restricted mode. |
| `GET /api/v1/health` | public | Keep accessible. Health checks must not require a valid license. |
| `POST /api/v1/sandbox/payload/validate` | public | Keep accessible as non-mutating provider diagnostic, rate-limited. |
| `POST /api/mcp` | needs-decision | Public MCP endpoint. Confirm production exposure before enforcement. |
| `GET /api/api-test` | deprecated-or-remove | Test endpoint. Remove or disable in production. |
| `GET /api/system-status` | deprecated-or-remove | Duplicate simple status endpoint. Prefer `/api/v1/health`. |
| `GET /api/retry-check` | deprecated-or-remove | Direct DB diagnostic; remove or admin-protect before enforcement. |
| `POST /api/v1/test-parser` | deprecated-or-remove | Test/support parser endpoint; confirm whether production needs it. |

### License Diagnostics

These must stay outside `license.valid`.

| Route Surface | Classification | Decision |
|---|---|---|
| `GET /api/license/status` | license-diagnostic | Vendor-license-authority only, throttled. Keep accessible in restricted mode. |
| `GET /api/license/capabilities` | license-diagnostic | Vendor-license-authority only, throttled. Keep accessible in restricted mode. |
| `POST /api/license/upload` | license-diagnostic | Vendor-license-authority only, throttled. Required for replacement/redeploy/reuse license actions. |
| `POST /api/license/recovery-request` | license-diagnostic | Vendor-license-authority only, throttled. Required for reinstall/redeploy/reuse recovery package generation. |

### Authenticated But Not License-Blocked Initially

| Route Surface | Classification | Decision |
|---|---|---|
| `POST /api/auth/logout` | auth-only | Keep accessible. |
| `GET /api/auth/user` | auth-only | Keep accessible. |
| `POST /api/v1/auth/terminal` | auth-only / needs-decision | Terminal login may be needed during recovery; block activation/intake instead. |
| `POST /api/v1/auth/refresh` | auth-only / needs-decision | Keep initially. Revisit after terminal binding exists. |
| `GET /api/v1/auth/me` | auth-only / needs-decision | Keep initially. Useful for terminal diagnostics. |
| `POST /api/v1/heartbeat` | auth-only / needs-decision | Recommended: observe-only later, not first hard block. |
| `GET /api/v1/tokens/introspect` | auth-only | Keep available for token diagnostics. |
| `POST /api/v1/checksum/sandbox` | auth-only | Non-mutating checksum utility. Do not license-block in first pass. |

### License-Protected: First Observe Attachment Candidates

Attach `license.valid` here first, with `LICENSE_ENFORCEMENT_MODE=observe`.

| Route Surface | Classification | Reason |
|---|---|---|
| `POST /api/v1/transactions/official` | license-protected | Main POS intake path; observe first to measure production impact. |
| `POST /api/v1/transactions/batch` | license-protected | POS batch intake; observe first. |
| `POST /api/v1/transactions/{transaction_id}/refund` | license-protected | POS mutation tied to licensed terminal/deployment. |
| `POST /api/v1/transactions/{transaction_id}/void` | license-protected | POS mutation tied to licensed terminal/deployment. |

### License-Protected: API Admin / Management

Attach after first observe pass confirms no route/runtime issues.

| Route Surface | Classification | Reason |
|---|---|---|
| `POST /api/tenants` | license-protected | Tenant creation must be limited by licensed deployment/location. |
| `PUT /api/tenants/{tenant}` | license-protected | Tenant binding must not drift outside deployment/location. |
| `DELETE /api/tenants/{tenant}` | license-protected | Protected management operation. |
| `POST /api/tenants/{tenant}/users` | license-protected | Tenant-scoped management. |
| `DELETE /api/tenants/{tenant}/users/{user}` | license-protected | Tenant-scoped management. |
| `POST /api/terminals` | license-protected | Terminal creation/activation must be licensed. |
| `PUT /api/terminals/{terminal}` | license-protected | Terminal binding must not drift outside deployment/location. |
| `PUT /api/terminals/{terminal}/expiry` | license-protected | Terminal lifecycle management. |
| `POST /api/terminals/tokens/{terminalId}/regenerate` | license-protected | Terminal credential lifecycle. |
| `POST /api/terminals/tokens/{terminalId}/revoke` | license-protected | Terminal credential lifecycle. |
| `POST /api/v1/terminals/{terminalId}/generate-token` | license-protected | Terminal credential lifecycle. |
| `POST /api/v1/terminals/generate-all-tokens` | license-protected | Bulk terminal credential lifecycle. |

### License-Protected: Reports, Logs, Exports

Attach after tenant/terminal binding fields exist and report filters can be checked safely.

| Route Surface | Classification | Reason |
|---|---|---|
| `GET /api/dashboard/metrics` | license-protected | Business dashboard data. |
| `GET /api/dashboard/charts` | license-protected | Business dashboard data. |
| `GET /api/dashboard/transactions` | license-protected | Business dashboard data. |
| `GET /api/dashboard/terminal-performance` | license-protected | Terminal-scoped business data. |
| `GET /api/transactions/logs*` | license-protected | Transaction logs and exports. |
| `POST /api/transactions/logs/reconcile` | license-protected | Business mutation. |
| `GET /api/v1/submission-events*` | license-protected | Transaction submission audit data. |
| `GET /api/v1/submissions/{submission_uuid}` | license-protected | Provider transaction status/support lookup. |
| `GET /api/v1/incidents*` | license-protected | Operational incident data. |
| `GET /api/v1/webapp/transactions*` | license-protected | Machine-to-machine transaction read API. |
| `GET /api/v1/webapp/reports*` | license-protected | Machine-to-machine report API. |

### Admin / Operational APIs

| Route Surface | Classification | Decision |
|---|---|---|
| `GET /api/dashboard/system-health` | auth-only / needs-decision | Keep initially. May need restricted-mode visibility. |
| `GET /api/dashboard/audit-logs` | auth-only / needs-decision | Keep initially for support; consider protection later. |
| `GET /api/v1/admin/failed-jobs*` | auth-only / needs-decision | Operational recovery routes. Do not block until ops policy is agreed. |
| `GET /api/v1/observability/*` | auth-only / needs-decision | Useful during observe rollout; protect later if business-sensitive. |
| `GET /api/web/dashboard/logs*` | license-protected | Log access/export should be protected after diagnostics are separated. |

### Legacy / Test / Debug API Surface

These should be removed, disabled, or explicitly protected before restricted/enforce mode.

| Route Surface | Classification | Decision |
|---|---|---|
| `GET /api/transactions/{id}/status` | deprecated-or-remove | Public legacy status route. Replace with authenticated provider route. |
| `GET /api/v1/retry-history/debug` | deprecated-or-remove | Exposes DB/schema diagnostics. |
| `GET /api/v1/retry-history/diagnostics` | deprecated-or-remove | Public diagnostic. |
| `GET /api/v1/retry-history/simple-status` | deprecated-or-remove | Duplicate diagnostic. |
| `POST /api/v1/retry-history/seed` | deprecated-or-remove | Data mutation/debug. |
| `POST /api/v1/retry-history/force-seed` | deprecated-or-remove | Data mutation/debug. |
| `GET /api/v1/recent-test-transactions` | deprecated-or-remove | Test data endpoint. |
| `GET /api/v1/transactions/{id}/details` | deprecated-or-remove | Public transaction detail endpoint. |
| `GET /api/v1/transaction-id-exists` | deprecated-or-remove | Public lookup endpoint. |
| `POST /api/transactions/bulk` | needs-decision | Bulk transaction API outside main authenticated POS group; classify before enforcement. |

## Web Routes

### Public / Auth-Only

| Route Surface | Classification | Decision |
|---|---|---|
| `/` | auth-only | Redirect route. Keep available. |
| `GET|POST /login` | public | Keep accessible. |
| `POST /logout` | auth-only | Keep accessible. |
| `GET /sandbox/payload` | public | Provider sandbox UI, non-mutating. |
| `GET /docs/pos-provider/api-testing` | public | Provider docs/support route. |
| `GET /up` | public | Framework health check. |
| `GET /sanctum/csrf-cookie` | public | Required for SPA auth. |

### Web SPA Shells

Recommended initial approach: do not attach `license.valid` to SPA shell routes first. Protect the backing APIs/data/export routes first, otherwise users can be locked out of diagnostics and recovery screens.

| Route Surface | Classification | Decision |
|---|---|---|
| `/dashboard/{any?}` | auth-only initially | SPA shell. Backend APIs enforce data access. |
| `/reports` | auth-only initially | SPA shell. |
| `/finance` | auth-only initially | SPA shell. |
| `/commercial*` page shell routes | auth-only initially | SPA shell. |
| `/transactions` | auth-only initially | SPA shell. |
| `/terminal-tokens` | auth-only initially | SPA shell. |
| `/users` | auth-only initially | SPA shell. |

### Web License-Protected Business Operations

| Route Surface | Classification | Reason |
|---|---|---|
| `GET /reports/data` | license-protected | CMSR/report data. |
| `GET /finance/reports/export` | license-protected | Report export. |
| `GET /commercial/reports/transactions/*` | license-protected | Commercial reports. |
| `GET /commercial/reports/export` | license-protected | Commercial export. |
| `GET /commercial/reports/tenants*` | license-protected | Tenant report data/export. |
| `GET /transactions/logs/*` | license-protected | Transaction logs, summaries, exports. |
| `POST /transactions/logs/export` | license-protected | Transaction log export. |
| `POST /transactions/{id}/retry` | license-protected | Transaction mutation. |
| `POST /transactions/retry/{transaction}` | license-protected | Transaction mutation. |
| `POST /transactions/bulk-generate` | deprecated-or-remove | Test/bulk generation route; do not allow in production. |
| `POST /terminal-tokens/*` | license-protected | Terminal credential lifecycle. |
| `POST|PUT|PATCH|DELETE /users*` | license-protected | User management. |
| `GET|POST /admin/settings` | license-protected | Admin settings. |

### Web Diagnostics / Operational Routes

| Route Surface | Classification | Decision |
|---|---|---|
| `/log-viewer*` | needs-decision | Useful diagnostics; likely auth-only during observe, protected later. |
| `/system-logs*` | needs-decision | Operational diagnostics; prune/destructive routes should remain admin-only and may be protected later. |
| `/dashboard/performance*` | needs-decision | Operational/reporting data. |
| `/dashboard/providers*` | license-protected | Provider/business management data. |
| `/providers*` | license-protected | Provider/business management data. |
| `/circuit-breakers` | needs-decision | Operational control page. |
| `/retry-check` | deprecated-or-remove | Direct DB diagnostic; remove or admin-protect. |
| `/system-status` | deprecated-or-remove | Duplicate health endpoint. |
| `/test-transaction*` | deprecated-or-remove | Test route. |
| `/transactions/test*` | deprecated-or-remove | Test route. |
| `/terminal-test` | deprecated-or-remove | Test route. |

## Horizon / Ignition / MCP

| Route Surface | Classification | Decision |
|---|---|---|
| Horizon command-center routes | auth-only / needs-decision | Operational. Do not license-block in first pass. Verify production auth. |
| `_ignition/*` | deprecated-or-remove | Must not be available in production. |
| `mcp`, `mcp/sse*` | needs-decision | Confirm production exposure and auth model. |

## Recommended First Middleware Attachment

Applied first observe-ready attachment:

```text
POST /api/v1/transactions/official
POST /api/v1/transactions/batch
POST /api/v1/transactions/{transaction_id}/refund
POST /api/v1/transactions/{transaction_id}/void
```

Required mode:

```env
LICENSE_ENFORCEMENT_MODE=observe
```

Expected behavior:

```text
Invalid license or mismatch is logged to license_audit_logs.
POS requests continue.
No tenant/terminal/location binding block is applied yet.
```

## Do Not Attach Yet

Do not attach `license.valid` yet to:

- login/logout/auth routes
- license diagnostics
- health checks
- SPA shell routes
- debug/legacy routes that should be removed instead
- report routes until tenant/location binding fields exist
- tenant/terminal management until binding migrations/backfill are ready

## Open Decisions Before Restricted/Enforce Mode

- Should terminal heartbeat be allowed, observe-only, or blocked in restricted mode?
- Should terminal auth be allowed but POS intake blocked in restricted mode?
- Which debug/test routes are removed before production enforcement?
- Should operational routes such as Horizon, failed jobs, observability, and log viewer remain available during restricted mode?
- Are webapp machine-to-machine read APIs active in production, and should they be included in the second protected wave?
