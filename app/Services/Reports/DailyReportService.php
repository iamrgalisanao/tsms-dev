<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Log;

/**
 * DailyReportService - lightweight wrapper that builds a daily summary
 * by reusing HourlyReportService to avoid duplicating SQL logic.
 */
class DailyReportService
{
    protected $hourlyService;

    public function __construct(?HourlyReportService $hourlyService = null)
    {
        $this->hourlyService = $hourlyService ?? new HourlyReportService();
    }

    /**
     * Return a daily summary and hourly breakdown for the given date and tenant.
     * @param string $date Y-m-d
     * @param string|null $tenantId
     * @param string|null $terminalId
     * @return array ['summary' => [...], 'hours' => [...]]
     */
    public function getDailySummary(string $date, ?string $tenantId = null, ?string $terminalId = null, bool $scaleToMillions = false): array
    {
        try {
            // Reuse hourly aggregates for the single date and perform aggregation.
            $hours = $this->hourlyService->getHourlyAggregates($date, $date, $tenantId, $terminalId, $scaleToMillions);

            $summary = [
                'gross_sales' => 0.0,
                'net_sales' => 0.0,
                'transaction_count' => 0,
                'guest_count' => 0,
                // breakdown fields
                'vatable_sales' => 0.0,
                'vat_exempt_sales' => 0.0,
                'vat_amount' => 0.0,
                'sc_pwd_discount' => 0.0,
                'regular_discount' => 0.0,
                'cash_payment' => 0.0,
                'card_payment' => 0.0,
                'other_tender' => 0.0,
                'net_sales_percentage_rent' => 0.0,
            ];

            foreach ($hours as $h) {
                $summary['gross_sales'] += isset($h['gross_sales']) ? (float) $h['gross_sales'] : 0.0;
                $summary['gross_sales_m'] = ($summary['gross_sales_m'] ?? 0.0) + (isset($h['gross_sales_m']) ? (float) $h['gross_sales_m'] : ((isset($h['gross_sales']) ? (float) $h['gross_sales'] / 1000000.0 : 0.0)));
                $summary['net_sales'] += isset($h['net_sales']) ? (float) $h['net_sales'] : 0.0;
                $summary['net_sales_m'] = ($summary['net_sales_m'] ?? 0.0) + (isset($h['net_sales_m']) ? (float) $h['net_sales_m'] : ((isset($h['net_sales']) ? (float) $h['net_sales'] / 1000000.0 : 0.0)));
                $summary['transaction_count'] += isset($h['transaction_count']) ? (int) $h['transaction_count'] : 0;
                $summary['guest_count'] += isset($h['guest_count']) ? (int) $h['guest_count'] : 0;
                // breakdown accumulators
                $summary['vatable_sales'] += isset($h['vatable_sales']) ? (float) $h['vatable_sales'] : 0.0;
                $summary['vat_exempt_sales'] += isset($h['vat_exempt_sales']) ? (float) $h['vat_exempt_sales'] : 0.0;
                $summary['vat_amount'] += isset($h['vat_amount']) ? (float) $h['vat_amount'] : 0.0;
                $summary['sc_pwd_discount'] += isset($h['sc_pwd_discount']) ? (float) $h['sc_pwd_discount'] : 0.0;
                $summary['regular_discount'] += isset($h['regular_discount']) ? (float) $h['regular_discount'] : 0.0;
                $summary['cash_payment'] += isset($h['cash_payment']) ? (float) $h['cash_payment'] : 0.0;
                $summary['card_payment'] += isset($h['card_payment']) ? (float) $h['card_payment'] : 0.0;
                $summary['other_tender'] += isset($h['other_tender']) ? (float) $h['other_tender'] : 0.0;
                $summary['net_sales_percentage_rent'] += isset($h['net_sales_percentage_rent']) ? (float) $h['net_sales_percentage_rent'] : 0.0;
            }

            // Normalize numeric types
            // Normalize numeric types
            foreach (['gross_sales','net_sales','vatable_sales','vat_exempt_sales','vat_amount','sc_pwd_discount','regular_discount','cash_payment','card_payment','other_tender','net_sales_percentage_rent'] as $k) {
                $summary[$k] = round($summary[$k], 2);
            }
            if (isset($summary['gross_sales_m'])) $summary['gross_sales_m'] = round($summary['gross_sales_m'], 4);
            if (isset($summary['net_sales_m'])) $summary['net_sales_m'] = round($summary['net_sales_m'], 4);

            // 1. Pre-consolidate all terminal data for each hour to avoid duplicates/omissions in the ledger
            $consolidated = [];
            foreach ($hours as $h) {
                $hr = $h['hour'] ?? '00:00';
                if (!isset($consolidated[$hr])) {
                    $consolidated[$hr] = $h; // copy entire structure
                } else {
                    // Sum up performance metrics
                    foreach (['gross_sales', 'net_sales', 'transaction_count', 'vat_amount', 'gross_sales_m', 'net_sales_m'] as $key) {
                        $consolidated[$hr][$key] = ($consolidated[$hr][$key] ?? 0) + ($h[$key] ?? 0);
                    }
                }
            }

            // 2. Pad to exactly 24 records (00:00 to 23:00)
            $fullHours = [];
            for ($h = 0; $h < 24; $h++) {
                $hStr = sprintf('%02d:00', $h);
                if (isset($consolidated[$hStr])) {
                    $fullHours[] = $consolidated[$hStr];
                } else {
                    $fullHours[] = [
                        'hour' => $hStr,
                        'gross_sales' => 0.0,
                        'net_sales' => 0.0,
                        'transaction_count' => 0,
                        'vat_amount' => 0.0,
                        'gross_sales_m' => 0.0,
                        'net_sales_m' => 0.0,
                    ];
                }
            }

            return [
                'summary' => $summary,
                'hours' => $fullHours,
            ];
        } catch (\Throwable $e) {
            Log::warning('DailyReportService failed: ' . $e->getMessage(), ['date' => $date, 'tenant' => $tenantId]);
            return ['summary' => ['gross_sales' => 0.0, 'net_sales' => 0.0, 'transaction_count' => 0, 'guest_count' => 0], 'hours' => []];
        }
    }
}
