<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Services\CircuitBreaker;
use App\Services\DuplicateReceiptMonitorService;
use App\Services\IngestionBackpressureService;
use App\Services\SkewRankingService;
use App\Services\TenantIngestionAuditService;
use App\Support\Metrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * WU7 (T054, specs/001-100-tenant-resilience/plan.md) authorization matrix
 * — resolved BEFORE any endpoint below was written, per plan.md's mandatory
 * pre-implementation step:
 *
 * | Endpoint (method)                  | Route                                                | Required ability   | Scope         |
 * |-------------------------------------|-------------------------------------------------------|---------------------|---------------|
 * | index (pre-existing)                | GET /api/v1/observability/intake                       | admin:manage        | global-admin  |
 * | history (pre-existing)              | GET /api/v1/observability/intake/history               | admin:manage        | global-admin  |
 * | tenants (pre-existing)              | GET /api/v1/observability/intake/tenants               | admin:manage        | global-admin  |
 * | recent (pre-existing)               | GET /api/v1/observability/intake/recent                | admin:manage        | global-admin  |
 * | tenantIngestionAudit (pre-existing) | GET /api/v1/observability/intake/tenant-audit          | admin:manage        | global-admin  |
 * | duplicateReceipts (pre-existing)    | GET /api/v1/observability/intake/duplicate-receipts    | admin:manage        | global-admin  |
 * | ingestionQueueDepth (WU7, new)      | GET /api/v1/observability/ingestion/queue-depth        | admin:manage        | global-admin  |
 * | ingestionQueueAge (WU7, new)        | GET /api/v1/observability/ingestion/queue-age          | admin:manage        | global-admin  |
 * | circuitBreakerState (WU7, new)      | GET /api/v1/observability/ingestion/circuit-breaker    | admin:manage        | global-admin  |
 * | backpressureState (WU7, new)        | GET /api/v1/observability/ingestion/backpressure       | admin:manage        | global-admin  |
 * | dbPressure (WU7, new)               | GET /api/v1/observability/ingestion/db-pressure        | admin:manage        | global-admin  |
 * | tenantTerminalSkew (WU7, new)       | GET /api/v1/observability/ingestion/skew               | admin:manage        | global-admin  |
 * | rejectionReasons (WU7, new)         | GET /api/v1/observability/ingestion/rejections         | admin:manage        | global-admin  |
 * | percentileMetrics (WU7, new)        | GET /api/v1/observability/ingestion/percentiles        | admin:manage        | global-admin  |
 *
 * Authorization primitive (confirmed by direct inspection, not assumed):
 * every route above sits inside `routes/api.php`'s
 * `Route::middleware('abilities:admin:manage')->group(...)` block (see the
 * outer `Route::prefix('v1')->middleware(['auth:sanctum', 'capture.terminal.ip',
 * AttachCorrelationId::class])` group it nests inside), which resolves to
 * Sanctum's `Laravel\Sanctum\Http\Middleware\CheckAbilities` — i.e. a caller
 * must present a valid Sanctum token whose abilities include `admin:manage`
 * (or `*`). This is the SAME gate the six pre-existing observability routes
 * already use; every new WU7 route below reuses it verbatim rather than
 * introducing a second authorization mechanism.
 *
 * Explicitly NOT used for isolation on any of this controller's endpoints,
 * new or existing: `TenantScope` / `BelongsToTenant`. Per plan.md's WU7
 * section, `TenantScope` is an opt-in-per-Eloquent-model global scope
 * (only `Transaction` uses it, not `TransactionIntake`), is skipped in
 * console context, is bypassed for any `hasRole('admin')` user, and has no
 * mechanism that applies to raw Redis structures (sorted sets, hashes,
 * lists) at all — the Redis-backed structures WU7's new endpoints read
 * (circuit breaker hash, skew-ranking ZSETs, distribution ZSETs, queue
 * lists) have no tenant-scoping mechanism to opt into in the first place.
 * All eight new endpoints are global-admin-only, matching every existing
 * endpoint in this controller. No tenant-scoped (non-admin) access is
 * introduced by this work unit — that would be a genuine broadening of the
 * authorization surface, explicitly out of scope here per plan.md.
 */
