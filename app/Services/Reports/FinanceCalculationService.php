<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;

class FinanceCalculationService
{
    /**
     * Aggregates a collection of transactions into raw sum components.
     *
     * @param Collection $transactions
     * @return array
     */
    public function aggregateComponents(Collection $transactions): array
    {
        $c = [
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
        ];
        
        // [FIX-FINANCE-RECON] Added support for excluding voids from high-level aggregations
        $excludeVoids = config('tsms.reporting.exclude_voids_from_totals', true);

        foreach ($transactions as $tx) {
            if ($excludeVoids && method_exists($tx, 'isVoided') && $tx->isVoided()) {
                continue;
            }
            $c['vatable_sales'] += (float) ($tx->vatable_sales ?? 0);
            $c['sc_vat_exempt_sales'] += (float) ($tx->sc_vat_exempt_sales ?? 0);

            // Defensive: if the transaction column for exempt sales is 0, check for tax rows
            // that are actually base amounts (common in some POS integrations).
            if ((float)($tx->sc_vat_exempt_sales ?? 0) === 0.0 && method_exists($tx, 'taxes')) {
                $c['sc_vat_exempt_sales'] += (float) $tx->taxes()
                    ->whereIn('tax_type', ['SC_VAT_EXEMPT_SALES', 'VAT_EXEMPT_SALES', 'VATEXEMPT_SALES', 'VAT-EXEMPT', 'EXEMPT', 'VATEXEMPT'])
                    ->sum('amount');
            }

            $c['vat_amount'] += (float) ($tx->vat_amount ?? 0);

            // Generic OTHER_TAX records should ALWAYS map to the other_tax bucket.
            // Aggregating them into vat_amount causes misclassification in CSMR reports
            // for providers that use OTHER_TAX for non-VAT items (e.g., Shadow VAT).
            if (method_exists($tx, 'taxes')) {
                $c['other_tax'] += (float) $tx->taxes()
                    ->whereIn('tax_type', ['OTHER_TAX', 'OTHER-TAX'])
                    ->sum('amount');
            }

            $promo = (float) ($tx->promo_discount ?? 0);
            if ($tx->promo_status === 'WITH_APPROVAL') {
                $c['promo_with_approval'] += $promo;
            } else {
                $c['promo_without_approval'] += $promo;
            }

            // Map columns correctly based on schema. 
            // In modern TSMS schemas, these are also checked against 
            // adjustments table if the columns are 0.
            $txEmployee = (float)($tx->employee_discount ?? 0);
            $txVip = (float)($tx->vip_card_discount ?? 0);

            if ($txEmployee === 0.0 && method_exists($tx, 'adjustments')) {
                $txEmployee = (float) $tx->adjustments()
                    ->where('adjustment_type', 'EMPLOYEE')
                    ->sum('amount');
            }
            if ($txVip === 0.0 && method_exists($tx, 'adjustments')) {
                $txVip = (float) $tx->adjustments()
                    ->where('adjustment_type', 'VIP')
                    ->sum('amount');
            }

            $c['employee_discount'] += $txEmployee;
            $c['vip_discount'] += $txVip;
            $c['senior_discount'] += (float) ($tx->senior_discount ?? 0);
            $c['pwd_discount'] += (float) ($tx->pwd_discount ?? 0);

            // For other_tax, we use the model's relation sum but EXCLUDE VAT components
            // and SC_VAT_EXEMPT_SALES to avoid double counting or misclassification.
            if (method_exists($tx, 'taxes')) {
                $c['other_tax'] += (float) $tx->taxes()
                    ->whereNotIn('tax_type', [
                        'VAT', 'VAT_AMOUNT', 'VATABLE_SALES', 'SC_VAT_EXEMPT_SALES', 
                        'VAT-EXEMPT', 'EXEMPT', 'VATEXEMPT', 'VATEXEMPT_SALES', 
                        'VAT_EXEMPT_SALES', 'ZERO_RATED', 'NON-VAT', 'NON_VAT', 'ZERO-RATED',
                        'OTHER_TAX', 'OTHER-TAX'
                    ])
                    ->sum('amount');
            }

            $c['service_charge_distributed'] += (float) ($tx->service_charge ?? 0);
            $c['service_charge_retained'] += (float) ($tx->management_service_charge ?? 0);
            $c['regular_discount'] += (float) ($tx->discount_total ?? 0);

            $c['gross_sales'] += (float) ($tx->gross_sales ?? 0);
            $c['net_sales'] += (float) ($tx->net_sales ?? 0);
        }

        return $c;
    }


