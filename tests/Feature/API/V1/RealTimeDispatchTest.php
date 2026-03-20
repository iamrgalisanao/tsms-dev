<?php

namespace Tests\Feature\API\V1;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Company;
use App\Jobs\ProcessTransactionJob;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class RealTimeDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $terminal;
    protected $token;
    protected $checksumService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->checksumService = new PayloadChecksumService();

        $company = Company::factory()->create(['customer_code' => 'TKN' . Str::random(5)]);
        $this->tenant = Tenant::factory()->create([
            'company_id' => $company->id,
        ]);
        
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Create a Sanctum token for the terminal
        $this->token = $this->terminal->createToken('test-token', ['transaction:create'])->plainTextToken;
    }

    /** @test */
    public function test_it_dispatches_process_transaction_job_immediately_on_official_submission()
    {
        Queue::fake();

        $submissionUuid = (string) Str::uuid();
        $transactionId = (string) Str::uuid();
        
        // Construct transaction payload
        $transaction = [
            'transaction_id' => $transactionId,
            'hardware_id' => 'HW-' . Str::random(5), // REQUIRED FIELD PREVIOUSLY MISSING
            'transaction_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
            'gross_sales' => 100.00,
            'net_sales' => 89.29,
            'promo_status' => 'NONE',
            'customer_code' => $this->tenant->company->customer_code,
            'adjustments' => array_map(fn($type) => ['adjustment_type' => $type, 'amount' => 0], [
                'promo_discount', 'senior_discount', 'pwd_discount', 
                'other_discount', 'service_charge', 'sc_vat_exempt_sales', 'pwd_vat_exempt_sales'
            ]),
            'taxes' => array_map(fn($type) => ['tax_type' => $type, 'amount' => 0], [
                'vatable_sales', 'vat_amount', 'vat_exempt_sales', 'vat_zero_rated_sales'
            ]),
        ];

        // Add transaction checksum
        $transaction['payload_checksum'] = $this->checksumService->computeChecksum($transaction);

        // Construct full submission payload
        $payload = [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'submission_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];

        // Add submission checksum
        $payload['payload_checksum'] = $this->checksumService->computeChecksum($payload);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/transactions/official', $payload);

        $response->assertStatus(200);

        // Verify transaction was created
        $tx = Transaction::where('transaction_id', $transactionId)->first();
        $this->assertNotNull($tx);

        // Verify job was pushed to the correct shard
        $shard = (int) ($this->tenant->id % 8);
        Queue::assertPushed(ProcessTransactionJob::class, function ($job) use ($tx) {
            return $job->getTransactionId() === $tx->id;
        });
        
        Queue::assertPushedOn('transaction-processing:s' . $shard, ProcessTransactionJob::class);
    }
}
