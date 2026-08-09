# Tenant Throttling Runbook

## 1. Purpose and Scope

This runbook documents WU5's tenant fairness override system — a
Redis-backed, TTL-bounded, **incident-response-only** control for
throttling or blocking ingestion from **one specific tenant**, without
redeploying config for every tenant. It is operated entirely through three
Artisan commands, backed by `App\Services\TenantFairnessOverrideService`
and consulted on every ingestion request by
`IngestionFairnessService::checkTenantOverride()`
(`App\Http\Middleware\IngestionFairnessMiddleware`).

This is **not** a tenant-tier/policy system. It has no tier concept, applies
to exactly one tenant at a time, and every override expires by design —
there is no permanent override
(`app/Services/TenantFairnessOverrideService.php:11-45`, class doc-comment).
A persistent, config-driven tenant-tier system is explicitly deferred/out
of scope for this feature (`specs/001-100-tenant-resilience/plan.md`,
"Fairness Architecture," point 7) and this system does not reopen that
decision.

This document does not cover the base global/tenant/terminal fixed-window
fairness limits (`IngestionFairnessService::checkGlobal()`/`checkTenant()`/
`checkTerminal()`) except where the override interacts with them, nor
circuit-breaker/backpressure mechanics in general — see
`docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md` for those. **Section 6 below
is the one place the two documents intersect, and it is the most important
section in this runbook** — read it before relying on a `blocked` override
during any incident that also involves Redis instability.

## 2. The Three Commands

All three require `--tenant=<positive integer>`. `set` and `clear` also
require `--operator=<non-empty string>` — a real, intentional identity for
the audit trail (deliberately **not** inferred from the OS process owner;
see `app/Console/Commands/TenantThrottleSet.php:17-24`'s doc-comment).

### 2.1 `ingestion:tenant-throttle:set`

```
ingestion:tenant-throttle:set
    {--tenant= : Tenant ID to throttle}
    {--mode= : Override mode: inherit|reduced_limit|blocked}
    {--limit= : Tenant-specific request limit (required when --mode=reduced_limit)}
    {--ttl= : Override TTL in seconds (must not exceed the configured maximum)}
    {--reason= : Reason for this override, recorded for the audit trail}
    {--operator= : Authorizing operator/CLI identity, recorded for the audit trail}
```

Creates or **replaces** (idempotent overwrite, not error-on-exists) a
tenant's override. Validation order
(`app/Services/TenantFairnessOverrideService.php:128-158`):

1. `--mode` must be `inherit`, `reduced_limit`, or `blocked`.
2. If `--mode=reduced_limit`, `--limit` must be a positive integer.
3. `--ttl` must be positive **and must not exceed**
   `config('tsms.tenant_throttle.max_ttl_seconds')` (default `14400` / 4
   hours). A too-large TTL is **rejected outright, not silently clamped** —
   this is deliberate: an operator needs to know the real expiry they're
   getting, not discover later that a silently-shortened TTL let a blocked
   tenant back in earlier than assumed.
4. `--reason` must be non-empty.
5. `--operator` must be non-empty (also checked earlier, command-side, at
   `app/Console/Commands/TenantThrottleSet.php:59-63`).
6. The tenant ID must correspond to an existing, non-deleted `Tenant` row —
   setting an override for an unknown tenant is refused.

Examples:

```bash
# Block a tenant outright for 30 minutes during a runaway-retry incident
php artisan ingestion:tenant-throttle:set \
  --tenant=42 --mode=blocked --ttl=1800 \
  --reason="runaway retry loop from terminal firmware bug, isolating pending vendor fix" \
  --operator="jdoe"

# Cut a noisy tenant's limit to 20/min for 1 hour instead of blocking outright
php artisan ingestion:tenant-throttle:set \
  --tenant=42 --mode=reduced_limit --limit=20 --ttl=3600 \
  --reason="dominating fairness skew ranking, degrading other tenants" \
  --operator="jdoe"
```

Output is a table (Tenant, Mode, Limit, TTL (s), Expires At, Operator,
Reason) preceded by `Override created.` or `Override replaced.` — logged
immediately as one of those two real events
(`TenantFairnessOverrideService: override created` /
`... override replaced`), never as a claim about what happened before this
process ran.

### 2.2 `ingestion:tenant-throttle:status`

```
ingestion:tenant-throttle:status
    {--tenant= : Tenant ID to inspect}
```

Read-only. Resolves and prints the tenant's current override state:

```bash
php artisan ingestion:tenant-throttle:status --tenant=42
```

Output is a table (Tenant, Mode, Limit, Retry-After (s), Expires At,
Operator, Reason, Source). **Read §3 before trusting this output during an
incident** — `status` cannot tell you everything an operator might assume
it can.

### 2.3 `ingestion:tenant-throttle:clear`

```
ingestion:tenant-throttle:clear
    {--tenant= : Tenant ID to clear the override for}
    {--operator= : Authorizing operator/CLI identity, recorded for the audit trail}
```

Idempotent: clearing a tenant with no existing override is a **no-op
success**, never an error (`app/Services/TenantFairnessOverrideService.php:220-226`).

```bash
php artisan ingestion:tenant-throttle:clear --tenant=42 --operator="jdoe"
```

Prints `Override cleared for tenant 42.` or `No override existed for tenant
42 (no-op success).` — both are logged as a real `clear requested` event
regardless of which outcome occurred.

## 3. The Honest Expiry/Absence Model — Read Before Trusting `status`

**Do not overstate what `status` can tell you.** A missing or expired
override key resolves to `mode: inherit`, and the store has **no way** to
distinguish, from key absence alone:

- an override that was **never created**,
- an override that was **explicitly cleared** via `clear`, or
- an override whose TTL **naturally expired**.

Redis TTL expiry does not execute application code — there is no scheduled
sweeper and no Redis keyspace-notification listener in this codebase
(deliberate scope control; see the class doc-comment,
`app/Services/TenantFairnessOverrideService.php:56-64`). The only way any
of these three is distinguished is a separately-retained log entry from
when `set`/`clear` originally ran — never the current key state by itself.

`resolve()`'s `source` field is a diagnostic-only signal (never consumed by
admission logic) with exactly two non-active values, and they mean
different things:

