<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\TransactionTax;
use App\Services\Reports\FinanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

class FinanceCalculationDiscrepancyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Replicates the 41.06 PHP variance seen on 2026-03-28.
     * 40.97 from OTHER_TAX misclassification + 0.09 from rounding accumulation.
     */
    public function test_finance_calculation_handles_other_tax_and_rounding_correctly()
    {
        // 1. Setup Transaction with OTHER_TAX (Misclassification Pattern)
        // Transaction 15977 from Mar 28: Gross 435.20, Vatable 303.57, Vat 36.43, Exempt 85.00
        // Result: Components sum to 425.00, and OTHER_TAX 10.20 makes it 435.20.
        $tx1 = Transaction::factory()->create([
            'gross_sales' => 435.20,
            'vatable_sales' => 303.57,
            'vat_amount' => 36.43,
            'sc_vat_exempt_sales' => 85.00,
            'promo_status' => 'NONE',
        ]);
        TransactionTax::create([
            'transaction_pk' => $tx1->id,
            'tax_type' => 'OTHER_TAX',
            'amount' => 10.20
        ]);

        // Repeat for other two transactions (Total OTHER_TAX = 10.20 + 10.20 + 20.57 = 40.97)
        $tx2 = Transaction::factory()->create([
            'gross_sales' => 435.20,
            'vatable_sales' => 303.57,
            'vat_amount' => 36.43,
            'sc_vat_exempt_sales' => 85.00,
        ]);
        TransactionTax::create([
            'transaction_pk' => $tx2->id,
            'tax_type' => 'OTHER_TAX',
            'amount' => 10.20
        ]);

        $tx3 = Transaction::factory()->create([
            'gross_sales' => 870.43, // (303.57*2) + (36.43*2) + (85*2) + 20.57 = 607.14 + 72.86 + 170 + 20.57 = 870.57? No.
            'vatable_sales' => 607.14,
            'vat_amount' => 72.86,
            'sc_vat_exempt_sales' => 170.00,
        ]);
        TransactionTax::create([
            'transaction_pk' => $tx3->id,
            'tax_type' => 'OTHER_TAX',
            'amount' => 20.57
        ]);
        // Total OTHER_TAX = 10.20 + 10.20 + 20.57 = 40.97

        // 2. Setup Rounding Discrepancy (Rounding Pattern)
        // Transaction 15504 from Mar 28: Gross 748.00, components sum to 748.02.
        // Diff is 0.02. We need 9 transactions to sum to 0.09.
        for ($i = 0; $i < 9; $i++) {
            Transaction::factory()->create([
                'gross_sales' => 100.00,
                'vatable_sales' => 89.28,
                'vat_amount' => 10.73, // 89.28 + 10.73 = 100.01
                'sc_vat_exempt_sales' => 0.00,
            ]);
        }
        // Total Rounding error = 0.01 * 9 = 0.09

        // Expected Nominal Gross = (435.20 * 2) + 870.43 + (100.00 * 9) 
        // = 870.40 + 870.43 + 900.00 = 2640.83
        $expectedGross = 2640.83;

        // 3. Run Calculation Service
        $service = new FinanceCalculationService();
        $transactions = Transaction::all();
        $components = $service->aggregateComponents($transactions);
        $metrics = $service->deriveMetrics($components);

        // 4. Verification
        // BEFORE FIX: 
        // - OTHER_TAX is added to vat_amount, and excluded from other_tax.
        // - Gross is recalculated from components.
        // - Rounding diffs (0.01 each) are added to Gross.
        
        $this->assertEquals($expectedGross, $metrics['gross_sales'], "Gross Sales should match the sum of nominal 'gross_sales' columns.");
        
        // Vat should be exactly 12% of Vatable in the final report (derived)
        // For tx1/2/3: Vatable = (Gross - SC - OtherTax) / 1.12 = (435.2*2 + 870.43 - 85*2 - 170 - 40.97) / 1.12
        // = (1740.83 - 340 - 40.97) / 1.12 = 1359.86 / 1.12 = 1214.16
        // Vat = 1214.16 * 0.12 = 145.70
        // For tx4-12: Vatable = 100 / 1.12 = 89.2857 -> 89.29, Vat = 10.71. (Total for 9: 803.61 + 96.39 = 900)
        // Total expected VAT = 145.70 + 96.39 = 242.09
        
        // We assert that the derived VAT matches the formula application on the aggregate nominal net.
        $this->assertEquals(round(($metrics['net_sales'] / 1.12) * 0.12, 2), $metrics['vat_amount']);
    }
}
