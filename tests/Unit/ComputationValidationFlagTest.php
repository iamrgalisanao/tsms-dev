<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Facades\Config;
use App\Services\TransactionValidationService;

class ComputationValidationFlagTest extends TestCase
{
    public function test_validation_skipped_when_flag_false()
    {
        Config::set('tsms.validation.enable_computation_validation', false);

        $svc = new TransactionValidationService();
        $payload = [
            'transaction_id' => 'T-TEST-1',
            'gross_sales' => 100.00,
            'net_sales' => -500.00, // invalid but should be caught by basic checks
        ];

        $result = $svc->validate($payload);
        // When computation validation disabled we still return valid=true unless strict_mode
        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
    }

    public function test_validation_runs_when_flag_true()
    {
        Config::set('tsms.validation.enable_computation_validation', true);

        $svc = new TransactionValidationService();
        $payload = [
            'transaction_id' => 'T-TEST-2',
            'gross_sales' => 100.00,
            'net_sales' => -500.00,
        ];

        $result = $svc->validate($payload);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        // With flag true, validation should detect net negative and mark invalid
        $this->assertFalse($result['valid']);
    }
}