- **`absent`** — the key genuinely is not present right now. `status`
  prints: *"No active override for tenant N (mode: inherit). This does not
  distinguish 'never set' from 'explicitly cleared' or 'TTL-expired' — the
  store cannot tell these apart from key absence alone."*
  (`app/Console/Commands/TenantThrottleStatus.php:44`)
- **`redis_error`** — the read itself failed (or the stored payload was
  unreadable). `status` prints: *"Could not read override state for tenant
  N: the override read itself failed. Fairness will FAIL OPEN to 'inherit'
  for this tenant until Redis is reachable again (Architecture Invariant
  1) — this does NOT mean no override was ever set."*
  (`app/Console/Commands/TenantThrottleStatus.php:41-42`)

**What `status` is actually useful for during an incident**: not leak
detection (every override is TTL-bounded and self-expires by design, so
there is no "silently persisted past its intended duration" scenario for
`status` to catch). What it verifies is narrower: *did the incident-response
action you took actually resolve* — after the triggering incident is
believed over, does `status` show `inherit` as expected, or does it still
show `reduced_limit`/`blocked` with time remaining (meaning you must decide
whether to let the TTL run out or `clear` it early)? If `status`
unexpectedly shows `inherit` while you believed an override was active,
check whether it already expired (compare against the `--ttl` you
originally requested and when `set` ran) — do not assume it was silently
"never applied."

## 4. `blocked` Mode: 429 and `Retry-After` Behavior

`IngestionFairnessMiddleware` calls
`IngestionFairnessService::checkTenantOverride($tenantId)` **before**
`checkGlobal()`/`checkTenant()`/`checkTerminal()`
(`app/Http/Middleware/IngestionFairnessMiddleware.php:73-100`). When the
resolved mode is `blocked`:

- The request is rejected immediately — `checkGlobal()`/`checkTenant()`/
  `checkTerminal()` are **never called** for this request; a blocked
  tenant's traffic does not even consume the global/terminal fixed-window
  budget shared with other tenants.
