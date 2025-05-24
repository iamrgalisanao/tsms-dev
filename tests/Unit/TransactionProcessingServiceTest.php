<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Services\TransactionProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

class TransactionProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $service;
    protected $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TransactionProcessingService::class);
        $this->terminal = PosTerminal::factory()->create();
    }

    public function test_generates_unique_transaction_id()
    {
        $data = [
            'terminal_id' => $this->terminal->id,
            'gross_sales' => 1000.00
        ];

        $result = $this->service->processTransaction($data);

        $this->assertArrayHasKey('transaction_id', $result);
        $this->assertStringStartsWith('TXN-', $result['transaction_id']);
    }

    public function test_logs_processing_errors()
    {
        Log::shouldReceive('error')
            ->once()
            ->with('Transaction processing error', \Mockery::any());

        $this->expectException(\Exception::class);

        $this->service->processTransaction([
            'terminal_id' => 999999, // Non-existent terminal
            'gross_sales' => 1000.00
        ]);
    }

    public function test_processes_valid_transaction()
    {
        $data = [
            'terminal_id' => $this->terminal->id,
            'gross_sales' => 1000.00
        ];

        $result = $this->service->processTransaction($data);

        $this->assertEquals('success', $result['status']);
        $this->assertDatabaseHas('transactions', [
            'terminal_id' => $this->terminal->id,
            'gross_sales' => 1000.00,
            'validation_status' => 'PENDING'
        ]);
    }
}