<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\Reports\FinanceCalculationService;
use Illuminate\Support\Collection;

class FinanceCalculationDiscrepancyTest extends TestCase
{
    /**
     * Replicates the 41.06 PHP variance seen on 2026-03-28 in a pure Unit Test.
     * This avoids any database connection requirements.
     */
    public function test_finance_calculation_handles_other_tax_and_rounding_correctly()
    {
        // 1. Mock Transaction with OTHER_TAX (Misclassification Pattern)
        // We use an anonymous class to mimic the Transaction model behavior
        $tx1 = new class {
            public $tax_amount;
            public $gross_sales = 435.20;
            public $vatable_sales = 303.57;
            public $vat_amount = 36.43;
            public $sc_vat_exempt_sales = 85.00;
            public $promo_status = 'NONE';
            public function taxes() {
                return new class($this->tax_amount) {
                    private $tax_amount;
                    private $is_excluding = false;
                    public function __construct($amt) { $this->tax_amount = $amt; }
                    public function whereIn($col, $val) { return $this; }
                    public function whereNotIn($col, $val) { $this->is_excluding = true; return $this; }
                    public function sum($col) { return $this->is_excluding ? 0.0 : $this->tax_amount; }
                };
            }
        };
        $tx1->tax_amount = 10.20;

        $tx2 = new class {
            public $tax_amount;
            public $gross_sales = 435.20;
            public $vatable_sales = 303.57;
            public $vat_amount = 36.43;
            public $sc_vat_exempt_sales = 85.00;
            public $promo_status = 'NONE';
            public function taxes() {
                return new class($this->tax_amount) {
                    private $tax_amount;
                    private $is_excluding = false;
                    public function __construct($amt) { $this->tax_amount = $amt; }
                    public function whereIn($col, $val) { return $this; }
                    public function whereNotIn($col, $val) { $this->is_excluding = true; return $this; }
                    public function sum($col) { return $this->is_excluding ? 0.0 : $this->tax_amount; }
                };
            }
        };
        $tx2->tax_amount = 10.20;

        $tx3 = new class {
            public $tax_amount;
            public $gross_sales = 870.43;
            public $vatable_sales = 607.14;
            public $vat_amount = 72.86;
            public $sc_vat_exempt_sales = 170.00;
            public $promo_status = 'NONE';
            public function taxes() {
                return new class($this->tax_amount) {
                    private $tax_amount;
                    private $is_excluding = false;
                    public function __construct($amt) { $this->tax_amount = $amt; }
                    public function whereIn($col, $val) { return $this; }
                    public function whereNotIn($col, $val) { $this->is_excluding = true; return $this; }
                    public function sum($col) { return $this->is_excluding ? 0.0 : $this->tax_amount; }
                };
            }
        };
        $tx3->tax_amount = 20.57;

        // 2. Mock Rounding Discrepancy (9 transactions with 0.01 error)
        $roundingTransactions = [];
        for ($i = 0; $i < 9; $i++) {
            $roundingTransactions[] = (object) [
                'gross_sales' => 100.00,
                'vatable_sales' => 89.28,
                'vat_amount' => 10.73, // 89.28 + 10.73 = 100.01
                'sc_vat_exempt_sales' => 0.00,
                'promo_status' => 'NONE'
            ];
        }

        $allTransactions = collect([$tx1, $tx2, $tx3])->merge($roundingTransactions);
        
        $expectedGross = 2640.83; // (435.20*2) + 870.43 + (100.00*9)

        // 3. Run Calculation Service
        $service = new FinanceCalculationService();
        $components = $service->aggregateComponents($allTransactions);
        $metrics = $service->deriveMetrics($components);

        // 4. Verification
        $this->assertEquals($expectedGross, $metrics['gross_sales'], "Gross Sales should match nominal sum.");
        
        // VAT should match the raw recorded VAT if it exists, to mirror the POS system's actual tax calculation.
        $this->assertEquals(242.29, $metrics['vat_amount'], "VAT must match the raw recorded VAT.");
    }

    public function test_csmr_gross_sales_includes_vat_column()
    {
        $service = new FinanceCalculationService();

        $components = [
            'vatable_sales' => 81133.59,
            'sc_vat_exempt_sales' => 3936.03,
            'vat_amount' => 8692.94,
            'promo_with_approval' => 0.0,
            'promo_without_approval' => 0.0,
            'employee_discount' => 0.0,
            'senior_discount' => 430.86,
            'pwd_discount' => 553.12,
            'vip_discount' => 0.0,
            'other_tax' => 0.0,
            'service_charge_distributed' => 0.0,
            'service_charge_retained' => 0.0,
            'regular_discount' => 0.0,
            'gross_sales' => 94746.54,
            'net_sales' => 85069.62,
        ];

        $metrics = $service->deriveMetrics($components);

        // Expected gross = columns B:M, including VAT column D.
        $this->assertEquals(94746.54, $metrics['gross_sales']);
        $this->assertEquals(81133.59, $metrics['vatable_sales']);
    }

    public function test_goldilocks_monthly_gross_sales_uses_net_sales_through_service_charge_sum()
    {
        $service = new FinanceCalculationService();

        $metrics = $service->deriveMetrics([
            'vatable_sales' => 2158481.64,
            'sc_vat_exempt_sales' => 0.00,
            'vat_amount' => 231360.83,
            'promo_with_approval' => 26998.90,
            'promo_without_approval' => 0.00,
            'employee_discount' => 0.00,
            'senior_discount' => 759.46,
            'pwd_discount' => 131.00,
            'vip_discount' => 0.00,
            'other_tax' => 0.00,
            'service_charge_distributed' => 0.00,
            'service_charge_retained' => 0.00,
            'regular_discount' => 0.00,
            'gross_sales' => 2186371.00,
            'net_sales' => 2158481.64,
        ]);

        $this->assertEquals(2417731.83, $metrics['gross_sales']);
    }

    public function test_finance_calculation_handles_vat_exclusive_net_sales_correctly()
    {
        $service = new FinanceCalculationService();

        // Wendy's Serial #R70866 Z-reading replica
        $components = [
            'vatable_sales' => 49660.21,
            'sc_vat_exempt_sales' => 9313.72,
            'vat_amount' => 5959.23,
            'promo_with_approval' => 131.85,
            'promo_without_approval' => 0.0,
            'employee_discount' => 0.0,
            'senior_discount' => 2328.43,
            'pwd_discount' => 0.0,
            'vip_discount' => 0.0,
            'other_tax' => 0.0,
            'service_charge_distributed' => 523.32,
            'service_charge_retained' => 0.0,
            'regular_discount' => 0.0,
            'gross_sales' => 67925.28,
            'net_sales' => 58973.95, // VAT-exclusive net sales
        ];

        $metrics = $service->deriveMetrics($components);

        // Expected gross matches nominal gross within 1% threshold
        $this->assertEquals(67925.28, $metrics['gross_sales']);
        
        // Vatable sales should match the raw captured vatable sales.
        $this->assertEquals(49660.21, $metrics['vatable_sales']);
        
        // VAT amount should match raw vat
        $this->assertEquals(5959.23, $metrics['vat_amount']);
        
        // Net sales internally should be Vatable + VAT (49660.23 + 5959.23 = 55619.46)
        $this->assertEquals(55619.46, $metrics['net_sales']);
        
        // Net total (Vatable + VAT + Exempt) should match Adjusted Gross (55619.46 + 9313.72 = 64933.18)
        $this->assertEquals(64933.18, $metrics['net_total']);
    }
}
