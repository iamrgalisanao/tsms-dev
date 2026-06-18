<?php

namespace Tests\Unit;

use App\Services\Reports\FinanceCalculationService;
use Tests\TestCase;

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

    public function test_csmr_pre_deduction_gross_uses_visible_sum_when_it_reconciles(): void
    {
        $service = new FinanceCalculationService();

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
    }

    public function test_csmr_does_not_derive_vat_when_taxable_buckets_are_zero(): void
    {
        $service = new FinanceCalculationService();

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

        $service = new FinanceCalculationService();
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
    ) {
    }

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

    public function __construct(private array $rows)
    {
    }

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
