<?php

namespace App\Services;

use App\Support\Metrics;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half-open';

    /**
     * WU4 (T053 remainder) numeric encoding mirrored into Metrics at every
     * real state transition below, so current breaker state is readable
     * via Metrics::get("circuit_breaker.state.{$serviceKey}") without
     * parsing logs. Instrumentation only — does not feed back into any
     * state-machine decision. Absence of a written value (a breaker that
     * has never transitioned) is read as STATE_METRIC_CLOSED by
     * Metrics::get()'s own default-value convention, matching this class's
     * own readState() default of STATE_CLOSED for a fresh/never-seen key.
     */
    public const STATE_METRIC_CLOSED = 0;

    public const STATE_METRIC_HALF_OPEN = 1;

    public const STATE_METRIC_OPEN = 2;

    /** Shared key used by the official/batch ingestion breaker (route param 'transaction-intake'). */
    public const INGESTION_SERVICE_KEY = 'transaction-intake';

    protected string $serviceKey;
    protected string $redisConnection;
    protected string $key;
    protected int $failureThreshold;
    protected int $resetTimeoutSeconds;
    protected int $stateTtlSeconds;
    protected bool $enabled;

    /** Max concurrent probes admitted per half-open generation (ADR T028a). */
    protected const HALF_OPEN_MAX_PROBES = 3;

    /** Number of successes (of the up to N probes) required to close from half-open (ADR T028a). */
    protected const HALF_OPEN_CLOSE_THRESHOLD = 2;

    /** Number of failures (of the up to N probes) required to reopen from half-open (ADR T028a). */
    protected const HALF_OPEN_REOPEN_THRESHOLD = 2;

    /**
     * Atomically admits (or rejects) a half-open probe. Folds together, in
     * a single Redis Lua script execution, everything that
     * isAvailable()'s half-open branch previously did as separate
     * non-atomic steps (re-read state/counters, detect an expired
     * unresolved episode and reopen, HINCRBY the probe-count cap, and
     * self-correct on overflow) so that no other process can transition
     * the breaker (e.g. via applyHalfOpenOutcome() below, or a concurrent
     * admission) between the check and the mutation (review finding F2).
     * A MULTI/EXEC transaction cannot do this correctly on its own: EXEC
     * cannot conditionally branch on a value read moments before entering
     * the transaction without WATCH, and a WATCH/retry loop only pushes
     * the same race into its own retry-handling surface. A single Lua
     * script is atomic end-to-end and is exactly what Redis provides this
     * class of operation for.
     *
     * KEYS[1] = the breaker's hash key.
     * ARGV[1] = now (unix timestamp).
     * ARGV[2] = resetTimeoutSeconds.
     * ARGV[3] = HALF_OPEN_MAX_PROBES.
     * ARGV[4] = HALF_OPEN_CLOSE_THRESHOLD.
     * ARGV[5] = HALF_OPEN_REOPEN_THRESHOLD.
     * ARGV[6] = stateTtlSeconds.
     *
     * Returns one of:
     *   {"not-half-open"}      state moved on since the caller's outer
     *                          readState() call; deny.
     *   {"expired"}            the episode was unresolved past
     *                          resetTimeoutSeconds; reopened, deny.
     *   {"rejected", generation} over the N=cap probes; self-corrected, deny.
     *   {"admitted", generation} probe slot consumed against `generation`.
     */
    public const HALF_OPEN_ADMISSION_SCRIPT = <<<'LUA'
local key = KEYS[1]
local now = tonumber(ARGV[1])
local resetTimeoutSeconds = tonumber(ARGV[2])
local maxProbes = tonumber(ARGV[3])
local closeThreshold = tonumber(ARGV[4])
local reopenThreshold = tonumber(ARGV[5])
local ttl = tonumber(ARGV[6])

local state = redis.call('HGET', key, 'state')
if state ~= 'half-open' then
    return {'not-half-open'}
end

local successes = tonumber(redis.call('HGET', key, 'half_open_successes') or '0')
local failures = tonumber(redis.call('HGET', key, 'half_open_failures') or '0')
local startedAt = tonumber(redis.call('HGET', key, 'half_open_started_at') or '0')
local resolved = (successes >= closeThreshold) or (failures >= reopenThreshold)

