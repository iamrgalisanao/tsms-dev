<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\PosTerminal;
use App\Models\Transaction;
use App\Services\TransactionValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

class TransactionValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $validationService;
    protected $tenant;
    protected $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->validationService = new TransactionValidationService();
        
        // Create test tenant
        $this->tenant = Tenant::factory()->create([
            'status' => 'active'
        ]);

        // Create test terminal
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);
    }

    public function test_validates_valid_transaction_data()
    {
        $data = [
            'tenant_id' => strval($this->tenant->id), // Cast to string
            'terminal_id' => $this->terminal->terminal_uid,
            'hardware_id' => 'HW-001',
            'transaction_id' => 'TXN-' . time(),
            'transaction_timestamp' => now()->toDateTimeString(),
            'gross_sales' => 1000.00,
            'net_sales' => 950.00,
            'vatable_sales' => 850.00,
            'vat_exempt_sales' => 100.00,
            'vat_amount' => 102.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'payload_checksum' => md5('test-payload') // Add required checksum
        ];

        $result = $this->validationService->validate($data);
        $this->assertTrue($result['valid'], print_r($result['errors'] ?? [], true));
    }

    public function test_detects_duplicate_transaction()
    {
        $transactionId = 'TXN-' . time();

        // Create existing transaction with explicit terminal_id
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,  // Ensure this is included
            'hardware_id' => 'HW-001',
            'transaction_id' => $transactionId,
            'transaction_timestamp' => now(),
            'gross_sales' => 1000.00,
            'net_sales' => 950.00,
            'vatable_sales' => 850.00,
            'vat_exempt_sales' => 100.00,
            'vat_amount' => 102.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test-payload')
        ]);

        // Log for debugging
        Log::info('Created test transaction', [
            'id' => $transaction->id,
            'terminal_id' => $transaction->terminal_id
        ]);

        // Verify the transaction was created
        $this->assertDatabaseHas('transactions', [
            'transaction_id' => $transactionId,
            'terminal_id' => $this->terminal->id
        ]);

        // Test duplicate detection
        $data = [
            'tenant_id' => strval($this->tenant->id),
            'terminal_id' => $this->terminal->terminal_uid,
            'hardware_id' => 'HW-001',
            'transaction_id' => $transactionId,
            'transaction_timestamp' => now()->toDateTimeString(),
            'gross_sales' => 1000.00,
            'net_sales' => 950.00,
            'vatable_sales' => 850.00,
            'vat_exempt_sales' => 100.00,
            'vat_amount' => 102.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'payload_checksum' => md5('test-payload')
        ];

        $result = $this->validationService->validate($data);
        $this->assertFalse($result['valid']);
        $this->assertContains('Transaction ID has already been processed', $result['errors']);
    }
}