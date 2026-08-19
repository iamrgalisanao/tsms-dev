<?php

namespace Tests\Unit;

use App\Services\Reports\FinanceCalculationService;
use Tests\TestCase;

class FinanceCalculationServiceTest extends TestCase
{
    public function test_csmr_normalizes_vat_inclusive_vatable_sales(): void
    {
        $service = new FinanceCalculationService;

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

    /**
     * Regression for docs/DEFECT_FINANCE_VATABLE_NORMALIZATION.md.
     *
     * Both fixtures are real staging aggregates for tenant 106 (2026-08-15 and
     * 2026-08-17) reported on the same VAT-inclusive convention. They are not
     * proportional to each other, and their accumulated per-receipt rounding
     * differs by orders of magnitude: the ratio sits 0.0000303 from the inclusive
     * anchor on 08-15 versus 0.0000000 on 08-17.
     *
     * Under the previous absolute "<= 1.00 pesos" gate that difference decided the
     * outcome -- instrumented against the old implementation, 08-15 was left
     * VAT-inclusive at 309,611.40 while 08-17 was normalized to 261,636.90. Same
     * tenant, same convention, two days apart, two different bases.
     */
    public function test_vatable_normalization_is_stable_across_differing_rounding_residue(): void
    {
        $service = new FinanceCalculationService;

        $highResidue = $service->deriveMetrics($this->components([
            'vatable_sales' => 309611.40,
            'vat_amount' => 33182.03,
            'sc_vat_exempt_sales' => 20000.00,
            'senior_discount' => 2000.00,
            'pwd_discount' => 1524.85,
            'net_sales' => 329611.40,
            'gross_sales' => 333136.25,
        ]), ['gross_sales_basis' => 'pre_deduction']);

        $lowResidue = $service->deriveMetrics($this->components([
            'vatable_sales' => 293033.33,
            'vat_amount' => 31396.43,
            'sc_vat_exempt_sales' => 25000.00,
            'senior_discount' => 2500.00,
            'pwd_discount' => 1905.98,
            'net_sales' => 318033.33,
            'gross_sales' => 322439.31,
        ]), ['gross_sales_basis' => 'pre_deduction']);

        $this->assertSame(
            'normalized_from_inclusive',
            $highResidue['vatable_sales_basis'],
            'the high-residue day previously fell off the absolute gate and stayed VAT-inclusive'
        );
        $this->assertSame('normalized_from_inclusive', $lowResidue['vatable_sales_basis']);

        $this->assertEqualsWithDelta(276438.75, $highResidue['vatable_sales'], 0.001);
        $this->assertEqualsWithDelta(261636.90, $lowResidue['vatable_sales'], 0.001);
    }

    /**
     * The ratio test alone cannot tell a genuinely VAT-inclusive base from a
     * VAT-exclusive base diluted by exempt or zero-rated content: an exempt share
     * between roughly 5.7% and 15.7% inside the vatable column produces the same
     * ratio as a VAT-inclusive base. Without corroboration from gross, such a base
     * would be silently written down by 10.71%.
     *
     * Here 300,000.00 is genuinely VAT-exclusive with 14% exempt content, so VAT is
     * only 30,960.00. The gross does not show the double-count, so the value must be
     * left alone.
     */
    public function test_csmr_does_not_normalize_vat_inclusive_ratio_without_gross_corroboration(): void
    {
        $service = new FinanceCalculationService;

        $metrics = $service->deriveMetrics($this->components([
            'vatable_sales' => 300000.00,
            'vat_amount' => 30960.00,
            'net_sales' => 300000.00,
            'gross_sales' => 330960.00,
        ]), ['gross_sales_basis' => 'pre_deduction']);

        $this->assertSame('uncorroborated', $metrics['vatable_sales_basis']);
        $this->assertEqualsWithDelta(300000.00, $metrics['vatable_sales'], 0.001);
    }

    public function test_csmr_leaves_already_vat_exclusive_vatable_sales_untouched(): void
    {
        $service = new FinanceCalculationService;

        $metrics = $service->deriveMetrics($this->components([
            'vatable_sales' => 83846.90,
            'vat_amount' => 10061.63,
            'net_sales' => 93908.53,
            'gross_sales' => 93908.53,
        ]), ['gross_sales_basis' => 'pre_deduction']);

        $this->assertSame('exclusive', $metrics['vatable_sales_basis']);
        $this->assertEqualsWithDelta(83846.90, $metrics['vatable_sales'], 0.001);
    }

    public function test_csmr_leaves_vatable_sales_untouched_when_ratio_matches_neither_basis(): void
    {
        $service = new FinanceCalculationService;

        $metrics = $service->deriveMetrics($this->components([
            'vatable_sales' => 1000.00,
            'vat_amount' => 50.00,
            'net_sales' => 1050.00,
            'gross_sales' => 1050.00,
        ]), ['gross_sales_basis' => 'pre_deduction']);

        $this->assertSame('unmatched', $metrics['vatable_sales_basis']);
        $this->assertEqualsWithDelta(1000.00, $metrics['vatable_sales'], 0.001);
    }

    public function test_csmr_does_not_classify_a_taxable_base_reported_without_vat(): void
    {
        $service = new FinanceCalculationService;

        $metrics = $service->deriveMetrics($this->components([
            'vatable_sales' => 5000.00,
            'vat_amount' => 0.0,
            'net_sales' => 5000.00,
            'gross_sales' => 5000.00,
        ]), ['gross_sales_basis' => 'pre_deduction']);

        $this->assertSame('not_applicable', $metrics['vatable_sales_basis']);
        $this->assertEqualsWithDelta(5000.00, $metrics['vatable_sales'], 0.001);
    }

    /**
     * The VAT-exclusive normalization is scoped to `vatable_sales` only. `net_ex_vat`
     * feeds `net_subject_to_rent`, which is the percentage-rent basis billed to
     * tenants (surfaced as `net_sales_percentage_rent` in HourlyReportService and
     * cell N71 of the finance export), so it must not move as a side effect.
     *
     * Values pinned here were captured from the implementation as it stood before
     * the normalization was introduced.
     */
    public function test_vatable_normalization_does_not_move_percentage_rent_basis(): void
    {
        $service = new FinanceCalculationService;

        $metrics = $service->deriveMetrics($this->components([
            'vatable_sales' => 309611.40,
            'vat_amount' => 33182.03,
            'sc_vat_exempt_sales' => 20000.00,
            'senior_discount' => 2000.00,
            'pwd_discount' => 1524.85,
            'net_sales' => 329611.40,
            'gross_sales' => 333136.25,
        ]), ['gross_sales_basis' => 'pre_deduction']);

        $this->assertSame('normalized_from_inclusive', $metrics['vatable_sales_basis']);
        $this->assertEqualsWithDelta(276429.37, $metrics['net_ex_vat'], 0.001);
        $this->assertEqualsWithDelta(296429.37, $metrics['net_subject_to_rent'], 0.001);
    }

    private function components(array $overrides = []): array
    {
        return array_merge([
            'vatable_sales' => 0.0,
            'sc_vat_exempt_sales' => 0.0,
            'vat_amount' => 0.0,
            'promo_with_approval' => 0.0,
            'promo_without_approval' => 0.0,
            'employee_discount' => 0.0,
            'senior_discount' => 0.0,
            'pwd_discount' => 0.0,
            'vip_discount' => 0.0,
            'other_tax' => 0.0,
            'service_charge_distributed' => 0.0,
            'service_charge_retained' => 0.0,
            'regular_discount' => 0.0,
            'gross_sales' => 0.0,
            'net_sales' => 0.0,
        ], $overrides);
    }

    public function test_csmr_pre_deduction_gross_preserves_raw_pos_gross_and_exposes_reconciliation(): void
    {
        $service = new FinanceCalculationService;

        $metrics = $service->deriveMetrics([
            'vatable_sales' => 81133.59,
            'sc_vat_exempt_sales' => 3936.03,
            'vat_amount' => 8692.94,
            'promo_with_approval' => 0,
            'promo_without_approval' => 0,
            'employee_discount' => 0,
            'senior_discount' => 430.86,
            'pwd_discount' => 553.12,
            'vip_discount' => 0,
            'other_tax' => 0,
            'service_charge_distributed' => 0,
            'service_charge_retained' => 0,
            'regular_discount' => 0,
            'gross_sales' => 94746.54,
            'net_sales' => 85069.62,
        ], ['gross_sales_basis' => 'pre_deduction']);

        $this->assertEqualsWithDelta(94746.54, $metrics['gross_sales'], 0.001);
        $this->assertEqualsWithDelta(94746.54, $metrics['raw_gross_sales'], 0.001);
        $this->assertEqualsWithDelta(94746.54, $metrics['computed_gross_sales'], 0.001);
        $this->assertEqualsWithDelta(0.0, $metrics['gross_sales_variance'], 0.001);
    }

    public function test_csmr_gross_uses_raw_pos_value_when_component_formula_differs(): void
    {
        $service = new FinanceCalculationService;

        $metrics = $service->deriveMetrics([
            'vatable_sales' => 83846.90,
            'sc_vat_exempt_sales' => 4758.78,
            'vat_amount' => 10061.63,
            'promo_with_approval' => 0,
            'promo_without_approval' => 0,
            'employee_discount' => 940.00,
            'senior_discount' => 235.69,
            'pwd_discount' => 716.05,
            'vip_discount' => 0,
            'other_tax' => 1515.28,
            'service_charge_distributed' => 0,
            'service_charge_retained' => 0,
            'regular_discount' => 0,
            'gross_sales' => 98663.86,
            'net_sales' => 99149.26,
        ], ['gross_sales_basis' => 'pre_deduction']);

        $this->assertEqualsWithDelta(98663.86, $metrics['gross_sales'], 0.001);
        $this->assertEqualsWithDelta(102074.33, $metrics['computed_gross_sales'], 0.001);
        $this->assertEqualsWithDelta(3410.47, $metrics['gross_sales_variance'], 0.001);
    }

    public function test_csmr_does_not_derive_vat_when_taxable_buckets_are_zero(): void
    {
        $service = new FinanceCalculationService;

        $metrics = $service->deriveMetrics([
            'vatable_sales' => 0.0,
            'sc_vat_exempt_sales' => 0.0,
            'vat_amount' => 0.0,
            'promo_with_approval' => 0.0,
            'promo_without_approval' => 0.0,
            'employee_discount' => 0.0,
            'senior_discount' => 0.0,
            'pwd_discount' => 0.0,
            'vip_discount' => 0.0,
            'other_tax' => 0.0,
            'service_charge_distributed' => 0.0,
            'service_charge_retained' => 0.0,
            'regular_discount' => 0.0,
            'gross_sales' => 45255.0,
            'net_sales' => 45255.0,
        ], ['gross_sales_basis' => 'pre_deduction']);

        $this->assertSame(0.0, $metrics['vat_amount']);
        $this->assertSame(0.0, $metrics['vatable_sales']);
        $this->assertSame(45255.0, $metrics['sc_vat_exempt_sales']);
        $this->assertSame(45255.0, $metrics['gross_sales']);
        $this->assertSame(45255.0, $metrics['net_total']);
        $this->assertSame(0.0, $metrics['net_ex_vat']);
    }

    public function test_aggregate_components_maps_related_taxes_adjustments_and_excludes_voids(): void
    {
        config(['tsms.reporting.exclude_voids_from_totals' => true]);

        $service = new FinanceCalculationService;
        $transactions = collect([
            new FinanceCalculationTransactionFake(
                [
                    'vatable_sales' => 100.0,
                    'sc_vat_exempt_sales' => 0.0,
                    'vat_amount' => 12.0,
                    'promo_discount' => 5.0,
                    'promo_status' => 'WITH_APPROVAL',
                    'senior_discount' => 2.0,
                    'pwd_discount' => 3.0,
                    'service_charge' => 4.0,
                    'management_service_charge' => 6.0,
                    'discount_total' => 8.0,
                    'gross_sales' => 132.0,
                    'net_sales' => 112.0,
                ],
                [
                    ['tax_type' => 'VAT_EXEMPT_SALES', 'amount' => 20.0],
                    ['tax_type' => 'OTHER_TAX', 'amount' => 7.0],
                    ['tax_type' => 'LOCAL_TAX', 'amount' => 9.0],
                    ['tax_type' => 'VAT', 'amount' => 12.0],
                ],
                [
                    ['adjustment_type' => 'employee_discount', 'amount' => 11.0],
                    ['adjustment_type' => 'VIP', 'amount' => 13.0],
                ],
            ),
            new FinanceCalculationTransactionFake([
                'vatable_sales' => 999.0,
                'gross_sales' => 999.0,
                'net_sales' => 999.0,
            ], [], [], true),
        ]);

        $components = $service->aggregateComponents($transactions);

        $this->assertEqualsWithDelta(100.0, $components['vatable_sales'], 0.001);
        $this->assertEqualsWithDelta(20.0, $components['sc_vat_exempt_sales'], 0.001);
        $this->assertEqualsWithDelta(12.0, $components['vat_amount'], 0.001);
        $this->assertEqualsWithDelta(16.0, $components['other_tax'], 0.001);
        $this->assertEqualsWithDelta(11.0, $components['employee_discount'], 0.001);
        $this->assertEqualsWithDelta(13.0, $components['vip_discount'], 0.001);
        $this->assertEqualsWithDelta(5.0, $components['promo_with_approval'], 0.001);
        $this->assertEqualsWithDelta(0.0, $components['promo_without_approval'], 0.001);
        $this->assertEqualsWithDelta(132.0, $components['gross_sales'], 0.001);
    }
}

class FinanceCalculationTransactionFake
{
    public function __construct(
        private array $attributes,
        private array $taxRows,
        private array $adjustmentRows,
        private bool $voided = false,
    ) {}

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function isVoided(): bool
    {
        return $this->voided;
    }

    public function taxes(): FinanceCalculationRelationFake
    {
        return new FinanceCalculationRelationFake($this->taxRows);
    }

    public function adjustments(): FinanceCalculationRelationFake
    {
        return new FinanceCalculationRelationFake($this->adjustmentRows);
    }
}

class FinanceCalculationRelationFake
{
    private ?array $includedTypes = null;

    private ?array $excludedTypes = null;

    private ?string $typeColumn = null;

    public function __construct(private array $rows) {}

    public function whereIn(string $column, array $types): self
    {
        $this->typeColumn = $column;
        $this->includedTypes = $types;

        return $this;
    }

    public function whereNotIn(string $column, array $types): self
    {
        $this->typeColumn = $column;
        $this->excludedTypes = $types;

        return $this;
    }

    public function sum(string $column): float
    {
        return (float) collect($this->rows)
            ->filter(function (array $row): bool {
                if ($this->includedTypes !== null) {
                    return in_array($row[$this->typeColumn] ?? null, $this->includedTypes, true);
                }

                if ($this->excludedTypes !== null) {
                    return ! in_array($row[$this->typeColumn] ?? null, $this->excludedTypes, true);
                }

                return true;
            })
            ->sum($column);
    }
}
