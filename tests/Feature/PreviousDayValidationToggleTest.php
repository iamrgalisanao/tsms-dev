<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Transaction;
use App\Support\Settings;
use App\Services\TransactionValidationService;
use Carbon\Carbon;

class PreviousDayValidationToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_previous_day_rejected_when_toggle_off()
    {
        // Ensure default toggle is off
        Settings::set('allow_previous_day_transactions', false, 'boolean');

        $service = new TransactionValidationService();

        $tx = Transaction::factory()->make([
            'transaction_timestamp' => Carbon::now()->subDay()->toIso8601String(),
            'tenant_id' => 1,
            'transaction_id' => 'ABC12345',
        ]);

        $errors = $this->invokeMethod($service, 'validateTransactionIntegrity', [$tx]);

        $this->assertNotEmpty($errors, 'Expected previous-day transaction to be rejected when toggle off');
    }

    public function test_previous_day_allowed_when_toggle_on()
    {
        Settings::set('allow_previous_day_transactions', true, 'boolean');

        $service = new TransactionValidationService();

        $tx = Transaction::factory()->make([
            'transaction_timestamp' => Carbon::now()->subDay()->toIso8601String(),
            'tenant_id' => 1,
            'transaction_id' => 'ABC12346',
        ]);

        $errors = $this->invokeMethod($service, 'validateTransactionIntegrity', [$tx]);

        // When allowed, the timestamp check should not add an error for same-day; other errors may be empty
        $this->assertIsArray($errors);
        $this->assertTrue(! (collect($errors)->contains(function($e){ return str_contains($e, 'Only transactions dated today'); })), 'Expected no same-day rejection when toggle on');
    }

    /**
     * Helper to call protected/private methods
     */
    protected function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}
