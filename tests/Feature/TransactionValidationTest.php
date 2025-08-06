<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\PosTerminal;
use App\Services\TransactionValidationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TransactionValidationTest extends TestCase
{
    use RefreshDatabase;

    protected $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TransactionValidationService::class);
    }

    public function test_validates_valid_transaction()
    {
        $terminal = PosTerminal::factory()->create(['status_id' => 1]);
        $transaction = [
            'transaction_id' => 'txn-uuid-001',
            'transaction_timestamp' => Carbon::now()->setHour(14)->toIso8601String(),
            'base_amount' => 1000.00,
            'payload_checksum' => 'dummy-txn-checksum',
            'adjustments' => [
                ['adjustment_type' => 'promo_discount', 'amount' => 50.00],
                ['adjustment_type' => 'senior_discount', 'amount' => 20.00],
            ],
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => 120.00],
                ['tax_type' => 'OTHER_TAX', 'amount' => 10.00],
            ],
        ];
        $data = [
            'submission_uuid' => 'batch-uuid-123',
            'tenant_id' => 1,
            'terminal_id' => $terminal->id,
            'submission_timestamp' => Carbon::now()->toIso8601String(),
            'transaction_count' => 1,
            'payload_checksum' => 'dummy-batch-checksum',
            'transaction' => $transaction,
        ];
        $result = $this->service->validate($data);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_validates_operating_hours()
    {
        $terminal = PosTerminal::factory()->create(['status_id' => 1]);
        $transaction = [
            'transaction_id' => 'txn-uuid-002',
            'transaction_timestamp' => Carbon::now()->setHour(23)->toIso8601String(),
            'base_amount' => 100.00,
            'payload_checksum' => 'dummy-txn-checksum',
        ];
        $data = [
            'submission_uuid' => 'batch-uuid-124',
            'tenant_id' => 1,
            'terminal_id' => $terminal->id,
            'submission_timestamp' => Carbon::now()->toIso8601String(),
            'transaction_count' => 1,
            'payload_checksum' => 'dummy-batch-checksum',
            'transaction' => $transaction,
        ];
        $result = $this->service->validate($data);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertContains('Transaction outside operating hours (6AM-10PM)', $result['errors']);
    }
}