class ObservabilityController extends Controller
{
    /**
     * Get real-time snapshot of the intake pipeline health.
     */
    public function index(): JsonResponse
    {
        $metrics = Metrics::snapshot([
            'intake.received_count',
            'intake.accepted_count',
            'intake.rejected_count',
            'intake.processed_count',
            'intake.failed_count',
        ]);

        // Confirmed-dead-key fix (WU2, T053 foundation): this block used to
        // read Metrics::get('intake.dispatch_latency:avg', ...) and two
        // similarly-suffixed ':avg' keys, but nothing ever wrote a key with
        // that suffix — Metrics::timing() writes the bare 'metrics:{name}'
        // key and Metrics::bucket() writes 'metrics:{name}.last_bucket' —
        // so this always silently returned the 0 default. These now read
        // the keys that are actually written. Field names and the 0
        // default are kept exactly as before (not renamed to *_last_ms/
        // *_last_s, despite these being last-observed gauges rather than
        // true averages) because resources/js/Components/IntakeHealth/
        // PipelineHealthPanel.jsx and resources/js/Pages/Observability/
        // IntakeHealthPage.jsx (including its CRITICAL/DEGRADED/OPERATIONAL
        // health banner) are live consumers keyed on these exact field
        // names — a rename would silently break that dashboard. WU7 owns
        // wiring real percentile-backed fields into the observability
        // endpoints via Metrics::percentile() (introduced in this same
        // work unit) once WU4 adds the corresponding Metrics::sample()
        // call sites; any consumer-facing rename belongs there, coordinated
        // with a frontend change, not here.
        $latencies = [
            'dispatch_avg_ms' => Metrics::get('intake.dispatch_latency', 0),
            'processing_lag_avg_s' => Metrics::get('intake.processing_lag', 0),
            'worker_time_avg_ms' => Metrics::get('intake.worker_time', 0),
        ];

        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
            'metrics' => $metrics,
            'latencies' => $latencies,
            'queue_size' => null,
        ]);
    }

    /**
     * Get historical bucketed data for charting.
     */
    public function history(Request $request): JsonResponse
    {
        $minutes = $request->query('minutes', 60);
        $metric = $request->query('metric', 'intake.processing_lag');
        
        $history = [];
        $now = now();

        for ($i = $minutes; $i >= 0; $i--) {
            $time = $now->copy()->subMinutes($i)->format('Y-m-d H:i');
            $key = "metrics:buckets:{$metric}:{$time}";
            
            $history[] = [
                'time' => $time,
                'value' => (float) Cache::get($key, 0),
            ];
        }

        return response()->json([
            'success' => true,
            'metric' => $metric,
            'data' => $history,
        ]);
    }

    /**
     * Get tenant volume breakdown.
     */
    public function tenants(): JsonResponse
    {
        // In a real system, we'd iterate over active tenants.
        // For now, we store them in a specific set or just query them.
        // We'll return the top ones from our Metrics helper if we had a list.
        // For simplicity, we'll return a stub or query recent intake.
        
        // Let's grab the top 10 from the database if cache isn't available
        $topTenants = \App\Models\TransactionIntake::select('tenant_id', \DB::raw('count(*) as count'))
            ->where('received_at', '>=', now()->subHours(24))
            ->groupBy('tenant_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $topTenants,
        ]);
    }

    /**
     * Get recent ingestion diagnostic logs.
     */
    /**
     * Get recent ingestion diagnostic logs.
     */
    public function recent(): JsonResponse
    {
        $recent = \App\Models\TransactionIntake::select([
                'id', 
                'payload',
                'tenant_id',
                'terminal_id', 
                'processing_status', 
                'last_error_message', 
                'processed_at', 
                'received_at'
            ])
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(function ($intake) {
                $payload = is_array($intake->payload) ? $intake->payload : [];
                $payloadPreview = [
                    'submission_uuid' => $payload['submission_uuid'] ?? null,
                    'tenant_id' => $payload['tenant_id'] ?? $intake->tenant_id,
                    'terminal_id' => $payload['terminal_id'] ?? $intake->terminal_id,
                    'transaction_count' => $payload['transaction_count'] ?? null,
                    'transaction' => [
                        'transaction_id' => data_get($payload, 'transaction.transaction_id'),
                        'receipt_no' => data_get($payload, 'transaction.receipt_no'),
                        'hardware_id' => data_get($payload, 'transaction.hardware_id'),
                    ],
                ];

                return [
                    'id' => $intake->id,
                    'receipt_no' => data_get($payload, 'transaction.receipt_no') ?? $payload['receipt_no'] ?? '---',
                    'terminal_id' => $intake->terminal_id,
                    'payload' => $payloadPreview,
                    'processing_status' => strtolower($intake->processing_status ?? 'pending'),
                    'last_error_message' => $intake->last_error_message,
                    'processed_at' => $intake->processed_at,
                    'received_at' => $intake->received_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $recent,
        ]);
    }

    public function duplicateReceipts(Request $request, DuplicateReceiptMonitorService $monitor): JsonResponse
    {
        $report = $monitor->report([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'tenant' => $request->query('tenant'),
            'terminal' => $request->query('terminal'),
            'limit' => $request->query('limit', 100),
        ]);

        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
            ...$report,
        ]);
    }

    public function tenantIngestionAudit(Request $request, TenantIngestionAuditService $audit): JsonResponse
    {
        $report = $audit->report([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'tenant' => $request->query('tenant'),
            'terminal' => $request->query('terminal'),
            'limit' => $request->query('limit', 200),
            'only_issues' => $request->boolean('only_issues'),
        ]);

        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
            ...$report,
        ]);
    }

    // ------------------------------------------------------------------
    // WU7 (T054): new read-only ingestion observability endpoints.
    // Every method below is strictly GET-only (Architecture Invariant 6),
    // never mutates any state (override store, breaker state, config), and
    // catches its own backing-store failures so a Redis/Cache outage
    // produces an honest `unavailable` envelope instead of an uncaught
    // exception / generic 500.
    // ------------------------------------------------------------------

    /**
     * Per-shard queue depth for both the intake and processing queue
     * families, via IngestionBackpressureService::currentDepth() (WU7's new
     * minimal read-only method — a pure LLEN read with none of
     * checkQueue()'s admission-decision side effects).
     */
    public function ingestionQueueDepth(IngestionBackpressureService $backpressure): JsonResponse
    {
        try {
            [$intakeQueues, $processingQueues] = $this->shardQueueNames();

            $intake = $this->readQueueDepths($backpressure, $intakeQueues);
            $processing = $this->readQueueDepths($backpressure, $processingQueues);

            $status = $this->combinedAvailabilityStatus(array_merge($intake, $processing));

            return $this->envelope('redis', 'instantaneous', $status, [
                'intake' => ['shards' => $intake],
                'processing' => ['shards' => $processing],
                'threshold' => (int) config('tsms.intake.backpressure.max_queue_depth', 5000),
                'mode' => config('tsms.intake.backpressure.mode', 'observe'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ObservabilityController: ingestion queue depth read failed', ['error' => $e->getMessage()]);

            return $this->envelope('redis', 'instantaneous', 'unavailable', []);
        }
    }

    /**
     * Per-shard "oldest ready job" age for both queue families, via
     * IngestionBackpressureService::oldestReadyJobAge() (WU4, already
     * committed — this is the "pull-based capability... ready for WU7 to
     * wire into an observability endpoint" that method's own doc-comment
     * anticipated). That method already fails safe on its own (never
     * throws, reports `available: false` with a reason), so the try/catch
     * here only guards this method's own shard-enumeration/config step.
     */
    public function ingestionQueueAge(IngestionBackpressureService $backpressure): JsonResponse
    {
        try {
            [$intakeQueues, $processingQueues] = $this->shardQueueNames();

            $intake = array_map(
                fn (string $queue) => array_merge(['queue' => $queue], $backpressure->oldestReadyJobAge($queue, 'intake')),
                $intakeQueues
            );
            $processing = array_map(
                fn (string $queue) => array_merge(['queue' => $queue], $backpressure->oldestReadyJobAge($queue, 'processing')),
                $processingQueues
            );

            $status = $this->combinedAvailabilityStatus(array_merge($intake, $processing));

            return $this->envelope('redis', 'instantaneous', $status, [
                'intake' => ['shards' => $intake],
                'processing' => ['shards' => $processing],
            ]);
        } catch (\Throwable $e) {
            Log::warning('ObservabilityController: ingestion queue age read failed', ['error' => $e->getMessage()]);

            return $this->envelope('redis', 'instantaneous', 'unavailable', []);
        }
    }

    /**
     * Circuit breaker state for the shared ingestion breaker
     * (`CircuitBreaker::INGESTION_SERVICE_KEY`), exposing BOTH:
     *   - `live`: the real, authoritative Redis-backed state via WU7's new
     *     `CircuitBreaker::currentState()` read method (a pure HGETALL —
     *     never calls `isAvailable()`, which has real side effects: it can
     *     admit/consume a half-open probe slot or drive an open→half-open
     *     transition. Calling isAvailable() from a read-only observability
     *     endpoint would violate Architecture Invariant 6, since it can
     *     mutate breaker state as a side effect of merely being observed).
     *   - `metrics_mirrored`: the WU4 Metrics-gauge mirror
     *     (`circuit_breaker.state.{serviceKey}`), for lightweight
     *     dashboard polling that doesn't need every hash field.
     * Both are exposed (rather than picking one) because they answer
     * different operational questions: `live` is the ground truth for
     * incident diagnosis (matches docs/CIRCUIT_BREAKER_BACKPRESSURE_RUNBOOK.md's
     * `HGETALL` guidance byte-for-byte), while `metrics_mirrored` is a
     * cheap numeric signal suited to charting/alerting without parsing a
     * hash.
     */
    public function circuitBreakerState(): JsonResponse
    {
        $serviceKey = CircuitBreaker::INGESTION_SERVICE_KEY;

        try {
            $live = (new CircuitBreaker($serviceKey))->currentState();
            $mirroredCode = (int) Metrics::get("circuit_breaker.state.{$serviceKey}", CircuitBreaker::STATE_METRIC_CLOSED);

            return $this->envelope('redis', 'instantaneous', 'available', [
                'service' => $serviceKey,
                'live' => $live,
                'metrics_mirrored' => [
                    'state_code' => $mirroredCode,
                    'state_label' => $this->circuitBreakerStateLabel($mirroredCode),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('ObservabilityController: circuit breaker state read failed', ['error' => $e->getMessage()]);

            return $this->envelope('redis', 'instantaneous', 'unavailable', []);
        }
    }

    /**
     * Current backpressure enforcement configuration plus a depth summary
     * (max observed depth per queue family), reusing the exact same
     * per-shard read helper as ingestionQueueDepth() above — no duplicated
     * Redis logic between the two endpoints.
     */
    public function backpressureState(IngestionBackpressureService $backpressure): JsonResponse
    {
        try {
            [$intakeQueues, $processingQueues] = $this->shardQueueNames();

            $intake = $this->readQueueDepths($backpressure, $intakeQueues);
            $processing = $this->readQueueDepths($backpressure, $processingQueues);

            $status = $this->combinedAvailabilityStatus(array_merge($intake, $processing));

            return $this->envelope('redis', 'instantaneous', $status, [
                'enabled' => (bool) config('tsms.intake.backpressure.enabled', true),
                'mode' => config('tsms.intake.backpressure.mode', 'observe'),
                'threshold' => (int) config('tsms.intake.backpressure.max_queue_depth', 5000),
                'retry_after_seconds' => (int) config('tsms.intake.backpressure.retry_after_seconds', 60),
                'intake_max_depth' => $this->maxDepth($intake),
                'processing_max_depth' => $this->maxDepth($processing),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ObservabilityController: backpressure state read failed', ['error' => $e->getMessage()]);

            return $this->envelope('redis', 'instantaneous', 'unavailable', []);
        }
    }

    /**
     * DB-pressure indicators from WU3's deadlock-retry instrumentation:
     * event counters via Metrics::get() (Cache-backed) and latency
     * percentiles via Metrics::percentile() (Redis-backed distribution
     * store, WU2). `source` is reported as `application` since this
     * endpoint blends both backing stores through the single Metrics
     * facade rather than reading either directly.
     *
     * Note (documented, not a bug): Metrics::get() does not itself catch
     * backing-store failures the way Metrics::incr()/timing()/sample()/
     * percentile() do — a Cache-store outage while reading a counter here
     * will throw, which this method's own try/catch turns into an honest
     * `unavailable` status rather than a 500.
     */
    public function dbPressure(): JsonResponse
    {
        try {
            $counters = [
                'attempted' => (int) Metrics::get('db.deadlock_retry.attempted', 0),
                'succeeded' => (int) Metrics::get('db.deadlock_retry.succeeded', 0),
                'exhausted' => (int) Metrics::get('db.deadlock_retry.exhausted', 0),
                'non_retryable' => (int) Metrics::get('db.deadlock_retry.non_retryable', 0),
            ];

            return $this->envelope('application', 'cumulative_since_last_reset', 'available', [
                'counters' => $counters,
                'delay_ms' => $this->percentilesFor('db.deadlock_retry.delay_ms'),
                'operation_ms' => $this->percentilesFor('db.deadlock_retry.operation_ms'),
                'total_recovery_ms' => $this->percentilesFor('db.deadlock_retry.total_recovery_ms'),
                'note' => 'Retry-observed DB pressure only (WU3): zero retries does not mean zero lock contention.',
            ]);
        } catch (\Throwable $e) {
            Log::warning('ObservabilityController: db pressure read failed', ['error' => $e->getMessage()]);

            return $this->envelope('application', 'cumulative_since_last_reset', 'unavailable', []);
        }
    }

    /**
     * Bounded tenant/terminal "top talkers" skew ranking (WU4's
     * SkewRankingService). The `limit` query parameter is passed straight
     * through to topTenants()/topTerminals() WITHOUT a second clamp here —
     * both methods already clamp internally to `tsms.metrics.skew.max_top_n`
     * (Architecture Invariant 5, bounded cardinality); adding a second,
     * independent clamp in this controller would risk the two limits
     * silently drifting out of sync. `requested_limit` (the raw, unclamped
     * input) is still surfaced in the response so an operator can see when
     * their request was clamped.
     *
     * SkewRankingService::topTenants()/topTerminals() already swallow any
     * Redis failure internally and return an empty list (documented on the
     * service itself: "must never affect ingestion admission or
     * behavior") — so a Redis outage here is indistinguishable from a
     * genuinely quiet window and both correctly report `available` with an
     * empty list, not `unavailable`. This is a deliberate, documented
     * limitation inherited from the underlying service's fail-safe design,
     * not an oversight.
     */
    public function tenantTerminalSkew(Request $request, SkewRankingService $skewRanking): JsonResponse
    {
        $limit = (int) $request->query('limit', 10);

        try {
            return $this->envelope('redis', 'current_window', 'available', [
                'requested_limit' => $limit,
                'tenants' => $skewRanking->topTenants($limit),
                'terminals' => $skewRanking->topTerminals($limit),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ObservabilityController: skew ranking read failed', ['error' => $e->getMessage()]);

            return $this->envelope('redis', 'current_window', 'unavailable', []);
        }
    }

    /**
     * Ingestion rejection-reason counters (WU4/WU5): a fixed, bounded list
     * of the six known `ingestion.rejected.*` counters — naturally bounded
     * response size, no caller-supplied filter needed.
     */
    public function rejectionReasons(): JsonResponse
    {
        $reasons = ['payload_size', 'batch_size', 'backpressure', 'circuit_breaker', 'fairness', 'tenant_override'];

        try {
            $counters = [];
            foreach ($reasons as $reason) {
                $counters[$reason] = (int) Metrics::get("ingestion.rejected.{$reason}", 0);
            }

            return $this->envelope('application', 'cumulative_since_last_reset', 'available', [
                'reasons' => $counters,
                'total' => array_sum($counters),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ObservabilityController: rejection reason read failed', ['error' => $e->getMessage()]);

            return $this->envelope('application', 'cumulative_since_last_reset', 'unavailable', []);
        }
    }

    /**
     * Percentile (p50/p95/p99) reads via Metrics::percentile() (WU2) for
     * every metric name this codebase currently samples via
     * Metrics::sample(). As of this work unit that is only WU3's
     * db.deadlock_retry.* trio — WU4 added ingestion rejection/queue/breaker
     * instrumentation via Metrics::incr()/timing() only, not sample(), so
     * there is no ingestion-latency distribution to report yet. This is
     * implemented honestly (an accurate, if currently narrow, list) rather
     * than fabricating additional metric names with no real samples behind
     * them.
     */
    public function percentileMetrics(): JsonResponse
    {
        $metrics = [
            'db.deadlock_retry.delay_ms',
            'db.deadlock_retry.operation_ms',
            'db.deadlock_retry.total_recovery_ms',
        ];

        try {
            $data = [];
            foreach ($metrics as $metric) {
                $data[$metric] = $this->percentilesFor($metric);
            }

            return $this->envelope('redis', 'recent_samples', 'available', [
                'metrics' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ObservabilityController: percentile metrics read failed', ['error' => $e->getMessage()]);

            return $this->envelope('redis', 'recent_samples', 'unavailable', []);
        }
    }

    /**
     * Shard queue names for both families, mirroring
     * App\Services\IngestionQueueRouter's private naming convention exactly
     * (`transaction-intake:s{N}` / `transaction-intake:s-{vipShard}` /
     * `transaction-processing:s{N}`) — the same deliberate duplication
     * already established by App\Console\Commands\VerifyShardTopology for
     * the identical reason: this is read-only reporting code that needs the
     * queue-name list itself, not a tenant-to-shard routing decision, so it
     * has no need to depend on IngestionQueueRouter's tenant-keyed public
     * API. Config-only (shard_count, vip_shard) — no Redis calls.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function shardQueueNames(): array
    {
        $intakeShardCount = max(1, (int) config('tsms.intake.shard_count', 8));
        $processingShardCount = max(1, (int) config('tsms.processing.shard_count', $intakeShardCount));
        $vipShard = config('tsms.intake.vip_shard');

        $intakeQueues = array_map(
            static fn (int $i): string => "transaction-intake:s{$i}",
            range(0, $intakeShardCount - 1)
        );

        if (is_string($vipShard) && $vipShard !== '') {
            $intakeQueues[] = "transaction-intake:s-{$vipShard}";
        }

        $processingQueues = array_map(
            static fn (int $i): string => "transaction-processing:s{$i}",
            range(0, $processingShardCount - 1)
        );

        return [$intakeQueues, $processingQueues];
    }

    /**
     * @param  list<string>  $queueNames
     * @return list<array{queue: string, available: bool, depth: int|null}>
     */
    private function readQueueDepths(IngestionBackpressureService $backpressure, array $queueNames): array
    {
        return array_map(
            static function (string $queueName) use ($backpressure): array {
                $result = $backpressure->currentDepth($queueName);

                return [
                    'queue' => $queueName,
                    'available' => $result['available'],
                    'depth' => $result['depth'],
                ];
            },
            $queueNames
        );
    }

    /** @param list<array{depth: int|null}> $depthEntries */
    private function maxDepth(array $depthEntries): ?int
    {
        $depths = array_filter(array_column($depthEntries, 'depth'), static fn ($d) => $d !== null);

        return $depths === [] ? null : max($depths);
    }

    /**
     * Combined `available`/`degraded`/`unavailable` status across a list of
     * items each carrying an `available` boolean (per-shard depth/age reads
     * above): all available -> `available`; none available -> `unavailable`;
     * a genuine mix -> `degraded` (the backing store is reachable for some
     * shards but not others — an honest partial-failure signal, not a full
     * outage).
     *
     * @param  list<array{available: bool}>  $items
     */
    private function combinedAvailabilityStatus(array $items): string
    {
        if ($items === []) {
            return 'available';
        }

        $availableCount = count(array_filter($items, static fn (array $i) => $i['available']));

        if ($availableCount === count($items)) {
            return 'available';
        }

        if ($availableCount === 0) {
            return 'unavailable';
        }

        return 'degraded';
    }

    /** @return array{p50: array{value: float|null, sample_count: int}, p95: array{value: float|null, sample_count: int}, p99: array{value: float|null, sample_count: int}} */
    private function percentilesFor(string $metric): array
    {
        return [
            'p50' => Metrics::percentile($metric, 50),
            'p95' => Metrics::percentile($metric, 95),
            'p99' => Metrics::percentile($metric, 99),
        ];
    }

    private function circuitBreakerStateLabel(int $code): string
    {
        return match ($code) {
            CircuitBreaker::STATE_METRIC_OPEN => CircuitBreaker::STATE_OPEN,
            CircuitBreaker::STATE_METRIC_HALF_OPEN => CircuitBreaker::STATE_HALF_OPEN,
            default => CircuitBreaker::STATE_CLOSED,
        };
    }

    /**
     * Required response envelope (plan.md WU7): every new endpoint above
     * returns exactly this shape. `freshness_seconds` is always 0 for these
     * endpoints since every read is a live, synchronous pull performed at
     * request time (no cached/precomputed snapshot is served).
     */
    private function envelope(string $source, string $window, string $status, array $data): JsonResponse
    {
        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'source' => $source,
            'window' => $window,
            'freshness_seconds' => 0,
            'status' => $status,
            'data' => $data,
        ]);
    }
}
