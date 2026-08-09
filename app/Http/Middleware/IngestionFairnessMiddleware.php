<?php

namespace App\Http\Middleware;

use App\Models\PosTerminal;
use App\Services\IngestionFairnessService;
use App\Services\TenantFairnessOverrideService;
use App\Support\Metrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * T045: Applies fairness (global/tenant/terminal) admission checks to
 * official and batch ingestion, positioned LAST in the ingestion middleware
 * chain — after ingestion.payload_size, ingestion.backpressure:processing,
 * and circuit.breaker:transaction-intake (see plan.md's "Fairness
 * Architecture" subsection, point 3). Cheapest/most-global checks already
 * ran; this is the most granular, most expensive per-tenant+terminal check,
 * and only runs for requests that already passed system-wide health gates.
 *
 * Identity resolution mirrors plan.md's "Fairness Architecture" point 8,
 * matching TransactionIntakeService's real resolution order: the
 * authenticated PosTerminal (guaranteed present here since auth:sanctum +
 * abilities:transaction:create already ran earlier in the same route
 * group) is the primary source, with request-input fallback purely
 * defensive. If tenant identity still cannot be resolved as a positive
 * integer, the tenant (and terminal) fairness check is skipped entirely —
 * fairness never invents a rejection for an identity problem, and never
 * checks a placeholder/zero/null scope id, since that would let unrelated
 * unresolved-identity traffic collide into one shared, meaningless bucket.
 * Later structural/FormRequest validation owns rejecting malformed/
 * unauthenticated requests.
 *
 * IngestionFairnessService already fails open internally on Redis errors
 * (T044), so this middleware does not need its own try/catch around
 * calling it — consistent with how CircuitBreakerMiddleware treats its own
 * already-fail-open-internally dependency.
 *
 * Must never call or reference IngestionQueueRouter — routing ("which
 * queue") and fairness ("allow now") are separate abstractions (plan.md's
 * "Fairness Architecture" subsection, point 2).
 *
 * WU5 addition: before the global/tenant/terminal composition below, this
 * middleware first consults IngestionFairnessService::checkTenantOverride()
 * for the resolved tenant — a TTL-bounded, incident-response override for
 * ONE specific tenant (inherit/reduced_limit/blocked). A `blocked` override
 * short-circuits here and never reaches checkGlobal()/checkTenant()/
 * checkTerminal() at all. A `reduced_limit` override's limit is threaded
 * into the checkTenant() call that follows, replacing that tenant's
 * configured limit for this request only; every other tenant is completely
 * unaffected. checkTenantOverride() returns a result shaped identically to
 * checkGlobal()/checkTenant()/checkTerminal() (allowed/scope/limit/count/
 * retry_after_seconds/reset_at), so the single rejectionResponse() helper
 * below serves both the base fairness rejection and the new override
 * rejection with no per-mode special-casing of the HTTP response itself.
 */
class IngestionFairnessMiddleware
{
    public function __construct(private readonly IngestionFairnessService $fairnessService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $terminal = $request->user();

        $tenantId = $this->resolvePositiveInt(
            $terminal instanceof PosTerminal ? $terminal->tenant_id : $request->input('tenant_id')
        );
        $terminalId = $this->resolvePositiveInt(
            $terminal instanceof PosTerminal ? $terminal->id : $request->input('terminal_id')
        );

        // WU5: resolve the tenant's incident-response override, if any,
        // BEFORE the existing global-limit decision below. Unresolved
        // identity (tenantId === null) skips the override check exactly
        // like it already skips the tenant fairness check — fairness (and
        // its override) never invents a rejection for an identity problem.
        $tenantLimitOverride = null;

        if ($tenantId !== null) {
            $override = $this->fairnessService->checkTenantOverride($tenantId);

            if (! $override['allowed']) {
                // 'blocked': reject immediately. checkGlobal()/checkTenant()/
                // checkTerminal() are never called for this request — a
                // blocked tenant's traffic must not even consume the
                // global/terminal fixed-window budget it would otherwise
                // share with every other tenant.
                return $this->rejectionResponse(
                    $request,
                    $override,
                    'TENANT_THROTTLE_BLOCKED',
                    'Ingestion blocked for this tenant by an active incident-response override. Retry later.'
                );
            }

            if ($override['mode'] === TenantFairnessOverrideService::MODE_REDUCED_LIMIT) {
                $tenantLimitOverride = $override['limit'];
            }
        }

        // Global -> tenant -> terminal: cheapest/most-aggregate check first,
        // reject on the first exceeded scope.
        $result = $this->fairnessService->checkGlobal();

        if ($result['allowed'] && $tenantId !== null) {
            $result = $this->fairnessService->checkTenant($tenantId, $tenantLimitOverride);
        }

        if ($result['allowed'] && $terminalId !== null) {
            $result = $this->fairnessService->checkTerminal($terminalId);
        }

        if ($result['allowed']) {
            return $next($request);
        }

        return $this->rejectionResponse(
            $request,
            $result,
            'FAIRNESS_LIMIT_EXCEEDED',
            'Ingestion temporarily throttled due to fairness limits. Retry later.'
        );
    }

    /**
     * Shared 429 response builder for both the base fairness rejection
     * (global/tenant/terminal, $result['scope'] one of those three) and the
     * WU5 tenant-override rejection ($result['scope'] === 'tenant_override').
     * Both shapes carry the same six fields (allowed/scope/limit/count/
     * retry_after_seconds/reset_at), which is exactly what lets this one
     * method serve both without special-casing the new override outcome.
     *
     * The rejection-reason counter is split by scope so the pre-existing
     * `ingestion.rejected.fairness` counter's semantics (and the assertion
     * in tests/Feature/IngestionFairnessMiddlewareTest.php that reads it)
     * are undisturbed by this addition — a tenant-override rejection is a
     * deliberate operator action, not an organic fairness-limit rejection,
     * so it is counted separately under `ingestion.rejected.tenant_override`.
     */
    private function rejectionResponse(Request $request, array $result, string $errorCode, string $message): Response
    {
        $correlationId = $request->attributes->get('correlation_id') ?: $request->header('X-Request-Id');

        Metrics::incr($result['scope'] === 'tenant_override'
            ? 'ingestion.rejected.tenant_override'
            : 'ingestion.rejected.fairness');

        return response()->json([
            'success' => false,
            'error_code' => $errorCode,
            'message' => $message,
            'scope' => $result['scope'],
            'limit' => $result['limit'],
            'count' => $result['count'],
            'retry_after_seconds' => $result['retry_after_seconds'],
            'reset_at' => $result['reset_at'],
            'correlation_id' => $correlationId,
        ], 429)->header('Retry-After', (string) $result['retry_after_seconds']);
    }

    /**
     * Normalizes a raw identity value (authenticated model attribute or
     * request input, which may be a string, null, or missing) to a positive
     * int, or null when it cannot be resolved as one. Never returns 0 —
     * callers must treat null as "skip this scope's check", not "check
     * scope id 0".
     */
    private function resolvePositiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }
}
