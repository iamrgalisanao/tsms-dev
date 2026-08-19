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

    /**
     * Statutory VAT rate. Also the expected ratio of reported VAT to a reported
     * taxable base that is already VAT-exclusive.
     */
    private const VAT_RATE = 0.12;

    /**
     * The same ratio when the reported taxable base still carries its VAT:
     * 0.12 / 1.12 = 0.1071428... The two anchors are 10.7% apart in relative
     * terms, which is what makes the classification in
     * normalizeVatableToExclusive() scale-free.
     */
    private const VAT_RATIO_VAT_INCLUSIVE = self::VAT_RATE / (1 + self::VAT_RATE);

    /**
     * How far the observed ratio may sit from an anchor and still be classified.
     * The anchors are 0.01286 apart, so this leaves a dead band between them
     * rather than letting the two match windows overlap. Observed drift on real
     * day-level aggregates is under 0.00004, so this is ~150x the worst case
     * seen while still refusing to classify a genuinely odd split.
     */
    private const VAT_RATIO_MATCH_TOLERANCE = 0.006;

    /**
     * How far the observed gross overshoot may sit from the reported VAT and still
     * corroborate a VAT-inclusive base. Expressed as a fraction of the VAT itself so
     * it scales with the aggregate; the floor covers very small buckets. Real
     * day-level aggregates corroborate to within ~0.03% of VAT.
     */
    private const VAT_CORROBORATION_RATE = 0.02;

    private const VAT_CORROBORATION_FLOOR = 1.00;

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
     * CMSR keeps Gross Sales anchored to the POS-reported pre-deduction
     * amount when available. Component math is still returned as reconciliation
     * metadata so dashboard variances remain explainable without mutating raw
     * transaction values.
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

        $gross = $nominalGross > 0 ? $nominalGross : $componentSum;
        $computedGross = $componentSum;
        $grossVariance = round($computedGross - $gross, 2);
        $calculationGross = $grossBasis === 'pre_deduction' ? $computedGross : $gross;

        $derivedNetSales = round(
            $calculationGross
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

        [$reportedVatableSales, $vatableSalesBasis] = $this->normalizeVatableToExclusive(
            $reportedVatableSales,
            $rawVat,
            $rawComponentSum,
            $nominalGross
        );

        $netExVAT = round($netSales - $vat, 2);
        if ($capturedSplitIsRoundingOnly) {
            // Deliberately re-derived here rather than reusing $reportedVatableSales.
            // The two were the same value before the VAT-exclusive normalization was
            // introduced; keeping them decoupled holds net_ex_vat -- and therefore
            // net_subject_to_rent, the percentage-rent basis -- byte-identical to the
            // pre-fix behaviour. Do not collapse these back together without pinning
            // net_subject_to_rent with tests first.
            $netExVAT = round($derivedNetSales - $vat, 2);
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
            'vatable_sales_basis' => $vatableSalesBasis,
            'sc_vat_exempt_sales' => $reportedScVatExempt,
            'gross_sales' => $gross,
            'raw_gross_sales' => $nominalGross,
            'computed_gross_sales' => $computedGross,
            'gross_sales_variance' => $grossVariance,
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

    /**
     * Classifies a reported taxable base as VAT-exclusive or VAT-inclusive and
     * returns it normalized to VAT-exclusive, plus a label describing the decision.
     *
     * VAT-exclusive is the authoritative basis for report-facing output: the PITX
     * CMSR worksheet computes VAT as `Vatable Trans. * 12%` and Gross as `SUM(B:M)`
     * across both columns, so a VAT-inclusive base makes the worksheet double-count
     * VAT when it reconstructs Gross.
     *
     * Two independent conditions must both hold before anything is rewritten.
     *
     * 1. Ratio test. reported_vat / reported_vatable sits at 0.12 when the base is
     *    already VAT-exclusive and at 0.12/1.12 when it still carries VAT. Unlike an
     *    absolute peso tolerance this is scale-free: per-receipt rounding moves the
     *    ratio by parts per million whatever the aggregate's row count, which is what
     *    made the previous `<= 1.00` gate flip the same tenant between bases on
     *    adjacent trading days.
     *
     * 2. Corroboration. The ratio alone cannot separate a genuinely VAT-inclusive
     *    base from a VAT-exclusive base diluted by exempt or zero-rated content: an
     *    exempt share between roughly 5.7% and 15.7% inside the vatable column lands
     *    on the same ratio. Those two cases are distinguished by whether the
     *    double-count is actually observable -- if the base really carries VAT, the
     *    raw component sum overshoots the POS-reported gross by approximately the VAT
     *    amount. When gross does not corroborate, the value is left untouched.
     *
     * This preserves the property that a rewrite only ever happens where it demonstrably
     * improves reconciliation against the POS-reported gross, and never on the strength
     * of a two-number coincidence.
     *
     * Corroboration is directional evidence, not proof. The overshoot test cannot tell
     * "vatable double-counts VAT" apart from "gross omits VAT" -- both leave
     * `rawComponentSum - gross` near the VAT amount. A tenant reporting gross EXCLUDING
     * VAT *and* carrying 5.7-15.7% exempt or zero-rated content inside the vatable column
     * would still be written down. That conjunction is unobserved in any fixture or
     * staging day examined (tenant 106 reports gross including VAT), but anyone extending
     * this should know the guard is one-sided.
     *
     * PRECONDITION: the aggregate must represent a single reporting convention. A bucket
     * blending tenants that report on different bases yields a weighted-average ratio
     * that this function cannot detect. See docs/DEFECT_FINANCE_VATABLE_NORMALIZATION.md.
     *
     * @return array{0: float, 1: string} normalized amount, basis label
     */
    private function normalizeVatableToExclusive(
        float $vatable,
        float $vat,
        float $rawComponentSum,
        float $nominalGross
    ): array {
        if ($vatable <= 0.0 || $vat <= 0.0) {
            return [$vatable, 'not_applicable'];
        }

        $ratio = $vat / $vatable;

        if (abs($ratio - self::VAT_RATE) <= self::VAT_RATIO_MATCH_TOLERANCE) {
            return [$vatable, 'exclusive'];
        }

        if (abs($ratio - self::VAT_RATIO_VAT_INCLUSIVE) > self::VAT_RATIO_MATCH_TOLERANCE) {
            // Matches neither basis. Do not guess.
            return [$vatable, 'unmatched'];
        }

        // Ratio says VAT-inclusive. Require the gross to show the double-count too.
        if ($nominalGross <= 0.0) {
            return [$vatable, 'uncorroborated'];
        }

        $overshoot = round($rawComponentSum - $nominalGross, 2);
        $tolerance = max(self::VAT_CORROBORATION_FLOOR, self::VAT_CORROBORATION_RATE * $vat);

        if (abs($overshoot - $vat) > $tolerance) {
            return [$vatable, 'uncorroborated'];
        }

        return [round($vatable / (1 + self::VAT_RATE), 2), 'normalized_from_inclusive'];
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
