<?php

namespace App\Http\Middleware;

use App\Models\PosTerminal;
use App\Services\IngestionFairnessService;
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

        // Global -> tenant -> terminal: cheapest/most-aggregate check first,
        // reject on the first exceeded scope.
        $result = $this->fairnessService->checkGlobal();

        if ($result['allowed'] && $tenantId !== null) {
            $result = $this->fairnessService->checkTenant($tenantId);
        }

        if ($result['allowed'] && $terminalId !== null) {
            $result = $this->fairnessService->checkTerminal($terminalId);
        }

        if ($result['allowed']) {
            return $next($request);
        }

        $correlationId = $request->attributes->get('correlation_id') ?: $request->header('X-Request-Id');

        // WU4 (T053 remainder): rejection-reason counter. A single counter
        // across all three scopes (global/tenant/terminal), matching the
        // one-counter-per-middleware-reason convention used by the other
        // three ingestion rejection paths; $result['scope'] is still
        // available in the JSON body below for per-scope diagnosis without
        // needing a higher-cardinality metric key. Metrics::incr() swallows
        // its own failures, so this can never affect the 429 response below.
        Metrics::incr('ingestion.rejected.fairness');

        return response()->json([
            'success' => false,
            'error_code' => 'FAIRNESS_LIMIT_EXCEEDED',
            'message' => 'Ingestion temporarily throttled due to fairness limits. Retry later.',
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
