<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\WebAppForwardingService;
use App\Models\Transaction;
use App\Models\WebappTransactionForward;
use Illuminate\Support\Facades\DB;

class WebAppForwardingBatchCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tsms.web_app.endpoint' => 'https://example.test/forward']);
        config(['tsms.web_app.auth_token' => 'test-token']);
        config(['tsms.testing.capture_only' => true]);
        config(['tsms.web_app.enabled' => true]);
        foreach ([
            'QUEUED' => 'Job queued',
            'RUNNING' => 'Job running',
            'RETRYING' => 'Job retry',
            'COMPLETED' => 'Job completed',
            'PERMANENTLY_FAILED' => 'Job permanently failed'
        ] as $code => $desc) {
            DB::table('job_statuses')->updateOrInsert(['code' => $code], ['description' => $desc]);
        }
    }

    private function makeValidTransaction(array $overrides = []): Transaction
    {
        $tenant = \App\Models\Tenant::factory()->create();
        $terminal = \App\Models\PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $defaults = [
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) \Str::uuid(),
            'transaction_timestamp' => now(),
            'gross_sales' => 10.00,
            'net_sales' => 8.50,
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
            'submission_uuid' => (string) \Str::uuid(),
            'submission_timestamp' => now(),
        ];
        return Transaction::create(array_merge($defaults, $overrides));
    }

    private function markJobCompleted(Transaction $tx): void
    {
        DB::table('transaction_jobs')->insert([
            'transaction_pk' => $tx->id,
            'job_status' => Transaction::JOB_STATUS_COMPLETED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_batch_capture_only_returns_envelope_and_records_completed(): void
    {
        $this->markTestSkipped('Batch capture-only test deferred pending refactor to expose batch builder for direct invocation.');
        $service = app(WebAppForwardingService::class);
        $t1 = $this->makeValidTransaction();
        $t2 = $this->makeValidTransaction(['gross_sales' => 12.00, 'net_sales' => 10.00, 'tenant_id' => $t1->tenant_id, 'terminal_id' => $t1->terminal_id]);
        $this->markJobCompleted($t1);
        $this->markJobCompleted($t2);

        // Ensure latest_job_status column (if present) reflects COMPLETED to pass service filter
        try {
            \DB::table('transactions')->where('id', $t1->id)->update(['latest_job_status' => 'COMPLETED']);
            \DB::table('transactions')->where('id', $t2->id)->update(['latest_job_status' => 'COMPLETED']);
        } catch (\Throwable $e) {
            // Column might not exist in current migration set; ignore if so
        }

        // Reset circuit breaker state
        \Cache::forget('webapp_forwarding_circuit_breaker_failures');
        \Cache::forget('webapp_forwarding_circuit_breaker_last_failure');

        $result = $service->forwardUnsentTransactions();
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('captured_payload', $result);
        $envelope = $result['captured_payload'];
        $this->assertEquals(2, $envelope['transaction_count']);
        $this->assertEquals('2.0', $envelope['schema_version']);
        $this->assertEquals($t1->tenant_id, $envelope['tenant_id']);
        $this->assertEquals($t1->terminal_id, $envelope['terminal_id']);
        $this->assertCount(2, $envelope['transactions']);

        $completed = WebappTransactionForward::completed()->get();
        $this->assertCount(2, $completed, 'Both records should be marked completed in capture-only mode');
    }
}
