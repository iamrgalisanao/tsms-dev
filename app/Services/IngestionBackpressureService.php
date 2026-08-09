<?php

namespace App\Services;

use App\Support\Metrics;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class IngestionBackpressureService
{
    public function __construct(private readonly IngestionQueueRouter $queueRouter)
    {
    }

    public function checkIntake(int|string|null $tenantId): array
    {
        return $this->checkQueue($this->queueRouter->intakeQueueForTenant($tenantId), 'intake');
    }

    public function checkProcessing(int|string|null $tenantId): array
    {
        return $this->checkQueue($this->queueRouter->processingQueueForTenant($tenantId), 'processing');
    }

    public function checkAggregate(int|string|null $tenantId, ?array $processing = null): array
    {
        $intake = $this->checkIntake($tenantId);
        $processingResult = $this->isValidSubDecision($processing) ? $processing : $this->checkProcessing($tenantId);

        return [
            'enforced' => $intake['enforced'] || $processingResult['enforced'],
            'degraded' => ($intake['enforced'] && $intake['degraded']) || ($processingResult['enforced'] && $processingResult['degraded']),
            'overloaded' => ($intake['enforced'] && $intake['overloaded']) || ($processingResult['enforced'] && $processingResult['overloaded']),
            'intake' => $intake,
            'processing' => $processingResult,
        ];
    }

    private function isValidSubDecision(?array $result): bool
    {
        return $result !== null && isset($result['enforced'], $result['degraded'], $result['overloaded'], $result['queue_type']);
    }

    public function checkQueue(string $queueName, string $queueType = 'intake'): array
    {
        if (!$this->enabled()) {
            return $this->result(false, false, false, $queueName, $queueType, null, $this->threshold(), 'disabled');
        }

        try {
            $depth = $this->queueDepth($queueName);
            $overloaded = $depth >= $this->threshold();
            $enforced = $overloaded && $this->enforced();

            // WU4 (T053 remainder): persist the queue depth this decision
            // already computed above — no new Redis call, just a gauge
            // write of the value already in hand. Keyed by queue_type+name
            // rather than tenant/terminal (bounded cardinality: queue names
            // are per-shard, capped by tsms.intake.shard_count /
            // tsms.processing.shard_count, not per-tenant).
            Metrics::timing("ingestion.queue_depth.{$queueType}.{$queueName}", $depth);

            if ($overloaded) {
                Log::warning('Ingestion backpressure threshold reached', [
                    'queue' => $queueName,
                    'queue_type' => $queueType,
                    'depth' => $depth,
                    'threshold' => $this->threshold(),
                    'mode' => $this->mode(),
                    'enforced' => $enforced,
                ]);
            }

            if ($enforced) {
                // WU4 (T053 remainder): rejection-reason counter. Covers
                // both the middleware's single-queue rejection and
                // TransactionIntakeService's aggregate (intake+processing)
                // rejection, since both ultimately go through this method
                // with no duplicated Redis/queue-depth work.
                Metrics::incr('ingestion.rejected.backpressure');
            }

            return $this->result($overloaded, false, $enforced, $queueName, $queueType, $depth, $this->threshold(), $this->mode());
        } catch (\Throwable $e) {
            $failClosed = $this->enforced(); // true only when mode === 'enforce'

            Log::error('Failed to evaluate ingestion backpressure', [
                'queue' => $queueName,
                'queue_type' => $queueType,
                'mode' => $this->mode(),
                'fail_closed' => $failClosed,
                'error' => $e->getMessage(),
            ]);

            if ($failClosed) {
                // Same rejection reason as the overloaded branch above: from
                // the caller's perspective this is still a backpressure
                // rejection, just triggered by an inability to evaluate the
                // queue (Redis failure) rather than a confirmed depth
                // breach.
                Metrics::incr('ingestion.rejected.backpressure');
            }

            return $this->result(false, true, $failClosed, $queueName, $queueType, null, $this->threshold(), $this->mode());
        }
    }

    public function degradedStatus(): int
    {
        return 503; // contract pins this to 503, unlike the configurable rejectionStatus()
    }

    public function degradedPayload(array $result, ?string $correlationId = null): array
    {
        return [
            'success' => false,
            'error_code' => 'INGESTION_DEGRADED',
            'message' => 'Ingestion health could not be evaluated. Retry later.',
            'retry_after_seconds' => $this->retryAfterSeconds(),
            'retry_after' => now()->addSeconds($this->retryAfterSeconds())->toISOString(),
            'correlation_id' => $correlationId,
            'reason' => $this->degradedReason($result),
        ];
    }

    public function rejectionPayload(array $result, ?string $correlationId = null): array
    {
        return [
            'success' => false,
            'message' => 'Ingestion temporarily throttled due to queue backpressure. Retry later.',
            'error_code' => 'INGESTION_BACKPRESSURE',
            'retry_after_seconds' => $this->retryAfterSeconds(),
            'retry_after' => now()->addSeconds($this->retryAfterSeconds())->toISOString(),
            'correlation_id' => $correlationId,
            'backpressure' => $this->isAggregateResult($result)
                ? $this->aggregateBackpressureContext($result)
                : $this->singleBackpressureContext($result),
        ];
    }

    public function rejectionStatus(): int
    {
        return (int) config('tsms.intake.backpressure.reject_status', 429);
    }

    private function isAggregateResult(array $result): bool
    {
        return isset($result['intake'], $result['processing']);
    }

    private function singleBackpressureContext(array $result): array
    {
        return [
            'queue' => $result['queue'],
            'queue_type' => $result['queue_type'],
            'queue_depth' => $result['queue_depth'],
            'threshold' => $result['threshold'],
            'mode' => $result['mode'],
            'reason' => $result['queue_type'] . '_queue_depth_exceeded',
        ];
    }

    private function aggregateBackpressureContext(array $result): array
    {
        return [
            'intake' => $this->subDecisionContext($result['intake']),
            'processing' => $this->subDecisionContext($result['processing']),
            'reason' => $this->aggregateReason($result, 'overloaded', 'queue_depth_exceeded'),
        ];
    }

    private function subDecisionContext(array $sub): array
    {
        return [
            'queue' => $sub['queue'],
            'queue_type' => $sub['queue_type'],
            'queue_depth' => $sub['queue_depth'],
            'threshold' => $sub['threshold'],
            'mode' => $sub['mode'],
            'overloaded' => $sub['overloaded'],
            'degraded' => $sub['degraded'],
        ];
    }

    private function degradedReason(array $result): string
    {
        if ($this->isAggregateResult($result)) {
            return $this->aggregateReason($result, 'degraded', 'health_check_failed');
        }

        return $result['queue_type'] . '_health_check_failed';
    }

    private function aggregateReason(array $result, string $flag, string $suffix): string
    {
        $intakeFlag = $result['intake']['enforced'] && $result['intake'][$flag];
        $processingFlag = $result['processing']['enforced'] && $result['processing'][$flag];

        if ($intakeFlag && $processingFlag) {
            return "intake_and_processing_{$suffix}";
        }

        return $intakeFlag ? "intake_{$suffix}" : "processing_{$suffix}";
    }

    private function queueDepth(string $queueName): int
    {
        $redisConnection = config('queue.connections.redis.connection', 'default');

        return (int) Redis::connection($redisConnection)->llen('queues:' . $queueName);
    }

    /**
     * WU4 (T053 remainder) queue-age feasibility outcome — a real,
     * Horizon-sourced "oldest ready-queue job age", not a proxy or an
     * `available: false` stub. Deliberately NOT called from checkQueue()
     * above: it issues its own extra Redis round trip (LINDEX), and
     * checkQueue() is on the hot ingestion request path with test coverage
     * (IngestionBackpressureTest, IngestionBackpressureFailureModeTest,
     * OfficialAsyncIntake*Test, OfficialIngestionTransactionBoundaryTest)
     * that asserts exact llen() call counts against a strict Mockery
     * double — adding a second Redis command there would either silently
     * change per-request Redis load for every ingestion request or force
     * unrelated call-count assertions to be rewritten across seven test
     * files for an observability nicety that does not need per-request
     * freshness. This method is a pull-based capability instead (the same
     * shape WU2 already established for Metrics::sample()/percentile(): a
     * real, tested capability with no live caller yet, ready for WU7 to
     * wire into an observability endpoint on its own poll cadence).
     *
     * Feasibility investigation (per plan.md's "confirm a stable timestamp
     * source exists ... before promising 'oldest pending job age'"):
     *
     * - This app runs Laravel Horizon (composer.json: laravel/horizon) on
     *   the 'redis' queue connection. Horizon's own queue connector,
     *   Laravel\Horizon\RedisQueue, overrides push()/createPayloadArray()
     *   to run every job payload through Laravel\Horizon\JobPayload::
     *   prepare(), which unconditionally stamps a 'pushedAt' => microtime()
     *   field onto the JSON payload before it is RPUSH'd onto
     *   `queues:{name}` (vendor/laravel/horizon/src/JobPayload.php:104,
     *   RedisQueue.php:69/108). This is a real, always-present timestamp on
     *   every job this pipeline enqueues — not inferred, not a guess.
     * - Laravel's queue Lua scripts (vendor/laravel/framework/src/
     *   Illuminate/Queue/LuaScripts.php) push via RPUSH and pop via LPOP,
     *   so the *ready* list is genuinely FIFO: index 0 (LINDEX ... 0) is
     *   always the oldest not-yet-picked-up job, exactly the same list
     *   checkQueue()'s own depth check already reads via LLEN.
     * - Scope: READY jobs only, deliberately not "all pending" (ready +
     *   delayed + reserved), because:
     *     - `queues:{name}:reserved` is a ZSET scored by lease-expiry time,
     *       not enqueue time, and holds jobs already claimed by an active
     *       worker (in-flight, not backlog) — not a queue-pressure signal.
     *     - `queues:{name}:delayed` is a ZSET scored by "available-at" time,
     *       also not enqueue time; and this pipeline's own
     *       ProcessTransactionIntakeJob never dispatches with a delay (no
     *       ->delay()/backoff()/release() call sites — confirmed by
     *       inspection), so the delayed set is empty for this pipeline in
     *       practice.
     *     - The ready list is exactly the structure checkQueue()'s own
     *       admission decision already keys off, so pairing depth+age from
     *       the same list keeps both signals internally consistent.
     *   This is why this method's name and 'source' value say "ready" —
     *   never "pending" — matching plan.md's instruction not to claim more
     *   precision than the data actually supports.
     *
     * @return array{available: bool, age_seconds: float|null, source: string, reason: string|null}
     */
    public function oldestReadyJobAge(string $queueName, string $queueType = 'processing'): array
    {
        try {
            $redisConnection = config('queue.connections.redis.connection', 'default');
            $raw = Redis::connection($redisConnection)->lindex('queues:'.$queueName, 0);

            if (! is_string($raw) || $raw === '') {
                return $this->queueAgeResult(true, 0.0, null); // empty queue: age is trivially zero, not unavailable
            }

            $decoded = json_decode($raw, true);
            $pushedAt = is_array($decoded) ? ($decoded['pushedAt'] ?? null) : null;

            if (! is_numeric($pushedAt)) {
                // Horizon did not decorate this payload (non-Horizon queue
                // driver, or a job enqueued before this instrumentation
                // existed) — cannot honestly report an age for it.
                return $this->queueAgeResult(false, null, 'pushed_at_unavailable');
            }

            // now()->getPreciseTimestamp(3) (Carbon, testable via
            // Carbon::setTestNow()) rather than a raw microtime(true) call,
            // so this is honestly testable without freezing real wall-clock
            // time. In production (no test-now override) this resolves to
            // the same real wall-clock instant microtime(true) would.
            $nowSeconds = now()->getPreciseTimestamp(3) / 1000;
            $ageSeconds = max(0.0, $nowSeconds - (float) $pushedAt);

            Metrics::timing("ingestion.queue_oldest_ready_age_seconds.{$queueType}.{$queueName}", $ageSeconds);

            return $this->queueAgeResult(true, $ageSeconds, null);
        } catch (\Throwable $e) {
            Log::warning('IngestionBackpressureService: failed to sample oldest ready-job age', [
                'queue' => $queueName,
                'queue_type' => $queueType,
                'error' => $e->getMessage(),
            ]);

            return $this->queueAgeResult(false, null, 'redis_unavailable');
        }
    }

    /** @return array{available: bool, age_seconds: float|null, source: string, reason: string|null} */
    private function queueAgeResult(bool $available, ?float $ageSeconds, ?string $reason): array
    {
        return [
            'available' => $available,
            'age_seconds' => $ageSeconds,
            'source' => 'ready_queue_head_pushed_at',
            'reason' => $reason,
        ];
    }

    private function enabled(): bool
    {
        return (bool) config('tsms.intake.backpressure.enabled', true);
    }

    private function enforced(): bool
    {
        return $this->mode() === 'enforce';
    }

    private function mode(): string
    {
        return config('tsms.intake.backpressure.mode', 'observe') === 'enforce' ? 'enforce' : 'observe';
    }

    private function threshold(): int
    {
        return (int) config('tsms.intake.backpressure.max_queue_depth', 5000);
    }

    private function retryAfterSeconds(): int
    {
        return max(1, (int) config('tsms.intake.backpressure.retry_after_seconds', 60));
    }

    private function result(
        bool $overloaded,
        bool $degraded,
        bool $enforced,
        string $queueName,
        string $queueType,
        ?int $queueDepth,
        int $threshold,
        string $mode
    ): array {
        return [
            'overloaded' => $overloaded,
            'degraded' => $degraded,
            'enforced' => $enforced,
            'queue' => $queueName,
            'queue_type' => $queueType,
            'queue_depth' => $queueDepth,
            'threshold' => $threshold,
            'mode' => $mode,
        ];
    }
}
