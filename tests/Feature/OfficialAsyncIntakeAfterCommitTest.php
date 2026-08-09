<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransactionIntakeJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TerminalStatus;
use App\Models\TransactionIntake;
use App\Services\IngestionQueueRouter;
use App\Services\PayloadChecksumService;
use App\Services\TransactionIntakeService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class OfficialAsyncIntakeAfterCommitTest extends TestCase
{
    use DatabaseTruncation;

    public function test_intake_job_is_not_enqueued_until_outer_transaction_commits(): void
    {
        config()->set('queue.default', 'database');
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
        $this->mockRedisDepths([$queue => 0, $processingQueue => 0]);

        $payload = $this->officialPayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
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

        DB::beginTransaction();

        try {
            $result = app(TransactionIntakeService::class)->handleOfficialIntake($request);

            $this->assertTrue($result['success']);
            $this->assertSame(202, $result['http_status']);
            $this->assertSame(0, DB::table('jobs')->count());

            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $e;
        }

        $this->assertSame(1, DB::table('jobs')->where('queue', $queue)->count());
        $this->assertDatabaseHas('transaction_intake', [
            'submission_uuid' => $payload['submission_uuid'],
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
        ]);
    }

    private function mockRedisDepths(array $depths): void
    {
        // handleOfficialIntake() is invoked directly below (bypassing
        // IngestionBackpressureMiddleware), so checkAggregate() evaluates
        // both the intake and processing queues fresh — both need mocking.
        $redis = Mockery::mock();
        foreach ($depths as $queue => $depth) {
            $redis->shouldReceive('llen')->once()->with('queues:' . $queue)->andReturn($depth);
        }

        // WU4 (T053 remainder): the accepted intake below also records
        // tenant+terminal skew ranking (SkewRankingService), each its own
        // Redis::connection('default')->eval(...) call — +2 connection()
        // resolutions beyond the count($depths) llen-driven ones above.
        Redis::shouldReceive('connection')->times(count($depths) + 2)->with('default')->andReturn($redis);
    }

    private function officialPayload(int $tenantId, int $terminalId, string $submissionUuid, string $hardwareId): array
    {
        $service = new PayloadChecksumService();
        $now = Carbon::now('UTC');
        $transaction = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $hardwareId,
            'receipt_no' => 'ACMT-' . Str::upper(Str::random(8)),
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
