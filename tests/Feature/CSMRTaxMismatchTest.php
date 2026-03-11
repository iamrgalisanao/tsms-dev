<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionTax;
use App\Services\Reports\FinanceCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CSMRTaxMismatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that VAT-related tax types (VAT, VAT_AMOUNT, VATABLE_SALES, SC_VAT_EXEMPT_SALES)
     * are correctly excluded from the 'other_tax' summation.
     */
    public function test_vat_components_are_excluded_from_other_tax()
    {
        $tenant = Tenant::factory()->create();
        $terminal = \App\Models\PosTerminal::factory()->create([
            'tenant_id' => $tenant->id
        ]);

        $tx = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'vatable_sales' => 1000.00,
            'vat_amount' => 120.00,
            'sc_vat_exempt_sales' => 500.00,
        ]);

        // Explicitly add tax rows that should be excluded from other_tax
        TransactionTax::create([
            'transaction_pk' => $tx->id,
            'tax_type' => 'VAT',
            'amount' => 120.00
        ]);

        TransactionTax::create([
            'transaction_pk' => $tx->id,
            'tax_type' => 'VAT_AMOUNT',
            'amount' => 120.00
        ]);

        TransactionTax::create([
            'transaction_pk' => $tx->id,
            'tax_type' => 'VATABLE_SALES',
            'amount' => 1000.00
        ]);

        TransactionTax::create([
            'transaction_pk' => $tx->id,
            'tax_type' => 'SC_VAT_EXEMPT_SALES',
            'amount' => 500.00
        ]);

        // This one SHOULD be included
        TransactionTax::create([
            'transaction_pk' => $tx->id,
            'tax_type' => 'LOCAL_TAX',
            'amount' => 15.50
        ]);

        $service = new FinanceCalculationService();
        $components = $service->aggregateComponents(collect([$tx]));

        $this->assertEquals(15.50, $components['other_tax'], 'Other Tax should only include LOCAL_TAX');

        // Also check Transaction model method
        $this->assertEquals(15.50, $tx->otherTaxSum(), 'Transaction otherTaxSum should only include LOCAL_TAX');
    }
}