if (not resolved) and startedAt > 0 and (now - startedAt) >= resetTimeoutSeconds then
    redis.call('HSET', key, 'state', 'open')
    redis.call('HSET', key, 'opened_at', now)
    redis.call('EXPIRE', key, ttl)
    return {'expired'}
end

local admitted = redis.call('HINCRBY', key, 'half_open_probe_count', 1)
local generation = tonumber(redis.call('HGET', key, 'half_open_generation') or '0')

if admitted > maxProbes then
    redis.call('HINCRBY', key, 'half_open_probe_count', -1)
    return {'rejected', generation}
end

return {'admitted', generation}
LUA;

    /**
     * Atomically evaluates a half-open success/failure outcome against the
     * generation it was admitted under, and — if (and only if) that
     * generation is still the breaker's current half-open generation —
     * performs the counter increment plus any terminal transition (close
     * on HALF_OPEN_CLOSE_THRESHOLD successes, reopen on
     * HALF_OPEN_REOPEN_THRESHOLD failures). Folding the generation check,
     * counter mutation, threshold evaluation and terminal transition into
     * one Lua script execution is what makes stale/superseded outcomes a
     * true no-op (review finding F1): if the check fails, the script
     * performs zero writes. Because every invocation (whether it wins the
     * race or arrives after another invocation already transitioned the
     * state) evaluates against the live state inside its own atomic
     * execution, two near-simultaneous outcomes for the same generation
     * cannot corrupt each other or double-transition — the second one to
     * execute simply observes the state the first one already committed.
     *
     * KEYS[1] = the breaker's hash key.
     * ARGV[1] = generation this outcome belongs to.
     * ARGV[2] = outcome: "success" or "failure".
     * ARGV[3] = now (unix timestamp).
     * ARGV[4] = HALF_OPEN_CLOSE_THRESHOLD.
     * ARGV[5] = HALF_OPEN_REOPEN_THRESHOLD.
     * ARGV[6] = stateTtlSeconds.
     *
     * Returns one of:
     *   {0, currentState, currentGeneration}  discarded, zero mutation.
     *   {1, count, transitioned}              applied; transitioned is
     *                                         0 (none), 1 (closed) or
     *                                         2 (reopened).
     */
    public const HALF_OPEN_OUTCOME_SCRIPT = <<<'LUA'
local key = KEYS[1]
local generation = tonumber(ARGV[1])
local outcome = ARGV[2]
local now = ARGV[3]
local closeThreshold = tonumber(ARGV[4])
local reopenThreshold = tonumber(ARGV[5])
local ttl = tonumber(ARGV[6])

local state = redis.call('HGET', key, 'state')
local currentGeneration = tonumber(redis.call('HGET', key, 'half_open_generation') or '0')

if state ~= 'half-open' or currentGeneration ~= generation then
    return {0, state or '', currentGeneration}
end

local field = 'half_open_successes'
if outcome == 'failure' then
    field = 'half_open_failures'
end

local count = redis.call('HINCRBY', key, field, 1)
local transitioned = 0

if outcome == 'failure' then
    redis.call('HSET', key, 'last_failure_at', now)
end

if outcome == 'success' and count >= closeThreshold then
    redis.call('HSET', key, 'state', 'closed')
    redis.call('HSET', key, 'failure_count', 0)
    redis.call('HSET', key, 'opened_at', 0)
    redis.call('HSET', key, 'last_success_at', now)
    redis.call('EXPIRE', key, ttl)
    transitioned = 1
elseif outcome == 'failure' and count >= reopenThreshold then
    redis.call('HSET', key, 'state', 'open')
    redis.call('HSET', key, 'opened_at', now)
    redis.call('EXPIRE', key, ttl)
    transitioned = 2
end

