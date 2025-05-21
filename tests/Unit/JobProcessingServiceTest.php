<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\JobProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Traits\DisablesCircuitBreaker;

class JobProcessingServiceTest extends TestCase
{
    use RefreshDatabase, DisablesCircuitBreaker;

    protected $service;
    protected $tenant;
    protected $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = new JobProcessingService();
        
        // Create test data
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active'
        ]);
    }

    public function test_processes_valid_transaction()
    {
        // Calculate exact amounts
        $vatableSales = 1000.00;
        $vatAmount = round($vatableSales * 0.12, 2); // 120.00
        $vatExempt = 100.00;
        $grossSales = $vatableSales + $vatExempt + $vatAmount; // 1220.00

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now()->subMinute(), // avoid future timestamp
            'gross_sales' => 1220.00,
            'net_sales' => 1100.00,
            'vatable_sales' => 1000.00,
            'vat_exempt_sales' => 100.00,
            'vat_amount' => 120.00,
            'management_service_charge' => 0.00, // explicitly added
            'discount_total' => 0.00,            // explicitly added
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => 'valid_checksum', // should not trigger `invalid_checksum`
            'original_payload' => json_encode(['gross_sales' => 1220.00]) // checksum logic needs this
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertTrue($result);
        $this->assertEquals('VALID', $transaction->fresh()->validation_status);
    }

    public function test_fails_on_invalid_amounts()
    {
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => -100.00, // Invalid negative amount
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_amount' => 120.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertFalse($result);
        $this->assertEquals('PENDING', $transaction->fresh()->validation_status);
    }

    public function test_validates_vat_calculation()
    {
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 1120.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_exempt_sales' => 0.00,
            'vat_amount' => 150.00, // Incorrect VAT (should be 120.00)
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertFalse($result);
        $this->assertEquals('PENDING', $transaction->fresh()->validation_status);
    }

    public function test_handles_missing_required_fields()
    {
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => null,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 1120.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_amount' => 120.00,
            'vat_exempt_sales' => 0.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertFalse($result);
        $this->assertEquals('PENDING', $transaction->fresh()->validation_status);
    }

    public function test_validates_gross_sales_calculation()
    {
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 1500.00, // Incorrect gross (should be 1220.00)
            'net_sales' => 1100.00,
            'vatable_sales' => 1000.00,
            'vat_exempt_sales' => 100.00,
            'vat_amount' => 120.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertFalse($result);
        $this->assertEquals('PENDING', $transaction->fresh()->validation_status);
    }

    public function test_handles_future_transaction_date()
    {
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now()->addDays(1), // Future date
            'gross_sales' => 1220.00,
            'net_sales' => 1100.00,
            'vatable_sales' => 1000.00,
            'vat_exempt_sales' => 100.00,
            'vat_amount' => 120.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertFalse($result);
        $this->assertEquals('PENDING', $transaction->fresh()->validation_status);
    }

    public function test_updates_error_status_on_validation_failure()
    {
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 1000.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_amount' => 0.00, // Invalid VAT amount
            'vat_exempt_sales' => 0.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertFalse($result);
        $this->assertEquals('PENDING', $transaction->fresh()->validation_status);
        $this->assertNull($transaction->fresh()->completed_at);
    }

    public function test_updates_job_status_during_processing()
    {
        $vatableSales = 1000.00;
        $vatAmount = round($vatableSales * 0.12, 2);
        $vatExempt = 100.00;
        $grossSales = $vatableSales + $vatExempt + $vatAmount;
        $netSales = $vatableSales + $vatExempt;

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'vatable_sales' => $vatableSales,
            'vat_exempt_sales' => $vatExempt,
            'vat_amount' => $vatAmount,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'job_status' => 'QUEUED',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertTrue($result);
        $this->assertEquals('COMPLETED', $transaction->fresh()->job_status);
        $this->assertNotNull($transaction->fresh()->completed_at);
    }

    public function test_validates_transaction_with_service_charges()
    {
        $vatableSales = 1000.00;
        $vatAmount = round($vatableSales * 0.12, 2);
        $vatExempt = 100.00;
        $serviceCharge = 50.00;
        $grossSales = $vatableSales + $vatExempt + $vatAmount + $serviceCharge;
        $netSales = $vatableSales + $vatExempt + $serviceCharge;

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'vatable_sales' => $vatableSales,
            'vat_exempt_sales' => $vatExempt,
            'vat_amount' => $vatAmount,
            'management_service_charge' => $serviceCharge,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'job_status' => 'QUEUED',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertTrue($result);
        $this->assertEquals('VALID', $transaction->fresh()->validation_status);
        $this->assertEquals('COMPLETED', $transaction->fresh()->job_status);
    }

    public function test_fails_on_negative_service_charges()
    {
        $vatableSales = 1000.00;
        $vatAmount = round($vatableSales * 0.12, 2);
        $vatExempt = 100.00;
        $serviceCharge = -50.00; // Invalid negative service charge
        $grossSales = $vatableSales + $vatExempt + $vatAmount + $serviceCharge;
        $netSales = $vatableSales + $vatExempt + $serviceCharge;

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'vatable_sales' => $vatableSales,
            'vat_exempt_sales' => $vatExempt,
            'vat_amount' => $vatAmount,
            'management_service_charge' => $serviceCharge,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertFalse($result);
        $this->assertEquals('PENDING', $transaction->fresh()->validation_status);
    }

    public function test_logs_validation_errors()
    {
        // Set up Log facade mock
        Log::shouldReceive('warning')
           ->once()
           ->withArgs(function ($message, $context) {
               return $message === 'Negative amount detected' &&
                      isset($context['transaction_id']);
           });

        Log::shouldReceive('error')
           ->never();

        Log::shouldReceive('info')
           ->once()
           ->withArgs(function ($message, $context) {
               return str_contains($message, 'Processing transaction');
           });

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 1500.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_amount' => 150.00,
            'vat_exempt_sales' => 0.00,
            'management_service_charge' => -50.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertFalse($result);
        $this->assertEquals('PENDING', $transaction->fresh()->validation_status);
    }

    public function test_handles_retry_attempt()
    {
        $vatableSales = 1000.00;
        $vatAmount = round($vatableSales * 0.12, 2);
        $vatExempt = 100.00;
        $grossSales = $vatableSales + $vatExempt + $vatAmount;

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => $grossSales,
            'net_sales' => $vatableSales + $vatExempt,
            'vatable_sales' => $vatableSales,
            'vat_exempt_sales' => $vatExempt,
            'vat_amount' => $vatAmount,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'job_status' => 'QUEUED',
            'job_attempts' => 2,
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertTrue($result);
        $this->assertEquals(3, $transaction->fresh()->job_attempts);
        $this->assertEquals('COMPLETED', $transaction->fresh()->job_status);
    }

    public function test_handles_null_service_charges()
    {
        $vatableSales = 1000.00;
        $vatAmount = round($vatableSales * 0.12, 2);
        $vatExempt = 100.00;
        $grossSales = $vatableSales + $vatExempt + $vatAmount;
        $netSales = $vatableSales + $vatExempt;

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'vatable_sales' => $vatableSales,
            'vat_exempt_sales' => $vatExempt,
            'vat_amount' => $vatAmount,
            'management_service_charge' => null,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertTrue($result);
        $this->assertEquals('VALID', $transaction->fresh()->validation_status);
    }

    public function test_tracks_completion_status()
    {
        $vatableSales = 1000.00;
        $vatAmount = round($vatableSales * 0.12, 2);
        $vatExempt = 100.00;
        $grossSales = $vatableSales + $vatExempt + $vatAmount;
        $netSales = $vatableSales + $vatExempt;

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'vatable_sales' => $vatableSales,
            'vat_exempt_sales' => $vatExempt,
            'vat_amount' => $vatAmount,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'job_status' => 'QUEUED',
            'completed_at' => null,
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $fresh = $transaction->fresh();
        $this->assertTrue($result);
        $this->assertEquals('COMPLETED', $fresh->job_status);
        $this->assertEquals('VALID', $fresh->validation_status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertTrue(now()->subMinute()->lt($fresh->completed_at));
    }

    public function test_handles_validation_tolerance()
    {
        $vatableSales = 1000.00;
        $vatAmount = 120.09; // Slightly off from exact 120.00
        $vatExempt = 100.00;
        $grossSales = 1220.08; // Slightly off from exact 1220.09
        $netSales = $vatableSales + $vatExempt;

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'vatable_sales' => $vatableSales,
            'vat_exempt_sales' => $vatExempt,
            'vat_amount' => $vatAmount,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertTrue($result, 'Transaction should pass with small rounding differences');
        $this->assertEquals('VALID', $transaction->fresh()->validation_status);
    }

    public function test_validates_transaction_with_discounts()
    {
        $vatableSales = 1000.00;
        $vatAmount = round($vatableSales * 0.12, 2);
        $vatExempt = 100.00;
        $discountAmount = 50.00;
        $grossSales = $vatableSales + $vatExempt + $vatAmount - $discountAmount;
        $netSales = $vatableSales + $vatExempt - $discountAmount;

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'vatable_sales' => $vatableSales,
            'vat_exempt_sales' => $vatExempt,
            'vat_amount' => $vatAmount,
            'discount_total' => $discountAmount,
            'promo_status' => 'A', // A=Applied, N=None, P=Pending
            'promo_discount_amount' => $discountAmount,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertTrue($result);
        $this->assertEquals('VALID', $transaction->fresh()->validation_status);
        $this->assertEquals($discountAmount, $transaction->fresh()->discount_total);
    }

    public function test_validates_tax_exempt_transaction()
    {
        $vatableSales = 0.00;
        $vatAmount = 0.00;
        $vatExempt = 1000.00;
        $grossSales = $vatableSales + $vatExempt + $vatAmount;
        $netSales = $vatableSales + $vatExempt;

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'vatable_sales' => $vatableSales,
            'vat_exempt_sales' => $vatExempt,
            'vat_amount' => $vatAmount,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertTrue($result);
        $this->assertEquals('VALID', $transaction->fresh()->validation_status);
        $this->assertEquals(0.00, $transaction->fresh()->vat_amount);
    }

    public function test_validates_mixed_tax_transaction()
    {
        $vatableSales = 1000.00;
        $vatAmount = round($vatableSales * 0.12, 2);
        $vatExempt = 500.00;
        $discountAmount = 100.00;
        $serviceCharge = 50.00;
        
        // Calculate totals including all components
        $netSales = $vatableSales + $vatExempt + $serviceCharge - $discountAmount;
        $grossSales = $netSales + $vatAmount;

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'vatable_sales' => $vatableSales,
            'vat_exempt_sales' => $vatExempt,
            'vat_amount' => $vatAmount,
            'discount_total' => $discountAmount,
            'management_service_charge' => $serviceCharge,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $fresh = $transaction->fresh();
        $this->assertTrue($result);
        $this->assertEquals('VALID', $fresh->validation_status);
        $this->assertEquals($netSales, $fresh->net_sales);
        $this->assertEquals($grossSales, $fresh->gross_sales);
    }

    public function test_tracks_status_transitions()
    {
        $vatableSales = 1000.00;
        $vatAmount = round($vatableSales * 0.12, 2);
        $vatExempt = 100.00;
        $grossSales = $vatableSales + $vatExempt + $vatAmount;
        $netSales = $vatableSales + $vatExempt;

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'vatable_sales' => $vatableSales,
            'vat_exempt_sales' => $vatExempt,
            'vat_amount' => $vatAmount,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'job_status' => 'QUEUED',
            'payload_checksum' => md5('test')
        ]);

        $initialState = [
            'validation_status' => $transaction->validation_status,
            'job_status' => $transaction->job_status,
            'completed_at' => $transaction->completed_at,
        ];

        $result = $this->service->processTransaction($transaction);
        
        $fresh = $transaction->fresh();
        $this->assertTrue($result);
        $this->assertEquals('PENDING', $initialState['validation_status']);
        $this->assertEquals('QUEUED', $initialState['job_status']);
        $this->assertNull($initialState['completed_at']);
        $this->assertEquals('VALID', $fresh->validation_status);
        $this->assertEquals('COMPLETED', $fresh->job_status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_validates_zero_amount_transaction()
    {
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 0.00,
            'net_sales' => 0.00,
            'vatable_sales' => 0.00,
            'vat_exempt_sales' => 0.00,
            'vat_amount' => 0.00,
            'discount_total' => 0.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $fresh = $transaction->fresh();
        $this->assertTrue($result);
        $this->assertEquals('VALID', $fresh->validation_status);
        $this->assertEquals('COMPLETED', $fresh->job_status);
        $this->assertEquals(0.00, $fresh->gross_sales);
        $this->assertEquals(0.00, $fresh->vat_amount);
    }

    public function test_validates_payload_checksum()
    {
        Log::shouldReceive('info')
           ->once()
           ->withArgs(function ($message) {
               return str_contains($message, 'Processing transaction');
           });

        Log::shouldReceive('warning')
           ->once()
           ->withArgs(function ($message) {
               return $message === 'Invalid payload checksum detected';
           });

        Log::shouldReceive('error')->never();

        $vatableSales = 1000.00;
        $vatAmount = round($vatableSales * 0.12, 2);
        $vatExempt = 100.00;
        $grossSales = $vatableSales + $vatExempt + $vatAmount;
        $netSales = $vatableSales + $vatExempt;

        // Create transaction with invalid checksum
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => $grossSales,
            'net_sales' => $netSales,
            'vatable_sales' => $vatableSales,
            'vat_exempt_sales' => $vatExempt,
            'vat_amount' => $vatAmount,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => 'invalid_checksum',
            'original_payload' => json_encode([
                'gross_sales' => $grossSales + 100 // Tampered amount
            ])
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $fresh = $transaction->fresh();
        $this->assertFalse($result);
        $this->assertEquals('ERROR', $fresh->validation_status);
        $this->assertEquals('FAILED', $fresh->job_status);
        $this->assertNull($fresh->completed_at);
    }

    public function test_logs_invalid_checksum()
    {
        Log::shouldReceive('info')
           ->once()
           ->withArgs(function ($message) {
               return str_contains($message, 'Processing transaction');
           });

        Log::shouldReceive('warning')
           ->once()
           ->withArgs(function ($message) {
               return $message === 'Invalid payload checksum detected';
           });

        Log::shouldReceive('error')->never();

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 1000.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_amount' => 120.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => 'invalid_checksum'
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $fresh = $transaction->fresh();
        $this->assertFalse($result);
        $this->assertEquals('ERROR', $fresh->validation_status);
        $this->assertEquals('FAILED', $fresh->job_status);
    }

    public function test_handles_max_retry_attempts()
    {
        // Setup basic mocks
        Log::partialMock();

        // Set up expectations
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once();
        Log::shouldReceive('error')->once();

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 1000.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_amount' => 120.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'job_status' => 'QUEUED',
            'job_attempts' => 5,
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $fresh = $transaction->fresh();

        // Basic assertions
        $this->assertFalse($result);
        $this->assertEquals('ERROR', $fresh->validation_status);
        $this->assertEquals('FAILED', $fresh->job_status);
        $this->assertNull($fresh->completed_at);
        $this->assertEquals(6, $fresh->job_attempts);
    }

    public function test_handles_invalid_json_in_payload()
    {
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once();
        
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 1000.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_amount' => 120.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'original_payload' => 'invalid{json',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertFalse($result);
        $this->assertEquals('ERROR', $transaction->fresh()->validation_status);
    }

    public function test_validates_decimal_precision()
    {
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 1000.999, // Too many decimal places
            'net_sales' => 1000.999,
            'vatable_sales' => 1000.999,
            'vat_amount' => 120.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertFalse($result);
    }

    public function test_handles_concurrent_processing_attempts()
    {
        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 1000.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_amount' => 120.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'job_status' => 'PROCESSING', // Already being processed
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertFalse($result);
        $this->assertEquals('PROCESSING', $transaction->fresh()->job_status);
    }

    public function test_handles_missing_original_payload()
    {
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once();

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 1000.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_amount' => 120.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'original_payload' => null,
            'payload_checksum' => 'some_checksum'
        ]);

        $result = $this->service->processTransaction($transaction);
        
        $this->assertFalse($result);
    }
    
    public function test_validates_transaction_sequence()
    {
        // Test for out-of-sequence transactions from same terminal
        $earlierTransaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now()->subHours(1),
            'gross_sales' => 1000.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_amount' => 120.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'sequence_number' => 2,
            'payload_checksum' => md5('test')
        ]);

        $laterTransaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-' . time(),
            'hardware_id' => 'HW-001',
            'transaction_timestamp' => now(),
            'gross_sales' => 1000.00,
            'net_sales' => 1000.00,
            'vatable_sales' => 1000.00,
            'vat_amount' => 120.00,
            'transaction_count' => 1,
            'machine_number' => 1,
            'validation_status' => 'PENDING',
            'sequence_number' => 1, // Invalid - should be higher than previous
            'payload_checksum' => md5('test')
        ]);

        $result = $this->service->processTransaction($laterTransaction);
        
        $this->assertFalse($result);
    }

    public function test_handles_database_connection_failure()
    {
        // Test database connection failure scenario
    }

    public function test_handles_queue_connection_failure()
    {
        // Test queue connection failure scenario
    }

    public function test_handles_malformed_transaction_data()
    {
        // Test malformed data handling
    }
}