- Response: HTTP **429**, JSON body:
  ```json
  {
    "success": false,
    "error_code": "TENANT_THROTTLE_BLOCKED",
    "message": "Ingestion blocked for this tenant by an active incident-response override. Retry later.",
    "scope": "tenant_override",
    "limit": null,
    "count": null,
    "retry_after_seconds": <int>,
    "reset_at": "<override's expires_at>",
    "correlation_id": "<request correlation id>"
  }
  ```
  with a `Retry-After` header set to the **same** `retry_after_seconds`
  value (`app/Http/Middleware/IngestionFairnessMiddleware.php:141-160`).
- **`retry_after_seconds` is the override's live remaining TTL**, read from
  Redis at request time (`TenantFairnessOverrideService::resolve()`'s
  `TTL` call), **not a fixed constant** — it decreases on successive
  requests as the override approaches its own expiry.
- Counted separately from ordinary fairness rejections:
  `Metrics::incr('ingestion.rejected.tenant_override')`, distinct from the
  pre-existing `ingestion.rejected.fairness` counter — a `blocked`-override
  rejection is a deliberate operator action, not an organic fairness-limit
  breach.

**`reduced_limit` mode does not use this rejection path.** A `reduced_limit`
override is `allowed=true` at the override-check step; its `limit` is
threaded into the subsequent `checkTenant($tenantId, $tenantLimitOverride)`
call, replacing that tenant's configured limit for that call only. If the
*reduced* limit is then exceeded, the resulting 429 goes through the
**ordinary** fairness-rejection path (`error_code: FAIRNESS_LIMIT_EXCEEDED`,
`scope: tenant`, counted under `ingestion.rejected.fairness`) — it is
**indistinguishable from a normal tenant fairness-limit rejection** by
error code, scope, or metric. Use `ingestion:tenant-throttle:status` to
confirm a `reduced_limit` override is active if you need to attribute a
tenant's 429s to your override specifically.

## 5. Fail-Open Is Absolute for This Store — By Design

Every Redis error, a missing key, an expired key, or an unreadable stored
value in `TenantFairnessOverrideService::resolve()` resolves to
`mode: inherit` with every other field `null` — **never** `mode: blocked`,
regardless of what was last written (Architecture Invariant 1;
`app/Services/TenantFairnessOverrideService.php:48-54,328-341`). A Redis
outage must never accidentally block/throttle a tenant that has an active
override — it fails toward "normal fairness behavior applies." This is
consistent with `IngestionFairnessService`'s own fail-open convention for
the base global/tenant/terminal checks.

This same guarantee is exactly what creates the outage risk in §6 below —
read it now if you have an active `blocked` override and are seeing any
Redis instability.

## 6. Critical: The Observe-Mode Outage Risk

**This is the most important operational fact in this document.** During a
Redis failure, a tenant you have explicitly set to `blocked` can degrade to
**unrestricted** admission — not merely "back to normal fairness limits,"
but effectively unthrottled — if the system is in its **default**
backpressure mode.

**Why this happens:**

- The default backpressure mode is `observe`
  (`config('tsms.intake.backpressure.mode')`, `config/tsms.php:80`, env
  `TSMS_INTAKE_BACKPRESSURE_MODE`).
