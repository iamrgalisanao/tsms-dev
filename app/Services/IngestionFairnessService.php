<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed fixed-window fairness limiter for ingestion (T044).
 *
 * Answers three independent admission questions — "is the global rate
 * within budget", "is this tenant within budget", "is this terminal within
 * budget" — using an INCR+conditional-EXPIRE fixed-window counter per
 * scope, executed as a single atomic Redis Lua script (FAIRNESS_CHECK_SCRIPT)
 * rather than two separate round-trips. A prior revision issued INCR then
 * EXPIRE as two calls inside one try/catch: if INCR succeeded but EXPIRE
 * then threw, the catch block discarded the real (already-incremented)
 * count and reported count=0/allowed=true, and the key was left without a
 * TTL (review finding F1). Folding both steps into one EVAL removes that
 * partial-failure window entirely — either the whole script runs (real
 * count obtained, TTL applied correctly on creation) or the EVAL call
 * itself fails and the outer catch's fail-open path is taken, with no
 * possible in-between state (see check()'s doc-comment for the full
 * reasoning). This still is NOT a token-bucket and does not need
 * CircuitBreaker's compare-and-set/generation machinery — a rate window is
 * a single atomic increment-and-maybe-expire, not a multi-step state race
 * (see specs/001-100-tenant-resilience/plan.md's "Fairness Architecture"
 * subsection, point 1).
 *
 * Scopes are fully independent: exhausting the global counter does not
 * affect any tenant/terminal counter and vice versa. This service does not
 * compose the three checks into one verdict — that composition (if a
 * caller needs one) belongs to the fairness middleware (T045), not here.
 *
 * This service owns Redis I/O and answers "allow or throttle now"; it must
 * never reference IngestionQueueRouter or compute/return a queue name —
 * routing ("which queue") and fairness ("allow now") are separate
 * abstractions (plan.md's "Fairness Architecture" subsection, point 2).
 *
 * Fails OPEN on any Redis error — this deliberately diverges from
 * IngestionBackpressureService's fail-closed-in-enforce-mode convention,
 * because backpressure's aggregate check is already the fail-closed
 * backstop for the ingestion path; fairness is a peer/refinement above it,
 * not a second backstop (plan.md's "Fairness Architecture" subsection,
 * point 4).
 */
class IngestionFairnessService
{
    private const SCOPE_GLOBAL = 'global';

    private const SCOPE_TENANT = 'tenant';

    private const SCOPE_TERMINAL = 'terminal';

    /**
     * Fixed scope_id used for the 'global' scope, since it has no natural
     * tenant/terminal identifier. Chosen to be 0 (rather than a string
     * constant like 'all') so every fairness key segment is a plain
     * integer, keeping the key format uniform across scopes.
     */
    private const GLOBAL_SCOPE_ID = 0;

    /**
     * Atomically increments the active window's counter and, only on the
     * INCR that creates the key (result exactly 1), applies EXPIRE with
     * the window's TTL — folding both steps into a single Redis round-trip
     * so there is no window between "count incremented" and "TTL applied"
     * where a caller could observe (or a failure could produce) a
     * genuinely-incremented-but-unreported count (review finding F1).
     *
     * Follows this codebase's existing EVAL convention (see
     * CircuitBreaker's HALF_OPEN_ADMISSION_SCRIPT/HALF_OPEN_OUTCOME_SCRIPT):
     * a class constant holding the Lua source, invoked via
     * `$connection->eval($script, $numkeys, ...keys, ...argv)`.
     *
     * KEYS[1] = the window's fairness key.
     * ARGV[1] = window_seconds (the TTL to apply on first creation).
     *
     * Returns the post-increment count (a plain integer).
     */
    public const FAIRNESS_CHECK_SCRIPT = <<<'LUA'
local key = KEYS[1]
local windowSeconds = tonumber(ARGV[1])

local count = redis.call('INCR', key)

if count == 1 then
    redis.call('EXPIRE', key, windowSeconds)
end

return count
LUA;

    public function checkGlobal(): array
    {
        return $this->check(self::SCOPE_GLOBAL, self::GLOBAL_SCOPE_ID);
    }

    public function checkTenant(int $tenantId): array
    {
        return $this->check(self::SCOPE_TENANT, $tenantId);
    }

    public function checkTerminal(int $terminalId): array
    {
        return $this->check(self::SCOPE_TERMINAL, $terminalId);
    }

    /**
     * Evaluate (and increment) the fixed-window counter for a single scope.
     *
     * Window bucketing: `floor(now()->timestamp / window_seconds)` — a
     * fixed-window scheme (not sliding), using Carbon's `now()` (not native
     * `time()`) so the bucket honors `Carbon::setTestNow()` the same way
     * the rest of this codebase's time-sensitive services do (see
     * CircuitBreaker's use of `now()->timestamp` throughout). All requests
     * landing in the same window_seconds-wide slice of wall-clock time
     * share one counter key, which resets to zero the moment the next
     * window bucket begins.
     *
     * EXPIRE is applied only when this INCR call is the one that created
     * the key (i.e. INCR's return value is exactly 1) — every subsequent
     * call within the same window bucket only increments, it never
     * re-applies EXPIRE. Window-bucket-derived keys already self-expire
     * from the counter's perspective (a new window bucket is a brand new
     * key), so this EXPIRE call exists purely as memory hygiene: without
     * it, a window's key would otherwise live in Redis forever once
     * traffic in that exact bucket stops.
     *
     * Both steps are executed as a single FAIRNESS_CHECK_SCRIPT EVAL call
     * (review finding F1) rather than separate INCR/EXPIRE round-trips: a
     * prior revision issued them as two calls inside this same try/catch,
     * so an EXPIRE that threw after a successful INCR would fall into the
     * catch block below and discard the real, already-incremented count.
     * With one atomic EVAL call there are exactly two possible outcomes —
     * the script runs to completion (real count returned, TTL correctly
     * applied on creation) or the EVAL call itself throws and the catch
     * block's fail-open path is taken — with no remaining code path that
     * can produce a "count was really incremented but we reported
     * count=0" inconsistency.
     */
    private function check(string $scope, int $scopeId): array
    {
        $limit = $this->limitFor($scope);
        $windowSeconds = $this->windowSeconds();
        $nowTimestamp = now()->timestamp;
        $windowBucket = intdiv($nowTimestamp, $windowSeconds);
        $resetAt = Carbon::createFromTimestamp(($windowBucket + 1) * $windowSeconds);
        $retryAfterSeconds = max(1, $resetAt->timestamp - $nowTimestamp);
        $key = $this->key($scope, $scopeId, $windowBucket);

        try {
            $connection = Redis::connection($this->redisConnection());

            $count = (int) $connection->eval(
                self::FAIRNESS_CHECK_SCRIPT,
                1,
                $key,
                $windowSeconds
            );

            $allowed = $count <= $limit;

            if (! $allowed) {
                Log::warning('IngestionFairnessService: fairness limit exceeded', [
                    'scope' => $scope,
                    'scope_id' => $scopeId,
                    'count' => $count,
                    'limit' => $limit,
                    'window_seconds' => $windowSeconds,
                ]);
            }

            return $this->result($allowed, $scope, $limit, $count, $retryAfterSeconds, $resetAt);
        } catch (\Throwable $e) {
            // Fairness bookkeeping itself depends on Redis; if Redis is down
            // we cannot reliably evaluate the counter. Fail open here —
            // backpressure's fail-closed aggregate check (T034) and the
            // circuit breaker already gate the request independently of
            // fairness, so fairness failing open does not leave the system
            // unprotected. Failing closed instead would reject every
            // tenant (not just a hot one) the moment Redis is unavailable,
            // recreating the exact starvation this feature exists to
            // prevent.
            Log::error('IngestionFairnessService: failed to evaluate fairness check, failing open', [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'error' => $e->getMessage(),
            ]);

            return $this->result(true, $scope, $limit, 0, $retryAfterSeconds, $resetAt);
        }
    }

    /**
     * Redis key format: `fairness:{scope}:{scope_id}:{window}` (with the
     * configurable key_prefix defaulting to `fairness:`), where `{scope}`
     * is `global`/`tenant`/`terminal`, `{scope_id}` is the tenant/terminal
     * ID (or GLOBAL_SCOPE_ID for the global scope), and `{window}` is the
     * fixed-window bucket identifier computed above.
     */
    private function key(string $scope, int $scopeId, int $windowBucket): string
    {
        return $this->keyPrefix()."{$scope}:{$scopeId}:{$windowBucket}";
    }

    private function result(
        bool $allowed,
        string $scope,
        int $limit,
        int $count,
        int $retryAfterSeconds,
        Carbon $resetAt
    ): array {
        return [
            'allowed' => $allowed,
            'scope' => $scope,
            'limit' => $limit,
            'count' => $count,
            'retry_after_seconds' => $retryAfterSeconds,
            'reset_at' => $resetAt->toISOString(),
        ];
    }

    private function limitFor(string $scope): int
    {
        return max(0, (int) config("tsms.fairness.{$scope}.limit", 0));
    }

    private function windowSeconds(): int
    {
        return max(1, (int) config('tsms.fairness.window_seconds', 60));
    }

    private function redisConnection(): string
    {
        return (string) config('tsms.fairness.redis_connection', 'default');
    }

    private function keyPrefix(): string
    {
        return (string) config('tsms.fairness.key_prefix', 'fairness:');
    }
}
