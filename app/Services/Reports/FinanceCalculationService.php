<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;

class FinanceCalculationService
{
    private const EXEMPT_TAX_TYPES = [
        'SC_VAT_EXEMPT_SALES',
        'VAT_EXEMPT_SALES',
        'VATEXEMPT_SALES',
        'VAT-EXEMPT',
        'EXEMPT',
        'VATEXEMPT',
    ];

    private const OTHER_TAX_TYPES = [
        'OTHER_TAX',
        'OTHER-TAX',
    ];

    private const SENIOR_DISCOUNT_TYPES = [
        'senior_discount',
        'senior_citizen_discount',
        'senior',
    ];

    private const PWD_DISCOUNT_TYPES = [
        'pwd_discount',
        'pwd_citizen_discount',
        'pwddiscount',
        'pwd',
    ];

    private const EMPLOYEE_DISCOUNT_TYPES = [
        'employee_discount',
        'EMPLOYEE',
        'employee',
    ];

    private const VIP_DISCOUNT_TYPES = [
        'vip_card_discount',
        'vip_discount',
        'VIP',
        'vip',
    ];

    private const NON_OTHER_TAX_TYPES = [
        'VAT',
        'VAT_AMOUNT',
        'VATABLE_SALES',
        'SC_VAT_EXEMPT_SALES',
        'VAT-EXEMPT',
        'EXEMPT',
        'VATEXEMPT',
        'VATEXEMPT_SALES',
        'VAT_EXEMPT_SALES',
        'ZERO_RATED',
        'NON-VAT',
        'NON_VAT',
        'ZERO-RATED',
        'OTHER_TAX',
        'OTHER-TAX',
    ];