- On a Redis error, **both** layers that would otherwise stop a `blocked`
  tenant fail open to `inherit`/admit:
  - `TenantFairnessOverrideService::resolve()` fails open to `inherit`
    (§5) — the override check itself stops blocking.
  - `IngestionFairnessService`'s base global/tenant/terminal fixed-window
    checks **also** fail open on their own Redis errors (Architecture
    Invariant 1, "Fairness Architecture" point 4 in
    `specs/001-100-tenant-resilience/plan.md`; corroborated by
    `docs/OBSERVABILITY_ALERT_DEFINITIONS.md` §5: *"`IngestionFairnessService`
    and WU5's tenant-override check both fail open (admit as `inherit`) on
    Redis error"*) — deliberately, so that a Redis outage does not reject
    100% of tenants, not just the one hot tenant this feature exists to
    contain.
- **Backpressure provides no fail-closed backstop outside `enforce` mode.**
  `IngestionBackpressureService::checkQueue()` fails closed **only when
  `mode === 'enforce'`** (`docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md`
  §5). In `observe` mode (the default), the same Redis failure produces
  **no rejection at all** — only log lines.
- Net effect: with all three layers either failing open or not enforcing,
  a tenant you deliberately blocked is, for the duration of the Redis
  outage, admitted the same as any unthrottled tenant.

**Detection signals:**

- Any WU7 observability endpoint returning `"status": "unavailable"` in its
  response envelope (`docs/OBSERVABILITY_DASHBOARD.md` §2;
  `docs/OBSERVABILITY_ALERT_DEFINITIONS.md` §5).
- Redis connectivity log lines: `CircuitBreaker: failed to read state,
  failing open`, `TenantFairnessOverrideService: failed to resolve
  override, failing open to inherit` /
  `TenantFairnessOverrideService: failed to resolve override` (from
  `resolve()`'s catch block), `Failed to evaluate ingestion backpressure`.
- `ingestion:tenant-throttle:status --tenant=<id>` returning `source:
  redis_error` for the tenant you believe is blocked — this is a direct,
  actionable signal that the override read itself is failing, not just a
  theoretical risk (§3's `redis_error` case).

**The operator's actual choice — not an automatic action:**

If a `blocked` (or `reduced_limit`) override is actively relied upon during
an incident and Redis becomes unreliable, switching backpressure to
`enforce` mode (`TSMS_INTAKE_BACKPRESSURE_MODE=enforce`, requires a config
change and restart — this is not a live toggle) restores a fail-closed
backstop **for the whole ingestion path**, not just the throttled tenant.
This is a genuine trade-off, not a recommendation to always take this
action:

- **Cost of switching to `enforce`**: every tenant's traffic — not just the
  one you throttled — is now subject to fail-closed rejection if the
  backpressure Redis read fails, meaning a broader, indiscriminate
  rejection risk across all tenants for as long as Redis remains
  unreliable.
- **Cost of not switching**: the specific tenant you blocked (or
  rate-reduced) regains effectively unrestricted admission for the
  duration of the outage — the exact condition your override was meant to
  prevent.

There is no single correct answer here; it depends on why the tenant was
blocked in the first place (e.g., a genuinely runaway/abusive client vs. a
routine fairness-skew mitigation) weighed against how disruptive a
whole-path fail-closed posture would be for every other tenant during the
same outage. Make this decision deliberately, and document it in the
incident record — do not treat either mode as the "safe default" to revert
to once Redis recovers without a second deliberate decision to do so.

## 7. Redis Keys

Single string key per tenant, TTL-bound (`EX` at set time):

```
{tsms.tenant_throttle.key_prefix}{tenant_id}
```

Default prefix `fairness:override:` (`config('tsms.tenant_throttle.key_prefix')`),
under the Redis connection named by `config('tsms.tenant_throttle.redis_connection')`
(default `default` — the same connection circuit breaker and base fairness
use by default, which is why a single Redis outage on that connection
affects all three simultaneously; see
`docs/OBSERVABILITY_ALERT_DEFINITIONS.md` §5's cross-cutting framing).
Value is a JSON payload (`mode`, `limit`, `reason`, `operator`, `set_at`,
`expires_at`). Safe to inspect read-only:

```
redis-cli GET fairness:override:42
redis-cli TTL fairness:override:42
```

Do not manually `SET`/`DEL` this key outside the three commands above — use
`clear` even for an immediate removal, so the audit-trail log entry is
still recorded.

## 8. Validation

```
php artisan test --filter=TenantFairnessOverrideService   # unit coverage for set/clear/resolve, including fail-open paths
php artisan test --filter=TenantThrottle                  # command-level coverage
php artisan test --filter=IngestionFairnessMiddleware      # HTTP-level: blocked rejection, reduced_limit threading
```

(Run `php artisan test --testsuite=Unit` / `--testsuite=Feature` for the
full suite if these filters do not match your local test names exactly.)

## Unknown / Not Verifiable From This Repository

- Real production incident history for this override system (how often
  `blocked` vs. `reduced_limit` has actually been used, and whether any
  incident has coincided with the §6 outage scenario in practice) is not
  present in this repository — **unknown**.
- Whether an organization-specific incident process requires a second
  approver before running `set --mode=blocked` is **unknown** — this
  runbook documents the command's actual behavior only, not any
  organizational approval policy around invoking it.
