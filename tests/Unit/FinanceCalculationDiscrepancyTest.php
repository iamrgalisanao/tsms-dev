<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
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
        
        // Vat should be derived from Net Sales
        $expectedVat = round(($metrics['net_sales'] / 1.12) * 0.12, 2);
        $this->assertEquals($expectedVat, $metrics['vat_amount'], "VAT must be derived accurately from Net Sales.");
    }

    public function test_gross_sales_does_not_double_count_vat_when_vatable_is_vat_inclusive()
    {
        $service = new FinanceCalculationService();

        $components = [
            // Upstream raw payload style: vatable already includes VAT here.
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
            // Nominal source contains the VAT-inclusive discrepancy.
            'gross_sales' => 94746.54,
            // Raw net from POS commonly includes exempt + VAT; service normalizes this.
            'net_sales' => 85069.62,
        ];

        $metrics = $service->deriveMetrics($components);

        // Expected gross = normalized vatable(ex-VAT) + exempt + VAT + discounts/service charge.
        $this->assertEquals(86053.60, $metrics['gross_sales']);
        $this->assertEquals(72440.65, $metrics['vatable_sales']);
    }
}