    /**
     * Aggregates transactions into the raw components consumed by deriveMetrics().
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

        $excludeVoids = config('tsms.reporting.exclude_voids_from_totals', true);

        foreach ($transactions as $tx) {
            if ($excludeVoids && method_exists($tx, 'isVoided') && $tx->isVoided()) {
                continue;
            }

            $c['vatable_sales'] += (float) ($tx->vatable_sales ?? 0);
            $c['sc_vat_exempt_sales'] += (float) ($tx->sc_vat_exempt_sales ?? 0);

            if ((float) ($tx->sc_vat_exempt_sales ?? 0) === 0.0) {
                $c['sc_vat_exempt_sales'] += $this->sumRelated($tx, 'taxes', 'tax_type', self::EXEMPT_TAX_TYPES);
            }

            $c['vat_amount'] += (float) ($tx->vat_amount ?? 0);
            $c['other_tax'] += $this->sumRelated($tx, 'taxes', 'tax_type', self::OTHER_TAX_TYPES);

            $payloadAdjustments = $this->adjustmentComponentsFromPayload($tx->original_payload ?? null, $tx->promo_status ?? null);
            $promo = (float) ($tx->promo_discount ?? 0);
            if ($promo === 0.0) {
                $c['promo_with_approval'] += $payloadAdjustments['promo_with_approval'];
                $c['promo_without_approval'] += $payloadAdjustments['promo_without_approval'];
            } elseif (($tx->promo_status ?? null) === 'WITH_APPROVAL') {
                $c['promo_with_approval'] += $promo;
            } else {
                $c['promo_without_approval'] += $promo;
            }

            $employeeDiscount = (float) ($tx->employee_discount ?? 0);
            if ($employeeDiscount === 0.0) {
                $employeeDiscount = $this->sumRelated($tx, 'adjustments', 'adjustment_type', self::EMPLOYEE_DISCOUNT_TYPES);
            }
            if ($employeeDiscount === 0.0) {
                $employeeDiscount = $payloadAdjustments['employee_discount'];
            }

            $vipDiscount = (float) ($tx->vip_card_discount ?? 0);
            if ($vipDiscount === 0.0) {
                $vipDiscount = $this->sumRelated($tx, 'adjustments', 'adjustment_type', self::VIP_DISCOUNT_TYPES);
            }
            if ($vipDiscount === 0.0) {
                $vipDiscount = $payloadAdjustments['vip_discount'];
            }

            $c['employee_discount'] += $employeeDiscount;
            $seniorDiscount = (float) ($tx->senior_discount ?? 0);
            if ($seniorDiscount === 0.0) {
                $seniorDiscount = $this->sumRelated($tx, 'adjustments', 'adjustment_type', self::SENIOR_DISCOUNT_TYPES);
            }
            if ($seniorDiscount === 0.0) {
                $seniorDiscount = $payloadAdjustments['senior_discount'];
            }

            $pwdDiscount = (float) ($tx->pwd_discount ?? 0);
            if ($pwdDiscount === 0.0) {
                $pwdDiscount = $this->sumRelated($tx, 'adjustments', 'adjustment_type', self::PWD_DISCOUNT_TYPES);
            }
            if ($pwdDiscount === 0.0) {
                $pwdDiscount = $payloadAdjustments['pwd_discount'];
            }

            $c['senior_discount'] += $seniorDiscount;
            $c['pwd_discount'] += $pwdDiscount;
            $c['vip_discount'] += $vipDiscount;
            $c['other_tax'] += max(
                $this->sumRelated($tx, 'taxes', 'tax_type', self::NON_OTHER_TAX_TYPES, true),
                $payloadAdjustments['other_tax']
            );

            $serviceChargeDistributed = (float) ($tx->service_charge ?? 0);
            if ($serviceChargeDistributed === 0.0) {
                $serviceChargeDistributed = $payloadAdjustments['service_charge_distributed'];
            }

            $serviceChargeRetained = (float) ($tx->management_service_charge ?? 0);
            if ($serviceChargeRetained === 0.0) {
                $serviceChargeRetained = $payloadAdjustments['service_charge_retained'];
            }

            $c['service_charge_distributed'] += $serviceChargeDistributed;
            $c['service_charge_retained'] += $serviceChargeRetained;
            $c['regular_discount'] += (float) ($tx->discount_total ?? 0);
            $c['gross_sales'] += (float) ($tx->gross_sales ?? 0);
            $c['net_sales'] += (float) ($tx->net_sales ?? 0);
        }

        return $c;
    }

    /**
     * Derives final financial metrics from raw CMSR components.
     *
     * CMSR uses visible-column math rather than simply echoing raw payload
     * fields. Some providers submit VATABLE_SALES as VAT-inclusive; this
     * normalizes those rows so the report matches the Z-reading layout:
     * vatable sales ex-VAT, VAT in its own column, and gross reconstructed
     * from the visible CMSR components.
     */
    public function deriveMetrics(array $c, array $options = []): array
    {
        $promotions = round(($c['promo_with_approval'] ?? 0) + ($c['promo_without_approval'] ?? 0), 2);
        $serviceCharge = round(($c['service_charge_distributed'] ?? 0) + ($c['service_charge_retained'] ?? 0), 2);
        $seniorPwd = round(($c['senior_discount'] ?? 0) + ($c['pwd_discount'] ?? 0), 2);

        $rawNetSales = (float) ($c['net_sales'] ?? 0);
        $rawVat = (float) ($c['vat_amount'] ?? 0);
        $rawScVatExempt = (float) ($c['sc_vat_exempt_sales'] ?? 0);
        $rawVatableSales = (float) ($c['vatable_sales'] ?? 0);
        $hasTaxableBasis = $rawVatableSales > 0 || $rawVat > 0;
        $reportedScVatExempt = (! $hasTaxableBasis && $rawScVatExempt === 0.0 && $rawNetSales > 0)
            ? round($rawNetSales, 2)
            : $rawScVatExempt;

        $vatableForGross = $rawVatableSales;
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
        $rawComponentSum = round(
            $rawVatableSales
            + $reportedScVatExempt
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

        $normalizedComponentSum = round(
            $vatableForGross
            + $reportedScVatExempt
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

        $componentSum = $normalizedComponentSum;
        if ($grossBasis === 'pre_deduction') {
            $rawMatchesNominal = $nominalGross > 0 && abs($rawComponentSum - $nominalGross) <= 0.10;
            $normalizedMatchesNominal = $nominalGross > 0 && abs($normalizedComponentSum - $nominalGross) <= 0.10;
            $componentSum = $rawMatchesNominal || ! $normalizedMatchesNominal
                ? $rawComponentSum
                : $normalizedComponentSum;
        }

        $gross = $grossBasis === 'pre_deduction'
            ? $componentSum
            : ($nominalGross > 0 ? $nominalGross : $componentSum);

        $derivedNetSales = round(
            $gross
            - $promotions
            - ($c['employee_discount'] ?? 0)
            - $seniorPwd
            - ($c['vip_discount'] ?? 0)
            - $reportedScVatExempt
            - ($c['other_tax'] ?? 0)
            - $serviceCharge,
            2
        );

        $netSales = ($rawNetSales > 0) ? round($rawNetSales, 2) : $derivedNetSales;

        if ($rawNetSales > 0 && $reportedScVatExempt > 0) {
            $netSales = round($netSales - $reportedScVatExempt, 2);

            $rawNetSalesIncludesVat = abs($rawNetSales - round($vatableForGross + $reportedScVatExempt + $rawVat, 2)) <= 0.10;
            if (! $rawNetSalesIncludesVat && $rawVat > 0) {
                $netSales = round($netSales + $rawVat, 2);
            }
        }

        $reportedVatableSales = round((float) ($c['vatable_sales'] ?? 0), 2);
        $derivedVat = $hasTaxableBasis ? round(($netSales / 1.12) * 0.12, 2) : 0.0;

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
            + $reportedScVatExempt
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
            'sc_vat_exempt_sales' => $reportedScVatExempt,
            'gross_sales' => $gross,
            'net_ex_vat' => $netExVAT,
            'net_subject_to_rent' => $netSubjectToRent,
            'net_total' => round($netSales + $reportedScVatExempt, 2),
        ]);
    }

