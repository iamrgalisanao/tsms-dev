<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;

/**
 * Service that encapsulates the hourly aggregation logic used by both
 * the API and the web (commercial) controllers.
 */
class HourlyReportService
{
    protected $connectionName = 'reporting';

    /**
     * Fetch hourly aggregates for the given date range and optional tenant/terminal filters.
     * Returns a collection (array) of normalized rows with the same contract
     * as Api\Webapp\HourlyTransactionsController produced previously.
     *
     * @param string $dateFrom Y-m-d
     * @param string $dateTo   Y-m-d
     * @param string|null $tenantId
     * @param string|null $terminalId
     * @return array
     */
    public function getHourlyAggregates(string $dateFrom, string $dateTo, ?string $tenantId = null, ?string $terminalId = null, bool $scaleToMillions = false): array
    {
        // Prefer live aggregation from the primary `transactions` table.
        // The reporting summary table may be present in some deployments, but
        // for consistent, authoritative results we always aggregate from the
        // primary DB here.

        // Otherwise, fall back to live aggregation from the primary `transactions` table.
        try {
            // prefer the configured reporting connection if present
            $primary = DB::connection($this->connectionName);
            // build a cache key so frequently re-opened dashboards don't re-run heavy queries
            $cacheKey = sprintf('reports:hourly:%s:%s:tenant:%s:terminal:%s:scale:%s', $dateFrom, $dateTo, $tenantId ?? 'all', $terminalId ?? 'all', $scaleToMillions ? '1' : '0');
            // short TTL - dashboard should show near-real-time data but we avoid repeated identical queries
            $ttl = 60; // seconds

            return Cache::remember($cacheKey, $ttl, function() use ($primary, $dateFrom, $dateTo, $tenantId, $terminalId, $scaleToMillions) {
                $txSchema = $primary->getSchemaBuilder();

                // cache hasColumn checks in a static to reduce repeated schema introspection costs
                static $schemaCache = [];
                $schemaKey = 'transactions_columns';
                if (!isset($schemaCache[$schemaKey])) {
                    $schemaCache[$schemaKey] = [
                        'net_sales' => $txSchema->hasColumn('transactions', 'net_sales'),
                        'discount_total' => $txSchema->hasColumn('transactions', 'discount_total'),
                        'promo_discount' => $txSchema->hasColumn('transactions', 'promo_discount'),
                        'vat_amount' => $txSchema->hasColumn('transactions', 'vat_amount'),
                        'tax_amount' => $txSchema->hasColumn('transactions', 'tax_amount'),
                        'service_charge' => $txSchema->hasColumn('transactions', 'service_charge'),
                        'voided_at' => $txSchema->hasColumn('transactions', 'voided_at'),
                        'refund_amount' => $txSchema->hasColumn('transactions', 'refund_amount'),
                        'refund_status' => $txSchema->hasColumn('transactions', 'refund_status'),
                        'transaction_timestamp' => $txSchema->hasColumn('transactions', 'transaction_timestamp'),
                        'completed_at' => $txSchema->hasColumn('transactions', 'completed_at'),
                        'transaction_date' => $txSchema->hasColumn('transactions', 'transaction_date'),
                    ];
                }

                $hasNet = $schemaCache[$schemaKey]['net_sales'];
                $hasDiscount = $schemaCache[$schemaKey]['discount_total'] || $schemaCache[$schemaKey]['promo_discount'];
                $hasVat = $schemaCache[$schemaKey]['vat_amount'] || $schemaCache[$schemaKey]['tax_amount'];
                $hasSc = $schemaCache[$schemaKey]['service_charge'];
                $hasVoided = $schemaCache[$schemaKey]['voided_at'];
                $hasRefund = $schemaCache[$schemaKey]['refund_amount'] || $schemaCache[$schemaKey]['refund_status'];

            // Determine the best timestamp columns to use for grouping/filtering.
            // Use a COALESCE expression so rows with NULL transaction_timestamp
            // still get included using completed_at/created_at as fallback.
                $tsParts = [];
            if ($schemaCache[$schemaKey]['transaction_timestamp']) {
                $tsParts[] = 'transaction_timestamp';
            }
            if ($schemaCache[$schemaKey]['completed_at']) {
                $tsParts[] = 'completed_at';
            }
            // always include created_at as last-resort
            $tsParts[] = 'created_at';
                $tsExpr = 'COALESCE(' . implode(', ', $tsParts) . ')';

                $selects = [
                'tenant_id',
                DB::raw("COALESCE(terminal_id, 0) AS terminal_id"),
                DB::raw("DATE_FORMAT({$tsExpr}, '%Y-%m-%d %H:00:00') AS hour"),
                DB::raw('COUNT(*) AS tx_count'),
                DB::raw('SUM(COALESCE(gross_sales,0)) AS total_amount'),
                DB::raw('SUM(COALESCE(gross_sales,0)) AS total_gross_amount'),
            ];

            $selects[] = $hasNet ? DB::raw('SUM(COALESCE(net_sales,0)) AS total_net_amount') : DB::raw('0 AS total_net_amount');

            if ($hasDiscount) {
                $hasDiscountTotal = $txSchema->hasColumn('transactions', 'discount_total');
                $hasPromoDiscount = $txSchema->hasColumn('transactions', 'promo_discount');
                if ($hasDiscountTotal && $hasPromoDiscount) {
                    $selects[] = DB::raw('SUM(COALESCE(discount_total, promo_discount, 0)) AS total_discount_amount');
                } elseif ($hasDiscountTotal) {
                    $selects[] = DB::raw('SUM(COALESCE(discount_total, 0)) AS total_discount_amount');
                } else {
                    $selects[] = DB::raw('SUM(COALESCE(promo_discount, 0)) AS total_discount_amount');
                }
            } else {
                $selects[] = DB::raw('0 AS total_discount_amount');
            }

            if ($hasVat) {
                $hasVatAmount = $txSchema->hasColumn('transactions', 'vat_amount');
                $hasTaxAmount = $txSchema->hasColumn('transactions', 'tax_amount');
                if ($hasVatAmount && $hasTaxAmount) {
                    $selects[] = DB::raw('SUM(COALESCE(vat_amount, tax_amount, 0)) AS total_tax_amount');
                } elseif ($hasVatAmount) {
                    $selects[] = DB::raw('SUM(COALESCE(vat_amount, 0)) AS total_tax_amount');
                } else {
                    $selects[] = DB::raw('SUM(COALESCE(tax_amount, 0)) AS total_tax_amount');
                }
            } else {
                $selects[] = DB::raw('0 AS total_tax_amount');
            }

            $selects[] = $hasSc ? DB::raw('SUM(COALESCE(service_charge,0)) AS total_service_charge_amount') : DB::raw('0 AS total_service_charge_amount');
            $selects[] = DB::raw('AVG(COALESCE(gross_sales,0)) AS avg_amount');
            $selects[] = DB::raw('MIN(COALESCE(gross_sales,0)) AS min_amount');
            $selects[] = DB::raw('MAX(COALESCE(gross_sales,0)) AS max_amount');

            $selects[] = DB::raw("SUM(CASE WHEN transaction_type = 'success' THEN 1 ELSE 0 END) AS success_count");
            $selects[] = DB::raw("SUM(CASE WHEN transaction_type = 'decline' THEN 1 ELSE 0 END) AS decline_count");
            $selects[] = DB::raw("SUM(CASE WHEN validation_status = 'WITH_ISSUES' THEN 1 ELSE 0 END) AS issues_count");
            $selects[] = DB::raw("SUM(CASE WHEN validation_status = 'WITH_ISSUES' THEN gross_sales ELSE 0 END) AS issues_amount");

            $selects[] = $hasVoided ? DB::raw('SUM(CASE WHEN voided_at IS NOT NULL THEN 1 ELSE 0 END) AS void_count') : DB::raw('0 AS void_count');

            if ($hasRefund) {
                $hasRefundAmount = $txSchema->hasColumn('transactions', 'refund_amount');
                $hasRefundStatus = $txSchema->hasColumn('transactions', 'refund_status');
                if ($hasRefundAmount && $hasRefundStatus) {
                    $selects[] = DB::raw("SUM(CASE WHEN COALESCE(refund_amount,0) > 0 OR refund_status = 'REFUNDED' THEN 1 ELSE 0 END) AS refunded_count");
                } elseif ($hasRefundAmount) {
                    $selects[] = DB::raw("SUM(CASE WHEN COALESCE(refund_amount,0) > 0 THEN 1 ELSE 0 END) AS refunded_count");
                } else {
                    $selects[] = DB::raw("SUM(CASE WHEN refund_status = 'REFUNDED' THEN 1 ELSE 0 END) AS refunded_count");
                }
            } else {
                $selects[] = DB::raw('0 AS refunded_count');
            }

            // Group & filter using COALESCE-based date checks to match TransactionLog
            // behavior and avoid missing rows when transaction_timestamp is NULL.
                $query = $primary->table('transactions')->select($selects)
                    ->groupBy('tenant_id')->groupBy('terminal_id')->groupBy('hour');

                if ($schemaCache[$schemaKey]['transaction_date']) {
                    $query->whereBetween('transaction_date', [$dateFrom, $dateTo]);
                } else {
                    $query->whereRaw('DATE(' . $tsExpr . ') >= ?', [$dateFrom])
                        ->whereRaw('DATE(' . $tsExpr . ') <= ?', [$dateTo]);
                }

                if (! empty($tenantId)) {
                    $query->where('tenant_id', $tenantId);
                }
                if (! empty($terminalId)) {
                    $query->where('terminal_id', $terminalId);
                }

                $rows = $query->get();

                $data = $rows->map(function ($r) use ($scaleToMillions) {
                return [
                    'customer_code' => null,
                    'tenant_name' => null,
                    'location' => null,
                    'zone' => null,
                    'sales_date' => \Carbon\Carbon::parse($r->hour)->setTimezone(config('app.timezone'))->toDateString(),
                    'hour' => \Carbon\Carbon::parse($r->hour)->setTimezone(config('app.timezone'))->format('H:00'),
                    'gross_sales' => isset($r->total_gross_amount) ? (float) $r->total_gross_amount : (isset($r->total_amount) ? (float) $r->total_amount : 0.0),
                    'vatable_sales' => isset($r->total_net_amount) ? (float) $r->total_net_amount : 0.0,
                    'vat_exempt_sales' => 0.0,
                    'vat_amount' => isset($r->total_tax_amount) ? (float) $r->total_tax_amount : 0.0,
                    'sc_pwd_discount' => 0.0,
                    'regular_discount' => isset($r->total_discount_amount) ? (float) $r->total_discount_amount : 0.0,
                    'void' => isset($r->void_count) ? (float) $r->void_count : 0.0,
                    'return' => isset($r->refunded_count) ? (float) $r->refunded_count : 0.0,
                    'net_sales' => isset($r->total_net_amount) ? (float) $r->total_net_amount : 0.0,
                    'cash_payment' => 0.0,
                    'card_payment' => 0.0,
                    'other_tender' => 0.0,
                    'net_sales_percentage_rent' => 0.0,
                    'transaction_count' => (int) ($r->tx_count ?? 0),
                    'guest_count' => 0,
                    // Add optional scaled fields for dashboards that request server-side millions
                    'gross_sales_m' => isset($r->total_gross_amount) ? round(((float) $r->total_gross_amount) / 1000000.0, 4) : (isset($r->total_amount) ? round(((float) $r->total_amount) / 1000000.0, 4) : 0.0),
                    'net_sales_m' => isset($r->total_net_amount) ? round(((float) $r->total_net_amount) / 1000000.0, 4) : 0.0,
                ];
            })->values()->toArray();

                return $data;
            });
        } catch (\Throwable $e) {
            // On failure, log and return empty array to keep API contract non-breaking
            \Illuminate\Support\Facades\Log::warning('HourlyReportService live aggregation failed: ' . $e->getMessage(), ['date_from' => $dateFrom, 'date_to' => $dateTo]);
            return [];
        }
    }
}
