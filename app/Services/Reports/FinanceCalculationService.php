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
            'gross_sales' => 0.0,
        ];

        foreach ($transactions as $tx) {
            $c['vatable_sales'] += (float) ($tx->vatable_sales ?? 0);
            $c['sc_vat_exempt_sales'] += (float) ($tx->sc_vat_exempt_sales ?? 0);
            $c['vat_amount'] += (float) ($tx->vat_amount ?? 0);

            $promo = (float) ($tx->promo_discount ?? 0);
            if ($tx->promo_status === 'WITH_APPROVAL') {
                $c['promo_with_approval'] += $promo;
            } else {
                $c['promo_without_approval'] += $promo;
            }

            // Map columns correctly based on schema
            $c['employee_discount'] += (float) ($tx->employee_discount ?? 0);
            $c['senior_discount'] += (float) ($tx->senior_discount ?? 0);
            $c['pwd_discount'] += (float) ($tx->pwd_discount ?? 0);
            $c['vip_discount'] += (float) ($tx->vip_card_discount ?? 0);

            // For other_tax, we use the model's relation sum but EXCLUDE VAT components
            // and SC_VAT_EXEMPT_SALES to avoid double counting or misclassification.
            if (method_exists($tx, 'taxes')) {
                $c['other_tax'] += (float) $tx->taxes()
                    ->whereNotIn('tax_type', ['VAT', 'VAT_AMOUNT', 'VATABLE_SALES', 'SC_VAT_EXEMPT_SALES'])
                    ->sum('amount');
            }

            $c['service_charge_distributed'] += (float) ($tx->service_charge ?? 0);
            $c['service_charge_retained'] += (float) ($tx->management_service_charge ?? 0);

            $c['gross_sales'] += (float) ($tx->gross_sales ?? 0);
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
        // 1. Calculations following Finance Standards
        $promotions = round(($c['promo_with_approval'] ?? 0) + ($c['promo_without_approval'] ?? 0), 2);
        $serviceCharge = round(($c['service_charge_distributed'] ?? 0) + ($c['service_charge_retained'] ?? 0), 2);

        // 2. Recalculated Gross Sales
        // Gross = (Vatable + Recalculated VAT) + Exempt + Senior + PWD + Promotions + ServiceCharge + OtherTax
        // But first we need Net Sales to get the canonical VAT

        // Approximate Gross for first pass
        $approxGross = round(
            ($c['vatable_sales'] ?? 0)
            + ($c['sc_vat_exempt_sales'] ?? 0)
            + ($c['senior_discount'] ?? 0)
            + ($c['pwd_discount'] ?? 0)
            + $promotions
            + $serviceCharge
            + ($c['other_tax'] ?? 0),
            2
        );

        // 3. Net Sales
        // Net = Gross - Senior - PWD - Promotions - Employee - OtherTax - ServiceCharge - Exempt
        $netSales = round(
            $approxGross
            - ($c['senior_discount'] ?? 0)
            - ($c['pwd_discount'] ?? 0)
            - $promotions
            - ($c['employee_discount'] ?? 0)
            - ($c['other_tax'] ?? 0)
            - $serviceCharge
            - ($c['sc_vat_exempt_sales'] ?? 0),
            2
        );

        // 4. VAT (Derived from Net)
        // VAT = (Net Sales / 1.12) * 0.12
        $vat = round(($netSales / 1.12) * 0.12, 2);

        // 5. Final Recalculated Gross
        $gross = round(
            ($c['vatable_sales'] ?? 0) + $vat
            + ($c['sc_vat_exempt_sales'] ?? 0)
            + ($c['senior_discount'] ?? 0)
            + ($c['pwd_discount'] ?? 0)
            + $promotions
            + $serviceCharge
            + ($c['other_tax'] ?? 0),
            2
        );

        // 6. Net Subject to Rent
        // Final Net = (Net Sales - VAT) + VAT Exempt + Promo (Without Approval) + Other Tax + SC Retained
        $netExVAT = round($netSales - $vat, 2);
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
            'net_sales' => $netSales,
            'vat_amount' => $vat,
            'gross_sales' => $gross,
            'net_ex_vat' => $netExVAT,
            'net_subject_to_rent' => $netSubjectToRent,
            // Senior/PWD combined for UI Less section
            'senior_pwd' => round(($c['senior_discount'] ?? 0) + ($c['pwd_discount'] ?? 0), 2),
        ]);
    }
}