return {1, count, transitioned}
LUA;

    /**
     * Atomically performs the open→half-open transition, guarded by a
     * compare-and-set read of `state`/`opened_at` inside the script itself
     * (not a pre-check in PHP). Before this script existed, isAvailable()'s
     * open-state branch drove this transition as three separate steps — a
     * plain HGETALL, a standalone atomic HINCRBY of half_open_generation,
     * then a separate MULTI/EXEC that unconditionally wrote state/counters
     * — with no guard preventing two concurrent callers (both having
     * observed state===open and expired) from each running the sequence.
     * Whichever caller's MULTI/EXEC landed second would overwrite the
     * first caller's already-admitted probe slot and zero the counters,
     * silently invalidating a legitimately admitted probe (review finding
     * F-NEW-1 — the same class of bug F1/F2 eliminated at the admission/
     * outcome call sites, recurring here instead).
     *
     * Because Redis serializes EVAL execution, only the first caller whose
     * script invocation actually runs while `state` is still `open` and
     * `resetTimeoutSeconds` has elapsed performs the real initialization:
     * it bumps `half_open_generation`, resets all half-open counters and
     * `failure_count`, sets `state`/`half_open_started_at`, and admits
     * itself as the generation's first probe (`half_open_probe_count`
     * ends at 1, not 0) — all inside this single atomic execution. Any
     * other concurrent caller whose script invocation runs after the
     * first one's has already landed observes `state` no longer `open`
     * (or not yet expired) and performs zero mutation, returning a
     * sentinel telling the PHP caller to fall through to the existing,
     * already-correct HALF_OPEN_ADMISSION_SCRIPT path instead — so it is
     * admitted as a normal probe against the now-current generation
     * rather than re-initializing and clobbering it. No admitted probe is
     * ever invalidated by a competing transition, and no caller is ever
     * silently dropped.
     *
     * KEYS[1] = the breaker's hash key.
     * ARGV[1] = now (unix timestamp).
     * ARGV[2] = resetTimeoutSeconds.
     * ARGV[3] = stateTtlSeconds.
     *
     * Returns one of:
     *   {"not-open", state}       state was not `open`, or `open` but not
     *                             yet expired, by the time this script
     *                             actually executed; caller must fall
     *                             through to HALF_OPEN_ADMISSION_SCRIPT
     *                             (if now half-open) or re-evaluate.
     *   {"initialized", generation} this caller performed the transition
     *                             and is admitted as probe 1 of the new
     *                             generation.
     */
    public const HALF_OPEN_TRANSITION_SCRIPT = <<<'LUA'
local key = KEYS[1]
local now = tonumber(ARGV[1])
local resetTimeoutSeconds = tonumber(ARGV[2])
local ttl = tonumber(ARGV[3])

local state = redis.call('HGET', key, 'state')
local openedAt = tonumber(redis.call('HGET', key, 'opened_at') or '0')

if state ~= 'open' or openedAt <= 0 or (now - openedAt) < resetTimeoutSeconds then
    return {'not-open', state or ''}
end

local generation = redis.call('HINCRBY', key, 'half_open_generation', 1)

redis.call('HSET', key, 'state', 'half-open')
redis.call('HSET', key, 'half_open_started_at', now)
redis.call('HSET', key, 'half_open_probe_count', 1)
redis.call('HSET', key, 'half_open_successes', 0)
redis.call('HSET', key, 'half_open_failures', 0)
redis.call('HSET', key, 'failure_count', 0)
redis.call('EXPIRE', key, ttl)

