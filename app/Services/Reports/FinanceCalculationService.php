<?php

namespace App\Services\Reports;

class FinanceCalculationService
{
    /**
     * Derive / format a standard metrics array from raw component values.
     *
     * The $components array is expected to contain the following keys (all
     * numeric, zero-defaulted):
     *   vatable_sales, sc_vat_exempt_sales, vat_amount,
     *   promo_with_approval, promo_without_approval,
     *   employee_discount, senior_discount, pwd_discount, vip_discount,
     *   other_tax, service_charge_distributed, service_charge_retained,
     *   regular_discount, gross_sales, net_sales
     *
     * $options (currently recognised):
     *   gross_sales_basis: 'pre_deduction' (default) — gross_sales value is
     *                      taken as-is from the component.
     *
     * @param  array  $components
     * @param  array  $options
     * @return array
     */
    public function deriveMetrics(array $components, array $options = []): array
    {
        $keys = [
            'vatable_sales',
            'sc_vat_exempt_sales',
            'vat_amount',
            'promo_with_approval',
            'promo_without_approval',
            'employee_discount',
            'senior_discount',
            'pwd_discount',
            'vip_discount',
            'other_tax',
            'service_charge_distributed',
            'service_charge_retained',
            'regular_discount',
            'gross_sales',
            'net_sales',
        ];

        $metrics = [];
        foreach ($keys as $key) {
            $metrics[$key] = round((float) ($components[$key] ?? 0), 2);
        }

        return $metrics;
    }
}
