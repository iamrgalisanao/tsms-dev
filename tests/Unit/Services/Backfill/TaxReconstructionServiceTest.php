<?php

namespace Tests\Unit\Services\Backfill;

use App\Models\Transaction;
use App\Services\Backfill\TaxReconstructionService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 1 (T009).
 *
 * Covers TaxReconstructionService's pure reconstruction of candidate
 * transaction_taxes rows from Transaction::original_payload, and its
 * cross-check against the vatable_sales / vat_amount / sc_vat_exempt_sales
 * columns using applyTaxColumns()'s historical last-wins semantics (T096).
 *
 * All transactions here are unsaved (`new Transaction([...])`) — the service
 * only reads model attributes, so no DB writes are needed to exercise it.
 */
class TaxReconstructionServiceTest extends TestCase
{
    private TaxReconstructionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TaxReconstructionService;
    }

    private function makeTransaction(array $attributes = []): Transaction
    {
        return new Transaction(array_merge([
            'tenant_id' => 1,
            'terminal_id' => 1,
            'transaction_id' => 'TXN-TEST-1',
            'hardware_id' => 'HW-1',
            'transaction_timestamp' => now(),
            'gross_sales' => 1000,
            'customer_code' => 'TEST001',
            'payload_checksum' => md5('test'),
        ], $attributes));
    }

    public function test_reconstructs_multiple_valid_tax_rows_from_well_formed_payload(): void
    {
        $payload = [
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => 120.50],
                ['tax_type' => 'VATABLE_SALES', 'amount' => 1000.25],
            ],
        ];

        $transaction = $this->makeTransaction([
            'original_payload' => json_encode($payload),
        ]);

        $rows = $this->service->reconstructTaxRows($transaction);

        $this->assertCount(2, $rows);
        $this->assertSame('VAT', $rows[0]['tax_type']);
        $this->assertSame(120.50, $rows[0]['amount']);
        $this->assertSame('VATABLE_SALES', $rows[1]['tax_type']);
        $this->assertSame(1000.25, $rows[1]['amount']);
    }

    public function test_payload_missing_taxes_key_returns_empty_result_without_exception(): void
    {
        $transaction = $this->makeTransaction([
            'original_payload' => json_encode(['tenant_id' => 1, 'gross_sales' => 1000]),
        ]);

        $rows = $this->service->reconstructTaxRows($transaction);

        $this->assertSame([], $rows);
    }

    public function test_malformed_non_json_payload_returns_empty_result_without_exception(): void
    {
        Log::shouldReceive('warning')->once();

        $transaction = $this->makeTransaction([
            'original_payload' => '{this is not valid json',
        ]);

        $rows = $this->service->reconstructTaxRows($transaction);

        $this->assertSame([], $rows);
    }

    public function test_missing_original_payload_returns_empty_result_without_exception(): void
    {
        $transaction = $this->makeTransaction([
            'original_payload' => null,
        ]);

        $rows = $this->service->reconstructTaxRows($transaction);

        $this->assertSame([], $rows);
    }

    public function test_tax_row_with_empty_tax_type_is_skipped_and_logged(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('TaxReconstructionService: Skipping malformed tax row', \Mockery::on(function ($context) {
                return $context['row']['tax_type'] === '';
            }));

        $payload = [
            'taxes' => [
                ['tax_type' => '', 'amount' => 50.00],
                ['tax_type' => 'VAT', 'amount' => 120.00],
            ],
        ];

        $transaction = $this->makeTransaction([
            'original_payload' => json_encode($payload),
        ]);

        $rows = $this->service->reconstructTaxRows($transaction);

        $this->assertCount(1, $rows);
        $this->assertSame('VAT', $rows[0]['tax_type']);
    }

    public function test_tax_row_with_null_amount_is_skipped_and_logged(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('TaxReconstructionService: Skipping malformed tax row', \Mockery::on(function ($context) {
                return $context['row']['tax_type'] === 'VAT_EXEMPT_SALES';
            }));

        $payload = [
            'taxes' => [
                ['tax_type' => 'VAT_EXEMPT_SALES', 'amount' => null],
                ['tax_type' => 'VAT', 'amount' => 120.00],
            ],
        ];

        $transaction = $this->makeTransaction([
            'original_payload' => json_encode($payload),
        ]);

        $rows = $this->service->reconstructTaxRows($transaction);

        $this->assertCount(1, $rows);
        $this->assertSame('VAT', $rows[0]['tax_type']);
    }

    public function test_cross_check_reports_mismatch_when_stored_vat_amount_disagrees(): void
    {
        $reconstructed = [
            ['tax_type' => 'VAT', 'amount' => 120.00],
        ];

        $transaction = $this->makeTransaction([
            'vat_amount' => 999.00,
        ]);

        $mismatches = $this->service->crossCheck($transaction, $reconstructed);

        $this->assertContains('vat_amount', $mismatches);
    }

    public function test_cross_check_reports_no_mismatch_when_columns_agree(): void
    {
        $reconstructed = [
            ['tax_type' => 'VAT', 'amount' => 120.00],
            ['tax_type' => 'VATABLE_SALES', 'amount' => 1000.00],
            ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 50.00],
        ];

        $transaction = $this->makeTransaction([
            'vat_amount' => 120.00,
            'vatable_sales' => 1000.00,
            'sc_vat_exempt_sales' => 50.00,
        ]);

        $mismatches = $this->service->crossCheck($transaction, $reconstructed);

        $this->assertSame([], $mismatches);
    }

    public function test_payload_with_more_than_four_tax_rows_returns_all_valid_rows(): void
    {
        $payload = [
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => 10.00],
                ['tax_type' => 'VATABLE_SALES', 'amount' => 20.00],
                ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 30.00],
                ['tax_type' => 'LOCAL_TAX', 'amount' => 40.00],
                ['tax_type' => 'OTHER_TAX', 'amount' => 50.00],
            ],
        ];

        $transaction = $this->makeTransaction([
            'original_payload' => json_encode($payload),
        ]);

        $rows = $this->service->reconstructTaxRows($transaction);

        $this->assertCount(5, $rows);
    }

    public function test_cross_check_uses_last_wins_not_sum_for_duplicate_tax_type_rows(): void
    {
        // Two VAT rows with different amounts, with the LARGER amount listed
        // FIRST and the smaller one last. applyTaxColumns() overwrites (not
        // sums) as it iterates, so the last row in array order (50.00) wins —
        // deliberately not the largest (80.00) and not the sum (130.00) — so
        // this proves order-based last-wins rather than a hypothetical
        // max()-based selection that would coincidentally also land on 80.00.
        $reconstructed = [
            ['tax_type' => 'VAT', 'amount' => 80.00],
            ['tax_type' => 'VAT', 'amount' => 50.00],
        ];

        $lastWinsTransaction = $this->makeTransaction([
            'vat_amount' => 50.00,
        ]);
        $this->assertSame(
            [],
            $this->service->crossCheck($lastWinsTransaction, $reconstructed),
            'Expected no mismatch when stored vat_amount reflects last-wins (50.00), not the max (80.00) or the sum (130.00).'
        );

        $summedTransaction = $this->makeTransaction([
            'vat_amount' => 130.00,
        ]);
        $this->assertContains(
            'vat_amount',
            $this->service->crossCheck($summedTransaction, $reconstructed),
            'A stored vat_amount equal to the sum of both rows must be flagged as a mismatch against last-wins semantics.'
        );
    }
}