return {'initialized', generation}
LUA;

    /**
     * The half_open_generation admitted by the most recent isAvailable()
     * call, if that call admitted this request as a half-open probe. Null
     * when the most recent call was not a half-open admission (closed
     * state, rejected, or disabled). Callers (e.g.
     * CircuitBreakerMiddleware) read this immediately after a truthy
     * isAvailable() result to thread the generation through to the
     * eventual recordSuccess()/recordFailure() call.
     */
    protected ?int $lastAdmittedGeneration = null;

    /**
     * The Retry-After hint (whole seconds) computed by the most recent
     * isAvailable() call that returned false. Null when the most recent
     * call returned true, or hasn't run yet — retryAfterSeconds() falls
     * back to resetTimeoutSeconds in that case (see its doc-comment).
     * Populated by isAvailable()/admitHalfOpenProbe() (T028b): this is
     * purely informational (what to tell the client), it never feeds back
     * into any state-transition decision, so it deliberately carries no
     * atomicity requirement the way half_open_generation does.
     */
    protected ?int $lastRetryAfterSeconds = null;

    public function __construct(string $serviceKey)
    {
        $this->serviceKey = $serviceKey;
        $this->redisConnection = (string) config('tsms.circuit_breaker.redis_connection', 'default');
        $this->key = config('tsms.circuit_breaker.key_prefix', 'tsms:circuit-breaker:') . $serviceKey;
        $this->failureThreshold = max(1, (int) config('tsms.circuit_breaker.failure_threshold', 5));
        $this->resetTimeoutSeconds = max(1, (int) config('tsms.circuit_breaker.reset_timeout_seconds', 60));
        $this->stateTtlSeconds = max($this->resetTimeoutSeconds, (int) config('tsms.circuit_breaker.state_ttl_seconds', 3600));
        $this->enabled = (bool) config('tsms.circuit_breaker.enabled', true);
    }

    public function isAvailable(): bool
    {
        $this->lastAdmittedGeneration = null;
        $this->lastRetryAfterSeconds = null;

        if (!$this->enabled) {
            return true;
        }

        try {
            $state = $this->readState();

            if ($state['state'] === self::STATE_OPEN) {
                if ($state['opened_at'] > 0 && (now()->timestamp - $state['opened_at']) >= $this->resetTimeoutSeconds) {
                    // Entering half-open admits this very request as the
                    // generation's first probe (slot 1 of N) — but only if
                    // this caller actually wins the atomic transition (see
                    // HALF_OPEN_TRANSITION_SCRIPT's doc-comment, F-NEW-1).
                    $generation = $this->transitionToHalfOpen();

                    if ($generation !== null) {
                        $this->lastAdmittedGeneration = $generation;
                        return true;
                    }

                    // Lost the race: another concurrent caller already
                    // performed the transition between our outer
                    // readState() read and this atomic script actually
                    // executing. Fall through to the existing, already
                    // -correct half-open admission path so this request is
                    // still admitted (or capped/rejected) as a normal probe
                    // against the now-current generation, instead of being
                    // silently dropped.
                    return $this->admitHalfOpenProbe();
                }

                // Still open, not yet past resetTimeoutSeconds: tell the
                // caller (T028b) how many seconds remain until this circuit
                // is expected to allow a retry, derived from this breaker's
                // own opened_at/resetTimeoutSeconds rather than a fixed
                // number. opened_at may be <= 0 in a degenerate/edge state
                // (e.g. manually seeded); computeRetryAfterSeconds()'s
                // max(1, ...) floor keeps that from ever surfacing a
                // negative or zero value.
                $this->lastRetryAfterSeconds = $this->computeRetryAfterSeconds($state['opened_at']);
                return false;
            }

            if ($state['state'] === self::STATE_HALF_OPEN) {
                return $this->admitHalfOpenProbe($state['half_open_started_at']);
            }

            return true; // closed
        } catch (\Throwable $e) {
            // Breaker bookkeeping itself depends on Redis; if Redis is down we
            // cannot reliably evaluate breaker state. Fail open here — a
            // Redis outage is independently handled by
            // IngestionBackpressureService's fail-closed path (T034) in
            // enforce mode, so traffic doesn't pass through unchecked.
            Log::error('CircuitBreaker: failed to read state, failing open', [
                'service' => $this->serviceKey,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * The half_open_generation admitted by the most recent isAvailable()
     * call, if any. See the property doc-comment for details.
     */
    public function currentHalfOpenGeneration(): ?int
    {
        return $this->lastAdmittedGeneration;
    }

    /**
     * The Retry-After hint (whole seconds, always a non-negative integer
     * >= 1) for the most recent isAvailable() call, whether or not that
     * call actually returned false. Callers (e.g. CircuitBreakerMiddleware,
     * T028b) read this immediately after a falsy isAvailable() result —
     * mirroring how currentHalfOpenGeneration() is read after a truthy
     * one — to attach an identical value to both the rejection response's
     * JSON body and its `Retry-After` header, the same single-computation
     * pattern IngestionBackpressureService already established for the
     * unrelated queue-backpressure rejection path.
     *
     * Falls back to resetTimeoutSeconds when isAvailable() didn't populate
     * a more specific value (i.e. the request was admitted, or hit one of
     * the rare half-open race outcomes ('expired' having just reopened the
     * circuit, or 'not-half-open' having raced a concurrent transition) —
     * in both of those cases the circuit is effectively freshly (re)opened,
     * so a full resetTimeoutSeconds wait is the correct, state-consistent
     * hint anyway.
     */
    public function retryAfterSeconds(): int
    {
        return $this->lastRetryAfterSeconds ?? $this->resetTimeoutSeconds;
    }

    /**
     * Computes a Retry-After hint as "seconds remaining until
     * resetTimeoutSeconds has elapsed since $referenceTimestamp", clamped
     * to a minimum of 1 second — mirroring
     * IngestionBackpressureService::retryAfterSeconds()'s max(1, ...)
     * floor convention exactly, so both rejection paths in this codebase
     * agree on what "a valid Retry-After value" means. The floor also
     * absorbs any input where $referenceTimestamp is stale, zero, or
     * otherwise makes the naive subtraction go negative (e.g. a
     * degenerate opened_at <= 0), so this never surfaces a negative value
     * or 0 to a client.
     */
    protected function computeRetryAfterSeconds(int $referenceTimestamp): int
    {
        $elapsed = now()->timestamp - $referenceTimestamp;

        return max(1, $this->resetTimeoutSeconds - $elapsed);
    }

    /**
     * @param int|null $generation The half-open probe generation this
     *   outcome belongs to, as returned by currentHalfOpenGeneration()
     *   immediately after the admitting isAvailable() call. Null (default)
     *   preserves the exact pre-existing closed-state behavior with no
     *   generation check at all — required for backward compatibility with
     *   call sites that never went through a half-open admission.
     */
    public function recordSuccess(?int $generation = null): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            if ($generation === null) {
                $state = $this->readState();
                if ($state['state'] !== self::STATE_CLOSED) {
                    $this->reset();
                }
                return;
            }

            $this->applyHalfOpenOutcome($generation, 'success');
        } catch (\Throwable $e) {
            Log::error('CircuitBreaker: failed to record success', [
                'service' => $this->serviceKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param int|null $generation See recordSuccess()'s doc-comment; the
     *   same generation-safety contract applies here.
     */
    public function recordFailure(?int $generation = null): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            if ($generation === null) {
                $connection = Redis::connection($this->redisConnection);

                // hincrby must happen first (and alone) so we know the resulting
                // failure count before deciding whether to open the breaker.
                $failureCount = (int) $connection->hincrby($this->key, 'failure_count', 1);
                $opening = $failureCount >= $this->failureThreshold;
                $now = now()->timestamp;

                // Write the rest of this update inside a genuine MULTI/EXEC
                // transaction (Predis's Client::transaction(), not pipeline() —
                // pipeline() only batches round-trips for performance and gives
                // no all-or-nothing guarantee). If 'state' and 'opened_at' were
                // written non-atomically, a crash between them could leave
                // state=open with a stale/zero opened_at — and
                // isAvailable()'s half-open transition requires opened_at > 0,
                // so the breaker could get stuck open until the whole key's
                // TTL expires.
                $connection->transaction(function ($tx) use ($now, $opening) {
                    $tx->hset($this->key, 'last_failure_at', $now);
                    if ($opening) {
                        $tx->hset($this->key, 'state', self::STATE_OPEN);
                        $tx->hset($this->key, 'opened_at', $now);
                    }
                    $tx->expire($this->key, $this->stateTtlSeconds);
                });

                if ($opening) {
                    Log::warning('CircuitBreaker: opened', [
                        'service' => $this->serviceKey,
                        'failure_count' => $failureCount,
                        'failure_threshold' => $this->failureThreshold,
                    ]);
                    $this->mirrorStateMetric(self::STATE_METRIC_OPEN);
                }
                return;
            }

            $this->applyHalfOpenOutcome($generation, 'failure');
        } catch (\Throwable $e) {
            Log::error('CircuitBreaker: failed to record failure', [
                'service' => $this->serviceKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function reset(): void
    {
        try {
            $connection = Redis::connection($this->redisConnection);
            $now = now()->timestamp;

            // Genuine MULTI/EXEC transaction — see the comment in
            // recordFailure() for why pipeline() was insufficient here.
            $connection->transaction(function ($tx) use ($now) {
                $tx->hset($this->key, 'state', self::STATE_CLOSED);
                $tx->hset($this->key, 'failure_count', 0);
                $tx->hset($this->key, 'opened_at', 0);
                $tx->hset($this->key, 'last_success_at', $now);
                $tx->expire($this->key, $this->stateTtlSeconds);
            });

            $this->mirrorStateMetric(self::STATE_METRIC_CLOSED);
        } catch (\Throwable $e) {
            Log::error('CircuitBreaker: failed to reset', [
                'service' => $this->serviceKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Attempt to atomically enter a fresh half-open generation via
     * HALF_OPEN_TRANSITION_SCRIPT (see its doc-comment for the full
     * rationale, Lua source, and how F-NEW-1 is resolved). Returns the new
     * generation number if this caller won the race and performed the
     * initialization (admitted as the generation's first probe, slot 1).
     * Returns null if this caller lost the race — another concurrent
     * caller's invocation of the same script already performed the
     * transition first; the caller (isAvailable()) must then fall through
     * to admitHalfOpenProbe() to be admitted against the now-current
     * generation instead. Exceptions are intentionally left to bubble up
     * to isAvailable()'s fail-open catch rather than being swallowed here.
     */
    protected function transitionToHalfOpen(): ?int
    {
        $connection = Redis::connection($this->redisConnection);

        $result = $connection->eval(
            self::HALF_OPEN_TRANSITION_SCRIPT,
            1,
            $this->key,
            now()->timestamp,
            $this->resetTimeoutSeconds,
            $this->stateTtlSeconds
        );

        $outcome = (string) ($result[0] ?? 'not-open');

        if ($outcome !== 'initialized') {
            // Another concurrent caller already transitioned (or the state
            // moved on again) between isAvailable()'s outer readState()
            // call and this atomic script actually executing.
            return null;
        }

        $generation = (int) $result[1];

        Log::info('CircuitBreaker: transitioned to half-open', [
            'service' => $this->serviceKey,
            'generation' => $generation,
        ]);
        $this->mirrorStateMetric(self::STATE_METRIC_HALF_OPEN);

        return $generation;
    }

    /**
     * Atomically admit or reject a half-open probe via
     * HALF_OPEN_ADMISSION_SCRIPT (see its doc-comment for the full
     * rationale and Lua source). Also handles driving an unresolved,
     * expired half-open episode back toward 'open' with a fresh
     * opened_at, per ADR T028a — this does not touch half_open_generation
     * directly, the next transitionToHalfOpen() call (once
     * resetTimeoutSeconds elapses again) bumps it, giving the next
     * episode its own new generation.
     *
     * @param int|null $knownHalfOpenStartedAt The caller's own already-read
     *   half_open_started_at (from isAvailable()'s outer readState() call),
     *   if available, so the 'rejected' (over-cap) branch below can compute
     *   a T028b Retry-After hint without an extra Redis round trip. Null
     *   when the caller (isAvailable()'s open-branch race-loss fallback)
     *   has no such value on hand — that branch falls back to a lazy HGET
     *   instead, only in the rare case it's actually needed.
     */
    protected function admitHalfOpenProbe(?int $knownHalfOpenStartedAt = null): bool
    {
        $connection = Redis::connection($this->redisConnection);

        $result = $connection->eval(
            self::HALF_OPEN_ADMISSION_SCRIPT,
            1,
            $this->key,
            now()->timestamp,
            $this->resetTimeoutSeconds,
            self::HALF_OPEN_MAX_PROBES,
            self::HALF_OPEN_CLOSE_THRESHOLD,
            self::HALF_OPEN_REOPEN_THRESHOLD,
            $this->stateTtlSeconds
        );

        $outcome = (string) ($result[0] ?? 'not-half-open');

        if ($outcome === 'admitted') {
            $this->lastAdmittedGeneration = (int) $result[1];
            return true;
        }

        if ($outcome === 'expired') {
            Log::warning('CircuitBreaker: half-open episode expired without resolution, reopening', [
                'service' => $this->serviceKey,
            ]);
            return false;
        }

        if ($outcome === 'rejected') {
            // Over the N=HALF_OPEN_MAX_PROBES cap; the script already
            // self-corrected its own increment. Deny without admitting.
            //
            // T028b: the client should retry soon, not wait a full fresh
            // resetTimeoutSeconds — a half-open episode resolves via up to
            // HALF_OPEN_MAX_PROBES in-flight outcomes (2-of-3), not a slow
            // failure count building back up. The state-derived value that
            // best reflects "how soon" without any new, unrelated hardcoded
            // number is how much of this episode's own resetTimeoutSeconds
            // budget (since half_open_started_at) remains before it times
            // out and forces a reopen — the same clamped
            // computeRetryAfterSeconds() used for the OPEN case above, just
            // anchored to half_open_started_at instead of opened_at.
            $startedAt = $knownHalfOpenStartedAt
                ?? (int) ($connection->hget($this->key, 'half_open_started_at') ?? 0);
            $this->lastRetryAfterSeconds = $this->computeRetryAfterSeconds($startedAt);
            return false;
        }

        // 'not-half-open': state moved on (already closed/reopened/new
        // generation) between isAvailable()'s outer readState() call and
        // this atomic script actually executing. Deny this request; the
        // next isAvailable() call will re-evaluate the now-current state.
        return false;
    }

    /**
     * Atomically evaluate and apply a half-open recordSuccess()/
     * recordFailure() outcome via HALF_OPEN_OUTCOME_SCRIPT (see its
     * doc-comment for the full rationale and Lua source).
     */
    protected function applyHalfOpenOutcome(int $generation, string $outcome): void
    {
        $connection = Redis::connection($this->redisConnection);

        $result = $connection->eval(
            self::HALF_OPEN_OUTCOME_SCRIPT,
            1,
            $this->key,
            $generation,
            $outcome,
            now()->timestamp,
            self::HALF_OPEN_CLOSE_THRESHOLD,
            self::HALF_OPEN_REOPEN_THRESHOLD,
            $this->stateTtlSeconds
        );

        $applied = (int) ($result[0] ?? 0);

        if (!$applied) {
            Log::debug("CircuitBreaker: discarding stale half-open {$outcome} outcome", [
                'service' => $this->serviceKey,
                'generation' => $generation,
                'current_state' => $result[1] ?? null,
                'current_generation' => $result[2] ?? null,
            ]);
            return;
        }

        $transitioned = (int) ($result[2] ?? 0);

        if ($transitioned === 1) {
            Log::info('CircuitBreaker: closed after half-open probe successes', [
                'service' => $this->serviceKey,
                'generation' => $generation,
            ]);
            $this->mirrorStateMetric(self::STATE_METRIC_CLOSED);
        } elseif ($transitioned === 2) {
            Log::warning('CircuitBreaker: reopened after half-open probe failures', [
                'service' => $this->serviceKey,
                'generation' => $generation,
            ]);
            $this->mirrorStateMetric(self::STATE_METRIC_OPEN);
        }
    }

    /**
     * WU4 (T053 remainder): mirror a real state transition into
     * Metrics::timing() so it is readable via
     * Metrics::get("circuit_breaker.state.{$serviceKey}") without parsing
     * logs. Metrics::timing() already swallows its own failures
     * (App\Support\MetricStores\CacheMetricStore), so this can never throw
     * back into the state-machine methods above.
     */
    private function mirrorStateMetric(int $stateValue): void
    {
        Metrics::timing("circuit_breaker.state.{$this->serviceKey}", $stateValue);
    }

    /**
     * WU7 (T054) read-only exposure of readState() for the observability
     * endpoint. Pure passthrough — does not alter the state machine in any
     * way, and carries none of isAvailable()'s side effects (no half-open
     * admission, no probe consumption, no transition). Safe to call at any
     * time purely for inspection; unlike isAvailable(), this method does
     * NOT fail open on a Redis error — it lets the exception propagate so
     * the caller (ObservabilityController) can report an honest
     * `unavailable` status instead of a fabricated closed/open state.
     *
     * @return array{state: string, failure_count: int, opened_at: int, half_open_generation: int, half_open_started_at: int, half_open_probe_count: int, half_open_successes: int, half_open_failures: int}
     */
    public function currentState(): array
    {
        return $this->readState();
    }

    protected function readState(): array
    {
        $data = Redis::connection($this->redisConnection)->hgetall($this->key);

        return [
            'state' => $data['state'] ?? self::STATE_CLOSED,
            'failure_count' => (int) ($data['failure_count'] ?? 0),
            'opened_at' => (int) ($data['opened_at'] ?? 0),
            'half_open_generation' => (int) ($data['half_open_generation'] ?? 0),
            'half_open_started_at' => (int) ($data['half_open_started_at'] ?? 0),
            'half_open_probe_count' => (int) ($data['half_open_probe_count'] ?? 0),
            'half_open_successes' => (int) ($data['half_open_successes'] ?? 0),
            'half_open_failures' => (int) ($data['half_open_failures'] ?? 0),
        ];
    }
}