    public function adjustmentComponentsFromPayload(mixed $payload, ?string $promoStatus = null): array
    {
        $components = [
            'promo_with_approval' => 0.0,
            'promo_without_approval' => 0.0,
            'employee_discount' => 0.0,
            'senior_discount' => 0.0,
            'pwd_discount' => 0.0,
            'vip_discount' => 0.0,
            'service_charge_distributed' => 0.0,
            'service_charge_retained' => 0.0,
            'other_tax' => 0.0,
        ];

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        if (! is_array($payload)) {
            return $components;
        }

        foreach (($payload['adjustments'] ?? []) as $adjustment) {
            if (! is_array($adjustment)) {
                continue;
            }

            $type = strtolower(trim((string) ($adjustment['adjustment_type'] ?? '')));
            $amount = (float) ($adjustment['amount'] ?? 0);

            if ($type === 'promo_discount') {
                if ($promoStatus === 'WITH_APPROVAL') {
                    $components['promo_with_approval'] += $amount;
                } else {
                    $components['promo_without_approval'] += $amount;
                }
            } elseif (in_array($type, self::EMPLOYEE_DISCOUNT_TYPES, true)) {
                $components['employee_discount'] += $amount;
            } elseif (in_array($type, self::SENIOR_DISCOUNT_TYPES, true)) {
                $components['senior_discount'] += $amount;
            } elseif (in_array($type, self::PWD_DISCOUNT_TYPES, true)) {
                $components['pwd_discount'] += $amount;
            } elseif (in_array($type, self::VIP_DISCOUNT_TYPES, true)) {
                $components['vip_discount'] += $amount;
            } elseif (in_array($type, ['service_charge', 'service_charge_distributed_to_employees'], true)) {
                $components['service_charge_distributed'] += $amount;
            } elseif (in_array($type, ['management_service_charge', 'service_charge_retained_by_management'], true)) {
                $components['service_charge_retained'] += $amount;
            }
        }

        $taxRows = $payload['taxes'] ?? $payload['transaction']['taxes'] ?? [];
        foreach ($taxRows as $tax) {
            if (! is_array($tax)) {
                continue;
            }

            $type = strtoupper(trim((string) ($tax['tax_type'] ?? '')));
            $amount = (float) ($tax['amount'] ?? 0);

            if ($type !== '' && ! in_array($type, self::NON_OTHER_TAX_TYPES, true)) {
                $components['other_tax'] += $amount;
            }
        }

        return $components;
    }

    private function sumRelated(object $tx, string $relation, string $typeColumn, array $types, bool $exclude = false): float
    {
        if (method_exists($tx, 'relationLoaded') && $tx->relationLoaded($relation)) {
            return (float) $tx->getRelation($relation)
                ->filter(function ($row) use ($typeColumn, $types, $exclude) {
                    $matches = in_array($row->{$typeColumn} ?? null, $types, true);

                    return $exclude ? ! $matches : $matches;
                })
                ->sum('amount');
        }

        if (! method_exists($tx, $relation)) {
            return 0.0;
        }

        $query = $tx->{$relation}();
        $query = $exclude
            ? $query->whereNotIn($typeColumn, $types)
            : $query->whereIn($typeColumn, $types);

        return (float) $query->sum('amount');
    }
}