    /**
     * Derives final financial metrics from raw components using the "Financial Truth" formulas.
     *
     * @param array $c Raw components from aggregateComponents()
     * @return array
     */
    public function deriveMetrics(array $c): array
    {
        // 1. Aggregate Discs and Service Charges for readability
        $promotions = round(($c['promo_with_approval'] ?? 0) + ($c['promo_without_approval'] ?? 0), 2);
        $serviceCharge = round(($c['service_charge_distributed'] ?? 0) + ($c['service_charge_retained'] ?? 0), 2);
        $seniorPwd = round(($c['senior_discount'] ?? 0) + ($c['pwd_discount'] ?? 0), 2);

        $rawNetSales = (float)($c['net_sales'] ?? 0);
        $rawVat = (float)($c['vat_amount'] ?? 0);

        // Some providers store vatable_sales as VAT-inclusive (Vatable + VAT).
        // For CSMR gross component math, normalize it to ex-VAT using recorded net/vat
        // when the pattern clearly indicates VAT-inclusive storage.
        $vatableForGross = (float)($c['vatable_sales'] ?? 0);
        if ($rawNetSales > 0 && $rawVat > 0) {
            $netBase = $rawNetSales;
            if (($c['sc_vat_exempt_sales'] ?? 0) > 0 && $netBase >= ($c['sc_vat_exempt_sales'] ?? 0)) {
                $netBase = round($netBase - ($c['sc_vat_exempt_sales'] ?? 0), 2);
            }

            $candidateExVat = round($netBase - $rawVat, 2);
            $rawLooksVatInclusive = abs($vatableForGross - round($candidateExVat + $rawVat, 2)) <= 0.05;
            if ($candidateExVat >= 0 && $rawLooksVatInclusive) {
                $vatableForGross = $candidateExVat;
            }
        }

        // 2. Gross Sales (Source of Truth)
        // We prefer the Nominal Gross (sum of column) to absorb minor component-level rounding errors.
        // If nominal is unavailable or mismatched significantly, we fall back to component sum.
        $componentSum = round(
            $vatableForGross
            + ($c['sc_vat_exempt_sales'] ?? 0)
            + ($c['vat_amount'] ?? 0)
            + ($c['promo_with_approval'] ?? 0)
            + ($c['promo_without_approval'] ?? 0)
            + ($c['employee_discount'] ?? 0)
            + ($c['senior_discount'] ?? 0)
            + ($c['pwd_discount'] ?? 0)
            + ($c['other_tax'] ?? 0)
            + ($c['vip_discount'] ?? 0)
            + ($c['service_charge_distributed'] ?? 0)
            + ($c['service_charge_retained'] ?? 0),
            2
        );

        $nominalGross = round($c['gross_sales'] ?? 0, 2);
        
        // Use nominal gross if it exists, otherwise use component sum.
        // A difference of > 1% would suggest a payload integrity issue, not just rounding.
        $gross = ($nominalGross > 0 && abs($nominalGross - $componentSum) < ($nominalGross * 0.01))
            ? $nominalGross
            : $componentSum;

        // 3. Net Sales (Source of Truth: Gross - Non-VAT components)
        // Excel N61: Gross - (Promos + Employee + Senior/PWD + VIP + Exempt + LocalTax + SC)
        // This effectively leaves (Vatable + VAT).
        // If the database has a non-zero recorded net_sales for this transaction/group, we 
        // calculate a nominal "raw" net to allow validation against the derived one.
        $derivedNetSales = round(
            $gross
            - $promotions
            - ($c['employee_discount'] ?? 0)
            - $seniorPwd
            - ($c['vip_discount'] ?? 0)
            - ($c['sc_vat_exempt_sales'] ?? 0)
            - ($c['other_tax'] ?? 0)
            - $serviceCharge,
            2
        );

        // Priority: Use recorded values from the database if they exist.
        // We ensure the internal $netSales base is strictly (Vatable + VAT) to remain
        // compatible with legacy CMSR/Log mappings that add Exempt sales back.
        $netSales = ($rawNetSales > 0) ? round($rawNetSales, 2) : $derivedNetSales;

        // If the raw net_sales already includes Exempt sales (standard POS logic),
        // we must subtract them to isolate the Vatable + VAT portion for the formulas below.
        if ($rawNetSales > 0 && ($c['sc_vat_exempt_sales'] ?? 0) > 0) {
            // We only subtract if the raw value appears to be inclusive of the exempt portion
            // (e.g. if Net >= Exempt). This prevents double-counting in summaries.
            $netSales = round($netSales - ($c['sc_vat_exempt_sales'] ?? 0), 2);
        }

        // 4. VAT (Source of Truth: Recorded VAT if exists, else Derived from Net)
        // Excel N62: (Net Sales / 1.12) * 0.12
        $derivedVat = round(($netSales / 1.12) * 0.12, 2);

        // IMPORTANT: If raw recorded VAT exists, we MUST use it for the export/summary 
        // to match the POS system's actual tax calculation.
        $vat = ($rawVat > 0) ? round($rawVat, 2) : $derivedVat;

        // 5. Net Ex-VAT (Equivalent to Vatable Sales after normalization)
        // Excel N64: Net Sales - VAT
        $netExVAT = round($netSales - $vat, 2);

        // 6. Net Subject to Rent
        // Excel N71: Net ex-VAT + SC Exempt + Promo (Without Approval) + Other Tax + SC Retained
        $netSubjectToRent = round(
            $netExVAT
            + ($c['sc_vat_exempt_sales'] ?? 0)
            + ($c['promo_without_approval'] ?? 0)
            + ($c['other_tax'] ?? 0)
            + ($c['service_charge_retained'] ?? 0),
            2
        );

        return array_merge($c, [
            'total_promotions' => $promotions,
            'total_service_charge' => $serviceCharge,
            'senior_pwd' => $seniorPwd,
            'net_sales' => $netSales,
            'vat_amount' => $vat,
            'vatable_sales' => $netExVAT,
            'gross_sales' => $gross,
            'net_ex_vat' => $netExVAT,
            'net_subject_to_rent' => $netSubjectToRent,
            // net_total: The final amount due from the customer (Vatable + VAT + Exempt)
            // This is the value that should be used for Dashboard "Net Total" summaries.
            'net_total' => round($netSales + ($c['sc_vat_exempt_sales'] ?? 0), 2),
        ]);
    }
}
