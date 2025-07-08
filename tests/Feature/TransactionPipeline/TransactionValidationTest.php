<?php

namespace Tests\Feature\TransactionPipeline;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\TransactionValidation;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\TransactionValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class TransactionValidationTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $terminal;
    protected $validationService;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test data
        $this->tenant = Tenant::factory()->create([
            'customer_code' => 'TEST001',
            'status' => 'active'
        ]);
        
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status_id' => 1
        ]);
        
        $this->validationService = app(TransactionValidationService::class);
    }

    /** @test */
    public function validates_valid_transaction()
    {
        $transaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 100.00,
            'transaction_timestamp' => Carbon::now()->setHour(14)->toDateTimeString()
        ]);

        $errors = $this->validationService->validateTransaction($transaction->toArray());
        $this->assertEmpty($errors);
    }

    /** @test */
    public function validates_operating_hours()
    {
        $transaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 100.00,
            'transaction_timestamp' => Carbon::now()->setHour(23)->toDateTimeString()
        ]);

        $errors = $this->validationService->validateTransaction($transaction->toArray());
        $this->assertNotEmpty($errors);
        $this->assertContains('Transaction outside operating hours (6AM-10PM)', $errors);
    }

    /** @test */
    public function validates_negative_amounts()
    {
        $transaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => -50.00,
            'transaction_timestamp' => Carbon::now()->setHour(14)->toDateTimeString()
        ]);

        $errors = $this->validationService->validateTransaction($transaction->toArray());
        $this->assertNotEmpty($errors);
        $this->assertContains('Amount must be positive', $errors);
    }

    /** @test */
    public function validates_terminal_status()
    {
        // Create inactive terminal
        $inactiveTerminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status_id' => 2 // Inactive
        ]);

        $transaction = Transaction::factory()->create([
            'terminal_id' => $inactiveTerminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 100.00,
            'transaction_timestamp' => Carbon::now()->setHour(14)->toDateTimeString()
        ]);

        $errors = $this->validationService->validateTransaction($transaction->toArray());
        $this->assertNotEmpty($errors);
        $this->assertContains('Terminal is not active', $errors);
    }

    /** @test */
    public function validates_maximum_amount_limits()
    {
        $transaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 50000.00, // Exceeds typical limit
            'transaction_timestamp' => Carbon::now()->setHour(14)->toDateTimeString()
        ]);

        $errors = $this->validationService->validateTransaction($transaction->toArray());
        $this->assertNotEmpty($errors);
        $this->assertContains('Amount exceeds maximum limit', $errors);
    }

    /** @test */
    public function validates_minimum_amount_limits()
    {
        $transaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 0.50, // Below minimum
            'transaction_timestamp' => Carbon::now()->setHour(14)->toDateTimeString()
        ]);

        $errors = $this->validationService->validateTransaction($transaction->toArray());
        $this->assertNotEmpty($errors);
        $this->assertContains('Amount below minimum limit', $errors);
    }

    /** @test */
    public function validates_transaction_timestamp_format()
    {
        $transactionData = [
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 100.00,
            'transaction_timestamp' => 'invalid-timestamp'
        ];

        $errors = $this->validationService->validateTransaction($transactionData);
        $this->assertNotEmpty($errors);
        $this->assertContains('Invalid timestamp format', $errors);
    }

    /** @test */
    public function validates_future_timestamps()
    {
        $transaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 100.00,
            'transaction_timestamp' => Carbon::now()->addDays(1)->toDateTimeString()
        ]);

        $errors = $this->validationService->validateTransaction($transaction->toArray());
        $this->assertNotEmpty($errors);
        $this->assertContains('Transaction timestamp cannot be in the future', $errors);
    }

    /** @test */
    public function validates_old_transactions()
    {
        $transaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 100.00,
            'transaction_timestamp' => Carbon::now()->subDays(8)->toDateTimeString()
        ]);

        $errors = $this->validationService->validateTransaction($transaction->toArray());
        $this->assertNotEmpty($errors);
        $this->assertContains('Transaction is too old (> 7 days)', $errors);
    }

    /** @test */
    public function validates_customer_code_format()
    {
        $transactionData = [
            'terminal_id' => $this->terminal->id,
            'customer_code' => 'INVALID-CODE-123',
            'base_amount' => 100.00,
            'transaction_timestamp' => Carbon::now()->setHour(14)->toDateTimeString()
        ];

        $errors = $this->validationService->validateTransaction($transactionData);
        $this->assertNotEmpty($errors);
        $this->assertContains('Invalid customer code format', $errors);
    }

    /** @test */
    public function validates_terminal_belongs_to_customer()
    {
        $otherTenant = Tenant::factory()->create(['customer_code' => 'OTHER001']);
        $otherTerminal = PosTerminal::factory()->create(['tenant_id' => $otherTenant->id]);

        $transactionData = [
            'terminal_id' => $otherTerminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 100.00,
            'transaction_timestamp' => Carbon::now()->setHour(14)->toDateTimeString()
        ];

        $errors = $this->validationService->validateTransaction($transactionData);
        $this->assertNotEmpty($errors);
        $this->assertContains('Terminal does not belong to customer', $errors);
    }

    /** @test */
    public function validates_duplicate_transaction_ids()
    {
        $transactionId = 'TXN-' . uniqid();
        
        // Create first transaction
        Transaction::factory()->create([
            'transaction_id' => $transactionId,
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code
        ]);

        $transactionData = [
            'transaction_id' => $transactionId,
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 100.00,
            'transaction_timestamp' => Carbon::now()->setHour(14)->toDateTimeString()
        ];

        $errors = $this->validationService->validateTransaction($transactionData);
        $this->assertNotEmpty($errors);
        $this->assertContains('Transaction ID already exists', $errors);
    }

    /** @test */
    public function validates_required_fields()
    {
        $transactionData = [
            // Missing required fields
            'base_amount' => 100.00,
        ];

        $errors = $this->validationService->validateTransaction($transactionData);
        $this->assertNotEmpty($errors);
        $this->assertContains('Terminal ID is required', $errors);
        $this->assertContains('Customer code is required', $errors);
        $this->assertContains('Transaction timestamp is required', $errors);
    }

    /** @test */
    public function validates_decimal_precision()
    {
        $transaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 100.123, // Too many decimal places
            'transaction_timestamp' => Carbon::now()->setHour(14)->toDateTimeString()
        ]);

        $errors = $this->validationService->validateTransaction($transaction->toArray());
        $this->assertNotEmpty($errors);
        $this->assertContains('Amount has too many decimal places', $errors);
    }

    /** @test */
    public function validates_concurrent_transactions()
    {
        $timestamp = Carbon::now()->setHour(14)->toDateTimeString();
        
        // Create multiple transactions at same time
        for ($i = 0; $i < 3; $i++) {
            $transaction = Transaction::factory()->create([
                'terminal_id' => $this->terminal->id,
                'customer_code' => $this->tenant->customer_code,
                'base_amount' => 100.00,
                'transaction_timestamp' => $timestamp
            ]);
            
            $errors = $this->validationService->validateTransaction($transaction->toArray());
            $this->assertEmpty($errors, "Transaction $i should be valid");
        }
    }

    /** @test */
    public function validates_transaction_items_consistency()
    {
        $transactionData = [
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 100.00,
            'transaction_timestamp' => Carbon::now()->setHour(14)->toDateTimeString(),
            'items' => [
                ['price' => 50.00, 'quantity' => 1],
                ['price' => 60.00, 'quantity' => 1] // Total 110.00 != base_amount 100.00
            ]
        ];

        $errors = $this->validationService->validateTransaction($transactionData);
        $this->assertNotEmpty($errors);
        $this->assertContains('Items total does not match base amount', $errors);
    }

    /** @test */
    public function validates_hardware_id_format()
    {
        $transactionData = [
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 100.00,
            'transaction_timestamp' => Carbon::now()->setHour(14)->toDateTimeString(),
            'hardware_id' => 'INVALID-HW-ID'
        ];

        $errors = $this->validationService->validateTransaction($transactionData);
        $this->assertNotEmpty($errors);
        $this->assertContains('Invalid hardware ID format', $errors);
    }

    /** @test */
    public function logs_validation_results()
    {
        $transaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => -50.00, // Invalid amount
            'transaction_timestamp' => Carbon::now()->setHour(14)->toDateTimeString()
        ]);

        $errors = $this->validationService->validateTransaction($transaction->toArray());
        
        // Verify validation was logged
        $this->assertDatabaseHas('transaction_validations', [
            'transaction_id' => $transaction->id,
            'validation_status' => 'FAILED'
        ]);
    }

    /** @test */
    public function validates_business_rules()
    {
        // Test specific business rules
        $transactionData = [
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 100.00,
            'transaction_timestamp' => Carbon::now()->setHour(14)->toDateTimeString(),
            'payment_method' => 'INVALID_METHOD'
        ];

        $errors = $this->validationService->validateTransaction($transactionData);
        $this->assertNotEmpty($errors);
        $this->assertContains('Invalid payment method', $errors);
    }

    /** @test */
    public function validates_transaction_sequence()
    {
        // Create transactions with sequence numbers
        $transactionData1 = [
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 100.00,
            'transaction_timestamp' => Carbon::now()->setHour(14)->toDateTimeString(),
            'sequence_number' => 1
        ];

        $transactionData2 = [
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => 100.00,
            'transaction_timestamp' => Carbon::now()->setHour(14)->addMinutes(1)->toDateTimeString(),
            'sequence_number' => 3 // Gap in sequence
        ];

        $errors1 = $this->validationService->validateTransaction($transactionData1);
        $errors2 = $this->validationService->validateTransaction($transactionData2);
        
        $this->assertEmpty($errors1);
        $this->assertNotEmpty($errors2);
        $this->assertContains('Sequence number gap detected', $errors2);
    }

    /** @test */
    public function validates_multiple_validation_errors()
    {
        $transactionData = [
            'terminal_id' => $this->terminal->id,
            'customer_code' => $this->tenant->customer_code,
            'base_amount' => -100.00, // Invalid amount
            'transaction_timestamp' => Carbon::now()->addDays(1)->toDateTimeString() // Future timestamp
        ];

        $errors = $this->validationService->validateTransaction($transactionData);
        $this->assertNotEmpty($errors);
        $this->assertCount(2, $errors); // Should have both errors
        $this->assertContains('Amount must be positive', $errors);
        $this->assertContains('Transaction timestamp cannot be in the future', $errors);
    }
}
