<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Jobs\ProcessTransactionJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TransactionProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $terminal;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        // Create or get existing tenant
        $this->tenant = Tenant::firstOrCreate(
            ['code' => 'SAMPLE001'],
            [
                'name' => 'Sample Tenant',
                'status' => 'active'
            ]
        );

        // Create test terminal with required fields
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'terminal_uid' => 'TEST-' . time()
        ]);
        
        // Authenticate using JWT
        $this->token = auth('pos_api')->login($this->terminal);
    }

    protected function makeAuthenticatedRequest($method, $uri, $data = [], $headers = [])
    {
        return $this->withHeaders(array_merge([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token
        ], $headers))->json($method, $uri, $data);
    }

    public function test_transaction_submission_and_queuing()
    {
        $data = [
            'tenant_id' => strval($this->tenant->id), // Explicitly cast to string
            'terminal_id' => $this->terminal->terminal_uid,
            'hardware_id' => 'HW-001',
            'transaction_id' => 'TXN-' . time(),
            'transaction_timestamp' => now()->toDateTimeString(),
            'transaction_date' => now()->toDateString(),
            'amount' => 1000.00,
            'type' => 'PAYMENT',
            'gross_sales' => 1000.00,
            'net_sales' => 950.00,
            'vatable_sales' => 850.00,
            'vat_exempt_sales' => 100.00,
            'vat_amount' => 102.00,
            'transaction_count' => 1,
            'payload_checksum' => md5('test-payload'),
            'status' => 'PENDING',
            'validation_status' => 'PENDING',
            'machine_number' => 1
        ];

        $response = $this->makeAuthenticatedRequest('POST', '/api/v1/transactions', $data);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'data' => [
                         'validation_status' => 'PENDING',
                     ],
                 ]);

        Queue::assertPushed(ProcessTransactionJob::class);
    }

    public function test_status_tracking()
    {
        $transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/transactions/{$transaction->id}/status");

        $response->assertOk()
                 ->assertJson([
                     'success' => true,
                     'data' => [
                         'transaction_id' => $transaction->id,
                         'status' => null,
                         'completed_at' => null,
                         'attempts' => null,
                         'error' => null
                     ]
                 ]);
    }

    public function test_handles_validation_errors()
    {
        $data = [
            'terminal_id' => 'INVALID-ID',
            'amount' => -100,
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/transactions', $data);

        $response->assertStatus(422)
                 ->assertJsonStructure([
                     'message',
                     'errors',
                 ]);
    }

    public function test_processes_text_format_input()
    {
        $input = "TENANT_ID: " . strval($this->tenant->id) . "\n"  // Explicitly cast to string
               . "TERMINAL_ID: {$this->terminal->terminal_uid}\n"
               . "HARDWARE_ID: HW-001\n"
               . "MACHINE_NUMBER: 1\n"
               . "TRANSACTION_ID: TXN-" . time() . "\n"
               . "TRANSACTION_DATE: " . now()->toDateString() . "\n"
               . "AMOUNT: 1000.00\n"
               . "TYPE: PAYMENT\n"
               . "TRANSACTION_TIMESTAMP: " . now()->toDateTimeString() . "\n"
               . "GROSS_SALES: 1000.00\n"
               . "NET_SALES: 950.00\n"
               . "VATABLE_SALES: 850.00\n"
               . "VAT_AMOUNT: 102.00\n"
               . "VAT_EXEMPT_SALES: 100.00\n"
               . "TRANSACTION_COUNT: 1\n"
               . "PAYLOAD_CHECKSUM: " . md5('test-payload') . "\n"
               . "STATUS: PENDING\n"
               . "VALIDATION_STATUS: PENDING";

        $response = $this->call(
            'POST',
            '/api/v1/transactions',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'text/plain',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            ],
            $input
        );

        $response->assertOk()
                 ->assertJson(['success' => true]);

        Queue::assertPushed(ProcessTransactionJob::class);
    }
}