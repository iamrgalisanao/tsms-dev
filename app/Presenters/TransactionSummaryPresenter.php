<?php

namespace App\Presenters;

use Illuminate\Support\Str;

class TransactionSummaryPresenter
{
    /**
     * Build a minimal summary array for the summary table UI.
     * Returns formatted number strings (2 decimals) or null when the type is absent.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $tx  Transaction model with relations 'adjustments' and 'taxes'
    * @return array{
    *   promo: ?string,
    *   senior: ?string,
    *   pwd: ?string,
    *   vat: ?string,
    *   vatable: ?string,
    *   sc_vat: ?string,
    *   gross: string,
    *   net: string,
    *   refund: string,
    * }
     */
    public static function fromTransaction($tx): array
    {
        // Ensure collections are available; if not, coerce to empty collections
        $adjustments = $tx->adjustments ?? collect();
        $taxes = $tx->taxes ?? collect();

        // Sum adjustments by type
        $promoSum = (float) $adjustments->where('adjustment_type', 'promo_discount')->sum('amount');
        $seniorSum = (float) $adjustments->where('adjustment_type', 'senior_discount')->sum('amount');
        $pwdSum = (float) $adjustments->where('adjustment_type', 'pwd_discount')->sum('amount');

    // Return raw numeric values (null when absent) for presenter consumers
    $promo = $adjustments->pluck('adjustment_type')->contains('promo_discount') ? $promoSum : null;
    $senior = $adjustments->pluck('adjustment_type')->contains('senior_discount') ? $seniorSum : null;
    $pwd = $adjustments->pluck('adjustment_type')->contains('pwd_discount') ? $pwdSum : null;

        // Sum taxes
        $vatSum = (float) $taxes->where('tax_type', 'VAT')->sum('amount');
        $vatableSum = (float) $taxes->where('tax_type', 'VATABLE_SALES')->sum('amount');
        $scSum = (float) $taxes->where('tax_type', 'SC_VAT_EXEMPT_SALES')->sum('amount');

        $vat = $taxes->pluck('tax_type')->contains('VAT') ? $vatSum : null;
        $vatable = $taxes->pluck('tax_type')->contains('VATABLE_SALES') ? $vatableSum : null;
        $sc_vat = $taxes->pluck('tax_type')->contains('SC_VAT_EXEMPT_SALES') ? $scSum : null;

        // Provide gross, net, refund as formatted strings (always present)
        $gross = (float) ($tx->gross_sales ?? 0.0);
        $net = (float) ($tx->net_sales ?? 0.0);
        $refund = (float) ($tx->refund_amount ?? 0.0);

        // Provide both legacy short keys used by some views (promo, vat, etc.)
        // and the explicit keys used by unit tests (promo_amount, vat_amount, ...)
        return [
            'promo' => $promo,
            'senior' => $senior,
            'pwd' => $pwd,
            'vat' => $vat,
            'vatable' => $vatable,
            'sc_vat' => $sc_vat,

            'promo_amount' => $promo,
            'senior_amount' => $senior,
            'pwd_amount' => $pwd,
            'vat_amount' => $vat,
            'vatable_sales' => $vatable,
            'sc_vat_amount' => $sc_vat,

            'gross' => $gross,
            'net' => $net,
            'refund' => $refund,
        ];
    }
}
