<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\TransactionAdjustment;
use App\Models\TransactionTax;
use App\Presenters\TransactionSummaryPresenter;

class TransactionSummaryPresenterTest extends TestCase
{
    public function test_sums_adjustments_and_taxes_correctly()
    {
        // Create a transaction with factory
        $tx = Transaction::factory()->create([
            'gross_sales' => 100.00,
            'vatable_sales' => 53.57,
            'vat_amount' => 6.43,
            'sc_vat_exempt_sales' => 0.00,
        ]);

        // Add multiple VAT rows to ensure summing works
        TransactionTax::create([
            'transaction_pk' => $tx->id,
            'tax_type' => 'VAT',
            'amount' => 1.23,
        ]);
        TransactionTax::create([
            'transaction_pk' => $tx->id,
            'tax_type' => 'VAT',
            'amount' => 5.20,
        ]);

        // Add VATABLE_SALES row
        TransactionTax::create([
            'transaction_pk' => $tx->id,
            'tax_type' => 'VATABLE_SALES',
            'amount' => 53.57,
        ]);

        // Add adjustments
        TransactionAdjustment::create([
            'transaction_pk' => $tx->id,
            'adjustment_type' => 'promo_discount',
            'amount' => 10.00,
        ]);
        TransactionAdjustment::create([
            'transaction_pk' => $tx->id,
            'adjustment_type' => 'senior_discount',
            'amount' => 0.00,
        ]);

        // Reload with relations
        $tx = Transaction::with(['adjustments','taxes'])->find($tx->id);

        $summary = TransactionSummaryPresenter::fromTransaction($tx);

    // VAT should be sum of 1.23 + 5.20 = 6.43 (presenter returns raw numeric)
    $this->assertEquals(6.43, $summary['vat_amount']);
    $this->assertEquals(53.57, $summary['vatable_sales']);
    $this->assertEquals(10.00, $summary['promo_amount']);
    // Senior discount exists but zero
    $this->assertEquals(0.00, $summary['senior_amount']);
    // PWD absent -> null
    $this->assertNull($summary['pwd_amount']);
    }

    public function test_absent_adjustments_and_taxes_return_nulls_and_formatted_amounts()
    {
        $tx = Transaction::factory()->create([
            'gross_sales' => 200.00,
            'vatable_sales' => 0.00,
            'vat_amount' => 0.00,
            'sc_vat_exempt_sales' => 0.00,
            'net_sales' => 200.00,
            'refund_amount' => 0.00,
        ]);

        // No adjustments or taxes added
        $tx = Transaction::with(['adjustments','taxes'])->find($tx->id);

        $summary = TransactionSummaryPresenter::fromTransaction($tx);

    $this->assertNull($summary['promo_amount']);
    $this->assertNull($summary['senior_amount']);
    $this->assertNull($summary['pwd_amount']);
    $this->assertNull($summary['vat_amount']);
    $this->assertNull($summary['vatable_sales']);
    $this->assertNull($summary['sc_vat_amount']);

    $this->assertEquals(200.00, $summary['gross']);
    $this->assertEquals(200.00, $summary['net']);
    $this->assertEquals(0.00, $summary['refund']);
    }
}
