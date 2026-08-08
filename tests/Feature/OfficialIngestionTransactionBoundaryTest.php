<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransactionIntakeJob;
use App\Jobs\ProcessTransactionJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TerminalStatus;
use App\Models\TransactionIntake;
use App\Services\DeadlockRetryService;
use App\Services\IngestionBackpressureService;
use App\Services\IngestionQueueRouter;
use App\Services\PayloadChecksumService;
use App\Services\TransactionIngestService;
use App\Services\TransactionIntakeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class OfficialIngestionTransactionBoundaryTest extends TestCase
{
    public function test_official_async_intake_keeps_non_atomic_work_outside_request_scope_transaction(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        $status = TerminalStatus::firstOrCreate(['name' => 'active']);
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'status_id' => $status->id,
        ]);
        $queue = app(IngestionQueueRouter::class)->intakeQueueForTenant($tenant->id);
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisDepth($queue, $processingQueue, 5, 0);

        $observations = [];
        $durableDurationsMs = [];
        $submissionUuids = [];
        $service = new class(
            app(PayloadChecksumService::class),
            app(IngestionQueueRouter::class),
            app(IngestionBackpressureService::class),
            $observations,
            $durableDurationsMs
        ) extends TransactionIntakeService {
            public function __construct(
                PayloadChecksumService $checksumService,
                IngestionQueueRouter $queueRouter,
                IngestionBackpressureService $backpressureService,
                private array &$observations,
                private array &$durableDurationsMs
            ) {
                parent::__construct($checksumService, $queueRouter, $backpressureService);
            }

            protected function observeTransactionBoundary(string $stage): void
            {
                $this->observations[] = [
                    'stage' => $stage,
                    'level' => DB::transactionLevel(),
                    'time' => microtime(true),
                ];

                if ($stage === 'after_durable_persist') {
                    $before = collect($this->observations)
                        ->reverse()
                        ->firstWhere('stage', 'before_durable_persist');

                    if ($before) {
                        $this->durableDurationsMs[] = (microtime(true) - $before['time']) * 1000;
                    }
                }
            }
        };

        for ($i = 0; $i < 5; $i++) {
            $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
            $submissionUuids[] = $payload['submission_uuid'];
            $result = $service->handleOfficialIntake($this->requestFor($payload, $terminal));

            $this->assertTrue($result['success']);
            $this->assertSame(202, $result['http_status']);
            $this->assertSame(0, DB::transactionLevel());
        }

        $nonAtomicStages = [
            'after_structural_validation',
            'after_idempotency_lookup',
            'after_checksum_validation',
            'after_backpressure_check',
            'before_durable_persist',
            'after_durable_persist',
            'before_intake_dispatch',
            'after_intake_dispatch',
        ];

        foreach ($observations as $observation) {
            if (in_array($observation['stage'], $nonAtomicStages, true)) {
                $this->assertSame(
                    0,
                    $observation['level'],
                    "{$observation['stage']} should not run inside a request-scope DB transaction"
                );
            }
        }

        sort($durableDurationsMs);
        $p95ish = $durableDurationsMs[(int) floor((count($durableDurationsMs) - 1) * 0.95)] ?? 0;
        $this->assertLessThan(1000, $p95ish, 'Durable intake transaction boundary should stay short.');

        $this->assertSame(
            5,
            TransactionIntake::whereIn('submission_uuid', $submissionUuids)
                ->where('intake_status', TransactionIntake::INTAKE_STATUS_QUEUED)
                ->count()
        );
        Queue::assertPushed(ProcessTransactionIntakeJob::class, 5);
    }

    public function test_official_async_intake_marks_queued_before_dispatch_attempt(): void
    {
        Queue::fake();
        config()->set('tsms.intake.backpressure.enabled', true);
        config()->set('tsms.intake.backpressure.mode', 'enforce');
        config()->set('tsms.intake.backpressure.max_queue_depth', 10);

        $status = TerminalStatus::firstOrCreate(['name' => 'active']);
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'status_id' => $status->id,
        ]);
        $queue = app(IngestionQueueRouter::class)->intakeQueueForTenant($tenant->id);
        $processingQueue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        $this->mockRedisDepth($queue, $processingQueue, 1, 0);

        $statusBeforeDispatch = null;
        $service = new class(
            app(PayloadChecksumService::class),
            app(IngestionQueueRouter::class),
            app(IngestionBackpressureService::class),
            $statusBeforeDispatch
        ) extends TransactionIntakeService {
            public function __construct(
                PayloadChecksumService $checksumService,
                IngestionQueueRouter $queueRouter,
                IngestionBackpressureService $backpressureService,
                private ?string &$statusBeforeDispatch
            ) {
                parent::__construct($checksumService, $queueRouter, $backpressureService);
            }

            protected function dispatchIntakeJob(TransactionIntake $intake, string $shardQueue): void
            {
                $this->statusBeforeDispatch = TransactionIntake::whereKey($intake->id)->value('intake_status');

                parent::dispatchIntakeJob($intake, $shardQueue);
            }
        };

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $result = $service->handleOfficialIntake($this->requestFor($payload, $terminal));

        $this->assertTrue($result['success']);
        $this->assertSame('queued', $result['status']);
        $this->assertSame(TransactionIntake::INTAKE_STATUS_QUEUED, $statusBeforeDispatch);
        Queue::assertPushed(ProcessTransactionIntakeJob::class, 1);
    }

    public function test_intake_worker_enters_each_item_ingest_without_outer_batch_transaction(): void
    {
        Queue::fake();

        $status = TerminalStatus::firstOrCreate(['name' => 'active']);
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'status_id' => $status->id,
        ]);
        $submissionUuid = (string) Str::uuid();
        $firstTransactionId = (string) Str::uuid();
        $secondTransactionId = (string) Str::uuid();

        $intake = TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'payload_checksum' => str_repeat('a', 64),
            'payload' => [
                'submission_uuid' => $submissionUuid,
                'submission_timestamp' => '2026-08-07T00:00:00Z',
                'transaction_count' => 2,
                'transactions' => [
                    ['transaction_id' => $firstTransactionId],
                    ['transaction_id' => $secondTransactionId],
                ],
            ],
            'payload_size_bytes' => 256,
            'source_ip' => '127.0.0.1',
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => null,
            'trace_id' => (string) Str::uuid(),
            'received_at' => now()->subMinute(),
            'queued_at' => now()->subMinute(),
        ]);

        $levels = [];
        $ingestService = new class(app(DeadlockRetryService::class), $levels) extends TransactionIngestService {
            private int $nextId = 1000;

            public function __construct(DeadlockRetryService $retryService, private array &$levels)
            {
                parent::__construct($retryService);
            }

            public function ingest(array $payload): array
            {
                $this->levels[] = DB::transactionLevel();

                return [
                    'status' => 'accepted',
                    'id' => $this->nextId++,
                    'transaction_id' => $payload['transaction_id'],
                    'terminal_id' => $payload['terminal_id'],
                    'message' => 'created',
                ];
            }
        };

        (new ProcessTransactionIntakeJob($intake->id))->handle($ingestService);

        $this->assertSame([0, 0], $levels);
        $intake->refresh();
        $this->assertSame(TransactionIntake::PROCESSING_STATUS_PROCESSED, $intake->processing_status);
        Queue::assertPushed(ProcessTransactionJob::class, 2);
    }

    private function mockRedisDepth(string $intakeQueue, string $processingQueue, int $times, int $depth): void
    {
        // handleOfficialIntake() is invoked directly below (bypassing
        // IngestionBackpressureMiddleware), so checkAggregate() evaluates
        // both the intake queue and the processing queue fresh on every
        // call — both need mocking, not just intake.
        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->times($times)->with('queues:' . $intakeQueue)->andReturn($depth);
        $redis->shouldReceive('llen')->times($times)->with('queues:' . $processingQueue)->andReturn($depth);

        Redis::shouldReceive('connection')->times($times * 2)->with('default')->andReturn($redis);
    }

    private function requestFor(array $payload, PosTerminal $terminal): Request
    {
        $request = Request::create(
            '/api/v1/transactions/official',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CORRELATION_ID' => (string) Str::uuid()],
            json_encode($payload)
        );
        $request->setJson(new \Symfony\Component\HttpFoundation\InputBag($payload));
        $request->setUserResolver(fn () => $terminal);

        return $request;
    }

    private function officialPayload(int $tenantId, int $terminalId, string $submissionUuid, string $hardwareId): array
    {
        $service = new PayloadChecksumService();
        $now = Carbon::now('UTC');
        $transaction = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $hardwareId,
            'receipt_no' => 'BOUND-' . Str::upper(Str::random(8)),
            'transaction_timestamp' => $now->copy()->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'gross_sales' => 100.0,
            'net_sales' => 100.0,
            'promo_status' => 'NONE',
            'customer_code' => 'C-TEST',
            'adjustments' => [
                ['adjustment_type' => 'promo_discount', 'amount' => 0],
                ['adjustment_type' => 'senior_discount', 'amount' => 0],
                ['adjustment_type' => 'pwd_discount', 'amount' => 0],
                ['adjustment_type' => 'vip_card_discount', 'amount' => 0],
                ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0],
                ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0],
                ['adjustment_type' => 'employee_discount', 'amount' => 0],
            ],
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => 0],
                ['tax_type' => 'VATABLE_SALES', 'amount' => 100],
                ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 0],
                ['tax_type' => 'OTHER_TAX', 'amount' => 0],
            ],
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
}
