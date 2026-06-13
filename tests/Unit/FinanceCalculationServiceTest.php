<?php

namespace Tests\Unit;

use App\Services\Reports\FinanceCalculationService;
use PHPUnit\Framework\TestCase;

class FinanceCalculationServiceTest extends TestCase
{
    public function test_csmr_normalizes_vat_inclusive_vatable_sales(): void
    {
        $service = new FinanceCalculationService();

        $metrics = $service->deriveMetrics([
            'vatable_sales' => 14629.00,
            'sc_vat_exempt_sales' => 545.71,
            'vat_amount' => 1567.39,
            'promo_with_approval' => 0,
            'promo_without_approval' => 0,
            'employee_discount' => 0,
            'senior_discount' => 106.25,
            'pwd_discount' => 30.18,
            'vip_discount' => 0,
            'other_tax' => 0,
            'service_charge_distributed' => 0,
            'service_charge_retained' => 0,
            'regular_discount' => 0,
            'gross_sales' => 15311.14,
            'net_sales' => 14629.00,
        ], ['gross_sales_basis' => 'pre_deduction']);

        $this->assertEqualsWithDelta(13061.61, $metrics['vatable_sales'], 0.001);
        $this->assertEqualsWithDelta(545.71, $metrics['sc_vat_exempt_sales'], 0.001);
        $this->assertEqualsWithDelta(1567.39, $metrics['vat_amount'], 0.001);
        $this->assertEqualsWithDelta(15311.14, $metrics['gross_sales'], 0.001);
    }
}
