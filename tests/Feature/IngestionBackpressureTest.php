<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransactionIntakeJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\IngestionQueueRouter;
use App\Services\PayloadChecksumService;
use App\Services\TransactionIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class IngestionBackpressureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These routes also carry 'circuit.breaker:transaction-intake'
        // (unchanged, pre-existing middleware). Since T033 made
        // CircuitBreaker Redis-backed, any request that completes with a
        // non-5xx status now transitively touches Redis via
        // CircuitBreakerMiddleware's isAvailable()/recordSuccess() calls —
        // an orthogonal concern to this file's backpressure-focused, exact
        // Redis-call-count assertions. Disable it here so those counts stay
        // scoped to IngestionBackpressureService alone; circuit breaker
        // behavior itself is covered separately in
        // tests/Feature/IngestionCircuitBreakerTest.php.
        config()->set('tsms.circuit_breaker.enabled', false);
    }

    public function test_official_endpoint_accepts_when_processing_queue_is_below_threshold_and_dispatches_to_checked_intake_shard(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $intakeQueue = app(IngestionQueueRouter::class)->intakeQueueForTenant($tenant->id);
        $this->mockRedisDepths([
            $processingQueue => 0,
            $intakeQueue => 0,
        ]);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response->assertStatus(202);
        Queue::assertPushed(ProcessTransactionIntakeJob::class, fn (ProcessTransactionIntakeJob $job) => $job->queue === $intakeQueue);
    }

    public function test_official_endpoint_returns_429_with_retry_metadata_when_backpressure_is_enforced(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);
        config()->set('tsms.intake.backpressure.retry_after_seconds', 45);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $queue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisDepth($queue, 10);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(429)
            ->assertHeader('Retry-After', '45')
            ->assertJsonPath('error_code', 'INGESTION_BACKPRESSURE')
            ->assertJsonPath('retry_after_seconds', 45)
            ->assertJsonPath('backpressure.queue', $queue)
            ->assertJsonPath('backpressure.queue_depth', 10)
            ->assertJsonPath('backpressure.threshold', 10);

        Queue::assertNothingPushed();
    }

    public function test_official_endpoint_backpressure_runs_before_form_request_database_validation(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 1);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $queue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisDepth($queue, 1);

        $payload = $this->officialPayload($tenant->id, 999999, (string) Str::uuid(), $terminal->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'INGESTION_BACKPRESSURE')
            ->assertJsonMissingValidationErrors(['terminal_id']);

        Queue::assertNothingPushed();
    }

    public function test_batch_endpoint_returns_429_when_backpressure_is_enforced(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 1);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $queue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisDepth($queue, 1);

        $response = $this->postJson('/api/v1/transactions/batch', $this->batchPayload($terminal), $this->headersFor($terminal));

        $response
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'INGESTION_BACKPRESSURE')
            ->assertJsonPath('backpressure.queue', $queue);

        Queue::assertNothingPushed();
    }

    public function test_transaction_intake_service_dispatches_to_computed_intake_shard(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $queue = app(IngestionQueueRouter::class)->intakeQueueForTenant($tenant->id);
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        // handleIntake() is called directly below, bypassing
        // IngestionBackpressureMiddleware, so no 'backpressure.processing'
        // request attribute exists to reuse — checkAggregate() must
        // evaluate the processing queue fresh too.
        $this->mockRedisDepths([$queue => 0, $processingQueue => 0]);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $request = Request::create('/api/v1/transactions/official', 'POST', $payload);
        $request->setUserResolver(fn () => $terminal);

        $checksum = Mockery::mock(PayloadChecksumService::class);
        $checksum->shouldReceive('validateSubmissionChecksums')->once()->andReturn(['valid' => true]);

        $service = new TransactionIntakeService(
            $checksum,
            app(IngestionQueueRouter::class),
            app(\App\Services\IngestionBackpressureService::class),
            app(\App\Services\SkewRankingService::class)
        );

        $result = $service->handleIntake($request);

        $this->assertTrue($result['success']);
        Queue::assertPushed(ProcessTransactionIntakeJob::class, fn (ProcessTransactionIntakeJob $job) => $job->queue === $queue);
    }

    public function test_official_endpoint_aggregate_rejection_reports_both_intake_and_processing_subdecisions_when_only_intake_is_overloaded(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 5);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $intakeQueue = app(IngestionQueueRouter::class)->intakeQueueForTenant($tenant->id);
        // Processing healthy (checked once at the route-level middleware),
        // intake overloaded (checked once fresh inside checkAggregate()).
        $this->mockRedisDepths([
            $processingQueue => 0,
            $intakeQueue => 5,
        ]);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'INGESTION_BACKPRESSURE')
            ->assertJsonPath('backpressure.reason', 'intake_queue_depth_exceeded')
            ->assertJsonPath('backpressure.intake.queue', $intakeQueue)
            ->assertJsonPath('backpressure.intake.queue_type', 'intake')
            ->assertJsonPath('backpressure.intake.overloaded', true)
            ->assertJsonPath('backpressure.intake.degraded', false)
            ->assertJsonPath('backpressure.processing.queue', $processingQueue)
            ->assertJsonPath('backpressure.processing.queue_type', 'processing')
            ->assertJsonPath('backpressure.processing.overloaded', false)
            ->assertHeader('Retry-After');

        // T035: storeOfficial() must attach the SAME retry_after_seconds
        // value already present in the JSON body as the Retry-After header
        // — previously this path (intake-aggregate overload, routed through
        // TransactionIntakeService::handleOfficialIntake() rather than
        // IngestionBackpressureMiddleware's own short-circuit) returned the
        // value in the body only, with no header at all.
        $this->assertSame(
            $response->headers->get('Retry-After'),
            (string) $response->json('retry_after_seconds')
        );

        Queue::assertNothingPushed();
    }

    public function test_official_endpoint_aggregate_rejection_reports_both_queues_overloaded(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 5);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $intakeQueue = app(IngestionQueueRouter::class)->intakeQueueForTenant($tenant->id);

        // If processing is over threshold, the route-level middleware
        // short-circuits with the flat single-queue 429 shape before the
        // controller/service aggregate check ever runs — so the nested
        // "both overloaded" aggregate shape can only be observed by calling
        // the service directly, the same way
        // test_transaction_intake_service_dispatches_to_computed_intake_shard
        // above does, bypassing the middleware entirely.
        $this->mockRedisDepths([
            $processingQueue => 5,
            $intakeQueue => 5,
        ]);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $request = Request::create('/api/v1/transactions/official', 'POST', $payload);
        $request->setUserResolver(fn () => $terminal);

        $checksum = Mockery::mock(PayloadChecksumService::class);
        $checksum->shouldReceive('validateSubmissionChecksums')->once()->andReturn(['valid' => true]);

        $service = new TransactionIntakeService(
            $checksum,
            app(IngestionQueueRouter::class),
            app(\App\Services\IngestionBackpressureService::class),
            app(\App\Services\SkewRankingService::class)
        );

        $result = $service->handleIntake($request);

        $this->assertFalse($result['success']);
        $this->assertSame(429, $result['http_status']);
        $this->assertSame('INGESTION_BACKPRESSURE', $result['error_code']);
        $this->assertSame('intake_and_processing_queue_depth_exceeded', $result['backpressure']['reason']);
        $this->assertTrue($result['backpressure']['intake']['overloaded']);
        $this->assertTrue($result['backpressure']['processing']['overloaded']);

        Queue::assertNothingPushed();
    }

    public function test_official_endpoint_aggregate_rejection_reuses_middleware_processing_check_without_a_second_redis_call(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 5);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $intakeQueue = app(IngestionQueueRouter::class)->intakeQueueForTenant($tenant->id);

        // mockRedisDepths() asserts Redis::connection() is called exactly
        // once per queue listed (Redis::shouldReceive(...)->times(2) below
        // the surface) — if checkAggregate() re-queried the processing
        // queue instead of reusing the middleware's attribute, this
        // expectation would be violated by a 3rd connection()/llen() call
        // and Mockery would fail the test.
        $this->mockRedisDepths([
            $processingQueue => 0,
            $intakeQueue => 5,
        ]);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal));

        $response
            ->assertStatus(429)
            ->assertJsonPath('backpressure.intake.overloaded', true)
            ->assertJsonPath('backpressure.processing.overloaded', false);

        Queue::assertNothingPushed();
    }

    /** WU4 (T053 remainder): rejection-reason counter for the backpressure path. */
    public function test_backpressure_rejection_increments_rejection_metric(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $queue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisDepth($queue, 10);

        $this->assertSame(0, \App\Support\Metrics::get('ingestion.rejected.backpressure', 0));

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(429);

        $this->assertSame(1, \App\Support\Metrics::get('ingestion.rejected.backpressure', 0));
    }

    /**
     * WU4 (T053 remainder): queue depth is persisted as a metric using the
     * exact depth value checkQueue() already computed for its admission
     * decision — no additional Redis call.
     */
    public function test_queue_depth_is_recorded_as_a_metric(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'observe');
        config()->set('tsms.intake.backpressure.max_queue_depth', 100);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $intakeQueue = app(IngestionQueueRouter::class)->intakeQueueForTenant($tenant->id);
        $this->mockRedisDepths([
            $processingQueue => 7,
            $intakeQueue => 3,
        ]);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(202);

        $this->assertSame(7, \App\Support\Metrics::get("ingestion.queue_depth.processing.{$processingQueue}", 0));
        $this->assertSame(3, \App\Support\Metrics::get("ingestion.queue_depth.intake.{$intakeQueue}", 0));
    }

    /**
     * WU4 (T053 remainder) queue-age feasibility outcome, tested directly
     * against IngestionBackpressureService::oldestReadyJobAge() — see that
     * method's doc-comment for the full investigation. Uses its own Redis
     * double (stubbing only lindex()) rather than mockRedisDepths(), since
     * this method never calls llen() and is not part of checkQueue()'s
     * request-time call chain.
     */
    public function test_oldest_ready_job_age_reports_a_real_age_from_horizons_pushed_at(): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(2_000_000_100));
        $pushedAt = 2_000_000_000; // 100 seconds before "now" above

        $redis = Mockery::mock();
        $redis->shouldReceive('lindex')
            ->once()
            ->with('queues:test-queue', 0)
            ->andReturn(json_encode(['pushedAt' => (string) $pushedAt, 'displayName' => 'SomeJob']));
        Redis::shouldReceive('connection')->with('default')->andReturn($redis);

        $result = app(\App\Services\IngestionBackpressureService::class)
            ->oldestReadyJobAge('test-queue', 'processing');

        $this->assertTrue($result['available']);
        $this->assertEqualsWithDelta(100.0, $result['age_seconds'], 0.01);
        $this->assertSame('ready_queue_head_pushed_at', $result['source']);
        $this->assertNull($result['reason']);

        Carbon::setTestNow(null);
    }

    public function test_oldest_ready_job_age_reports_zero_for_an_empty_queue(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('lindex')->once()->with('queues:empty-queue', 0)->andReturn(null);
        Redis::shouldReceive('connection')->with('default')->andReturn($redis);

        $result = app(\App\Services\IngestionBackpressureService::class)
            ->oldestReadyJobAge('empty-queue', 'processing');

        $this->assertTrue($result['available']);
        $this->assertSame(0.0, $result['age_seconds']);
    }

    /** A non-Horizon-decorated payload (no numeric pushedAt) is reported honestly as unavailable, never guessed. */
    public function test_oldest_ready_job_age_is_unavailable_when_pushed_at_is_missing(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('lindex')
            ->once()
            ->with('queues:legacy-queue', 0)
            ->andReturn(json_encode(['displayName' => 'SomeJob']));
        Redis::shouldReceive('connection')->with('default')->andReturn($redis);

        $result = app(\App\Services\IngestionBackpressureService::class)
            ->oldestReadyJobAge('legacy-queue', 'processing');

        $this->assertFalse($result['available']);
        $this->assertNull($result['age_seconds']);
        $this->assertSame('pushed_at_unavailable', $result['reason']);
    }

    /** Fail-safe: a Redis failure while sampling age must never throw, only report unavailable. */
    public function test_oldest_ready_job_age_is_unavailable_and_does_not_throw_when_redis_fails(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('lindex')->once()->andThrow(new \RuntimeException('Redis down'));
        Redis::shouldReceive('connection')->with('default')->andReturn($redis);

        $result = app(\App\Services\IngestionBackpressureService::class)
            ->oldestReadyJobAge('broken-queue', 'processing');

        $this->assertFalse($result['available']);
        $this->assertNull($result['age_seconds']);
        $this->assertSame('redis_unavailable', $result['reason']);
    }

    /**
     * WU4 (T053 remainder): confirms TransactionIntakeService wires the
     * bounded skew-ranking increment in at the same call site as the
     * existing unbounded `tenant.{id}.intake_count` counter, for both the
     * tenant and terminal dimensions.
     */
    public function test_accepted_official_intake_records_tenant_and_terminal_skew_ranking(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', false);
        config()->set('tsms.circuit_breaker.enabled', false);

        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        $evalCalls = [];
        $redis = Mockery::mock();
        // Also fields any fairness-check eval() calls (T045 wires
        // IngestionFairnessMiddleware onto this same route) — returning 1
        // for every call keeps every scope comfortably under its
        // configured limit, so this test only needs to isolate and assert
        // on the RANK_INCREMENT_SCRIPT calls below.
        $redis->shouldReceive('eval')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $script, int $numKeys, ...$rest) use (&$evalCalls) {
                $evalCalls[] = ['script' => $script, 'key' => $rest[0], 'argv' => array_slice($rest, $numKeys)];

                return 1;
            });
        Redis::shouldReceive('connection')->with('default')->andReturn($redis);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(202);

        $rankCalls = array_filter(
            $evalCalls,
            fn (array $call) => $call['script'] === \App\Services\SkewRankingService::RANK_INCREMENT_SCRIPT
        );
        $members = array_column(array_map(fn (array $call) => $call['argv'], $rankCalls), 0);

        $this->assertContains((string) $tenant->id, $members, 'tenant skew ranking must be recorded on accepted intake');
        $this->assertContains((string) $terminal->id, $members, 'terminal skew ranking must be recorded on accepted intake');
    }

    private function seedTenantAndTerminal(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $terminal];
    }

    private function headersFor(PosTerminal $terminal): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $terminal->generateAccessToken(),
        ];
    }

    private function mockRedisDepth(string $queue, int $depth): void
    {
        $this->mockRedisDepths([$queue => $depth]);
    }

    private function mockRedisDepths(array $depths): void
    {
        $redis = Mockery::mock();
        foreach ($depths as $queue => $depth) {
            $redis->shouldReceive('llen')->once()->with('queues:' . $queue)->andReturn($depth);
        }

        // T045 wired IngestionFairnessMiddleware onto these same routes,
        // which also calls Redis::connection('default')->eval(...) (up to
        // 3x per request: global/tenant/terminal checks) on every request
        // that reaches it. That call volume is incidental to this file's
        // backpressure-focused assertions, so it is deliberately left
        // un-asserted here rather than folded into a hand-counted total —
        // a fixed connection()->times(N) total would need re-calibrating
        // every time another Redis-backed concern is layered onto this
        // route (exactly what just broke). The llen expectations above
        // already assert precisely what this file cares about
        // (backpressure's own queue-depth reads); fairness itself still
        // genuinely runs, and a fresh/unseeded window (eval always
        // returning a count of 1, comfortably under any configured limit)
        // naturally evaluates as allowed.
        $redis->shouldReceive('eval')->zeroOrMoreTimes()->andReturn(1);

        Redis::shouldReceive('connection')->with('default')->andReturn($redis);
    }

    private function officialPayload(int $tenantId, int $terminalId, string $submissionUuid, string $hardwareId): array
    {
        $service = new PayloadChecksumService();
        $now = Carbon::now('UTC');
        $transaction = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $hardwareId,
            'receipt_no' => 'BP-' . Str::upper(Str::random(8)),
            'transaction_timestamp' => $now->copy()->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'gross_sales' => 100.0,
            'net_sales' => 100.0,
            'promo_status' => 'NONE',
            'customer_code' => 'C-TEST',
            'adjustments' => $this->adjustments(),
            'taxes' => $this->taxes(),
        ];
        $transaction['payload_checksum'] = $service->computeChecksum($transaction);

        $payload = [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => $now->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];
        $payload['payload_checksum'] = $service->computeChecksum($payload);

        return $payload;
    }

    private function batchPayload(PosTerminal $terminal): array
    {
        return [
            'batch_id' => (string) Str::uuid(),
            'customer_code' => 'C-TEST',
            'terminal_id' => $terminal->id,
            'transactions' => [
                [
                    'transaction_id' => (string) Str::uuid(),
                    'hardware_id' => $terminal->serial_number,
                    'gross_sales' => 100.0,
                    'net_sales' => 100.0,
                    'transaction_timestamp' => now()->subMinute()->toIso8601String(),
                    'payload_checksum' => hash('sha256', (string) Str::uuid()),
                    'items' => [
                        ['id' => 1, 'name' => 'Item', 'price' => 100.0, 'quantity' => 1],
                    ],
                ],
            ],
        ];
    }

    private function intakePayload(): array
    {
        return [
            'submission_uuid' => (string) Str::uuid(),
            'submission_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
            'payload_checksum' => hash('sha256', 'submission'),
            'transaction' => [
                'transaction_id' => (string) Str::uuid(),
                'receipt_no' => 'BP-' . Str::upper(Str::random(8)),
            ],
        ];
    }

    private function adjustments(): array
    {
        return [
            ['adjustment_type' => 'promo_discount', 'amount' => 0],
            ['adjustment_type' => 'senior_discount', 'amount' => 0],
            ['adjustment_type' => 'pwd_discount', 'amount' => 0],
            ['adjustment_type' => 'vip_card_discount', 'amount' => 0],
            ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0],
            ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0],
            ['adjustment_type' => 'employee_discount', 'amount' => 0],
        ];
    }

    private function taxes(): array
    {
        return [
            ['tax_type' => 'VAT', 'amount' => 0],
            ['tax_type' => 'VATABLE_SALES', 'amount' => 100],
            ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 0],
            ['tax_type' => 'OTHER_TAX', 'amount' => 0],
        ];
    }
}
