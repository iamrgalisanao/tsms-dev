<?php

namespace App\Services\Reports;

class FinanceCalculationService
{
    /**
     * Derives final financial metrics from raw CSMR components.
     *
     * CSMR uses visible-column math rather than simply echoing raw payload
     * fields. Some providers submit VATABLE_SALES as VAT-inclusive; this
     * normalizes those rows so the report matches the Z-reading layout:
     * vatable sales ex-VAT, VAT in its own column, and gross reconstructed
     * from the visible CSMR components.
     */
    public function deriveMetrics(array $c, array $options = []): array
    {
        $promotions = round(($c['promo_with_approval'] ?? 0) + ($c['promo_without_approval'] ?? 0), 2);
        $serviceCharge = round(($c['service_charge_distributed'] ?? 0) + ($c['service_charge_retained'] ?? 0), 2);
        $seniorPwd = round(($c['senior_discount'] ?? 0) + ($c['pwd_discount'] ?? 0), 2);

        $rawNetSales = (float) ($c['net_sales'] ?? 0);
        $rawVat = (float) ($c['vat_amount'] ?? 0);
        $rawScVatExempt = (float) ($c['sc_vat_exempt_sales'] ?? 0);

        $vatableForGross = (float) ($c['vatable_sales'] ?? 0);
        $capturedVatableIncludesVatLessSeniorPwd = false;

        if ($rawNetSales > 0 && $rawVat > 0) {
            if (abs($vatableForGross - $rawNetSales) <= 0.05) {
                $vatableForGross = round($vatableForGross - $rawVat, 2);
            }

            $discountAdjustedExVat = round($vatableForGross - $rawVat + $seniorPwd, 2);
            if (
                $discountAdjustedExVat >= 0
                && abs($rawVat - round($discountAdjustedExVat * 0.12, 2)) <= 0.10
                && abs($rawVat - round($vatableForGross * 0.12, 2)) > 0.10
            ) {
                $vatableForGross = $discountAdjustedExVat;
                $capturedVatableIncludesVatLessSeniorPwd = true;
            }

            $netBase = $rawNetSales;
            if ($rawScVatExempt > 0 && $netBase >= $rawScVatExempt) {
                $netBase = round($netBase - $rawScVatExempt, 2);
            }

            $candidateExVat = round($netBase - $rawVat, 2);
            $rawLooksVatInclusive = abs($vatableForGross - round($candidateExVat + $rawVat, 2)) <= 0.05;
            if ($candidateExVat >= 0 && $rawLooksVatInclusive) {
                $alreadyExVat = abs($rawVat - round($vatableForGross * 0.12, 2)) <= 0.10;
                if (! $alreadyExVat) {
                    $vatableForGross = $candidateExVat;
                }
            }
        }

        $nominalGross = round($c['gross_sales'] ?? 0, 2);
        $grossBasis = $options['gross_sales_basis'] ?? 'raw';
        $componentSum = round(
            $vatableForGross
            + $rawScVatExempt
            + $rawVat
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

        $gross = $grossBasis === 'pre_deduction'
            ? $componentSum
            : ($nominalGross > 0 ? $nominalGross : $componentSum);

        $derivedNetSales = round(
            $gross
            - $promotions
            - ($c['employee_discount'] ?? 0)
            - $seniorPwd
            - ($c['vip_discount'] ?? 0)
            - $rawScVatExempt
            - ($c['other_tax'] ?? 0)
            - $serviceCharge,
            2
        );

        $netSales = ($rawNetSales > 0) ? round($rawNetSales, 2) : $derivedNetSales;

        if ($rawNetSales > 0 && $rawScVatExempt > 0) {
            $netSales = round($netSales - $rawScVatExempt, 2);

            $rawNetSalesIncludesVat = abs($rawNetSales - round($vatableForGross + $rawScVatExempt + $rawVat, 2)) <= 0.10;
            if (! $rawNetSalesIncludesVat && $rawVat > 0) {
                $netSales = round($netSales + $rawVat, 2);
            }
        }

        $derivedVat = round(($netSales / 1.12) * 0.12, 2);
        $reportedVatableSales = round((float) ($c['vatable_sales'] ?? 0), 2);

        $capturedVatableIsTaxableInclusive = $rawVat > 0
            && abs($reportedVatableSales - $derivedNetSales) <= 0.05;
        $capturedSplitMatchesTaxableBase = $rawVat > 0
            && abs(round($reportedVatableSales + $rawVat, 2) - $derivedNetSales) <= 0.05;
        $aggregateVat = round(($derivedNetSales / 1.12) * 0.12, 2);
        $capturedSplitIsRoundingOnly = (
            $capturedVatableIncludesVatLessSeniorPwd
            || $capturedVatableIsTaxableInclusive
            || $capturedSplitMatchesTaxableBase
        ) && abs($rawVat - $aggregateVat) <= 1.00;

        $vat = ($rawVat > 0) ? round($rawVat, 2) : $derivedVat;
        if ($capturedSplitIsRoundingOnly) {
            $vat = $aggregateVat;
        }

        $netExVAT = round($netSales - $vat, 2);
        if ($capturedSplitIsRoundingOnly) {
            $reportedVatableSales = round($derivedNetSales - $vat, 2);
            $netExVAT = $reportedVatableSales;
        }

        $netSubjectToRent = round(
            $netExVAT
            + $rawScVatExempt
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
            'vatable_sales' => $reportedVatableSales,
            'gross_sales' => $gross,
            'net_ex_vat' => $netExVAT,
            'net_subject_to_rent' => $netSubjectToRent,
            'net_total' => round($netSales + $rawScVatExempt, 2),
        ]);
    }
}
