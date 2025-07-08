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
        
        $data = [
            'terminal_id' => $terminal->id,
            'amount' => 100.00,
            'transaction_date' => Carbon::now()->setHour(14)->toDateTimeString()
        ];

        $errors = $this->service->validateTransaction($data);
        $this->assertEmpty($errors);
    }

    public function test_validates_operating_hours()
    {
        $terminal = PosTerminal::factory()->create(['status_id' => 1]);
        
        $data = [
            'terminal_id' => $terminal->id,
            'amount' => 100.00,
            'transaction_date' => Carbon::now()->setHour(23)->toDateTimeString()
        ];

        $errors = $this->service->validateTransaction($data);
        $this->assertNotEmpty($errors);
        $this->assertContains('Transaction outside operating hours (6AM-10PM)', $errors);
    }
}
