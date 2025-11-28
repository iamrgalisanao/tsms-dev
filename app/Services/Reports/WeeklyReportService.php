<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Services\Reports\HourlyReportService;

/**
 * WeeklyReportService - builds a per-day summary across a date range by
 * reusing DailyReportService for day-level aggregation.
 */
class WeeklyReportService
{
    protected $dailyService;

    public function __construct(?DailyReportService $dailyService = null)
    {
        $this->dailyService = $dailyService ?? new DailyReportService();
    }

    /**
     * Return a weekly summary and per-day breakdown.
     * @param string $dateFrom Y-m-d
     * @param string $dateTo Y-m-d
     * @param string|null $tenantId
     * @return array ['summary' => [...], 'days' => [...]]
     */
    public function getWeeklySummary(string $dateFrom, string $dateTo, ?string $tenantId = null, bool $excludeWeekends = false, bool $onlyWeekends = false, bool $scaleToMillions = false): array
    {
        try {
            $start = Carbon::parse($dateFrom)->startOfDay();
            $end = Carbon::parse($dateTo)->startOfDay();

            $days = [];
            $summary = [
                'gross_sales' => 0.0,
                'net_sales' => 0.0,
                'transaction_count' => 0,
                'guest_count' => 0,
                'vatable_sales' => 0.0,
                'vat_exempt_sales' => 0.0,
                'vat_amount' => 0.0,
                'sc_pwd_discount' => 0.0,
                'regular_discount' => 0.0,
                'cash_payment' => 0.0,
                'card_payment' => 0.0,
                'other_tender' => 0.0,
            ];

            // Optimization: fetch hourly aggregates for the whole date range in a single query
            // and then group by sales_date to build per-day summaries. This avoids calling
            // DailyReportService (which itself calls hourly aggregates) once per day.
            $startTime = microtime(true);
            $hourlyService = new HourlyReportService();
            $hourRows = $hourlyService->getHourlyAggregates($dateFrom, $dateTo, $tenantId, null, $scaleToMillions);
            $elapsed = microtime(true) - $startTime;
            Log::info('WeeklyReportService: fetched hourly aggregates', ['from' => $dateFrom, 'to' => $dateTo, 'tenant' => $tenantId, 'rows' => count($hourRows), 'duration_s' => $elapsed]);

            // Group hourly rows by sales_date
            $byDate = [];
            foreach ($hourRows as $h) {
                $d = $h['sales_date'] ?? null;
                if (empty($d)) continue;
                if (!isset($byDate[$d])) {
                    $byDate[$d] = [
                        'gross_sales' => 0.0,
                        'gross_sales_m' => 0.0,
                        'net_sales' => 0.0,
                        'net_sales_m' => 0.0,
                        'transaction_count' => 0,
                        'guest_count' => 0,
                        'vatable_sales' => 0.0,
                        'vat_exempt_sales' => 0.0,
                        'vat_amount' => 0.0,
                        'sc_pwd_discount' => 0.0,
                        'regular_discount' => 0.0,
                        'cash_payment' => 0.0,
                        'card_payment' => 0.0,
                        'other_tender' => 0.0,
                    ];
                }
                $byDate[$d]['gross_sales'] += isset($h['gross_sales']) ? (float) $h['gross_sales'] : 0.0;
                $byDate[$d]['gross_sales_m'] += isset($h['gross_sales_m']) ? (float) $h['gross_sales_m'] : ((isset($h['gross_sales']) ? (float) $h['gross_sales'] / 1000000.0 : 0.0));
                $byDate[$d]['net_sales'] += isset($h['net_sales']) ? (float) $h['net_sales'] : 0.0;
                $byDate[$d]['net_sales_m'] += isset($h['net_sales_m']) ? (float) $h['net_sales_m'] : ((isset($h['net_sales']) ? (float) $h['net_sales'] / 1000000.0 : 0.0));
                $byDate[$d]['transaction_count'] += isset($h['transaction_count']) ? (int) $h['transaction_count'] : 0;
                $byDate[$d]['guest_count'] += isset($h['guest_count']) ? (int) $h['guest_count'] : 0;
                foreach (['vatable_sales','vat_exempt_sales','vat_amount','sc_pwd_discount','regular_discount','cash_payment','card_payment','other_tender'] as $k) {
                    $byDate[$d][$k] += isset($h[$k]) ? (float) $h[$k] : 0.0;
                }
            }

            // Build days array for the requested date range (respect weekend options)
            for ($date = $start; $date->lte($end); $date->addDay()) {
                if ($excludeWeekends && $date->isWeekend()) continue;
                if ($onlyWeekends && !$date->isWeekend()) continue;
                $d = $date->format('Y-m-d');
                $s = $byDate[$d] ?? ['gross_sales' => 0.0, 'gross_sales_m' => 0.0, 'net_sales' => 0.0, 'net_sales_m' => 0.0, 'transaction_count' => 0, 'guest_count' => 0, 'vatable_sales' => 0.0, 'vat_exempt_sales' => 0.0, 'vat_amount' => 0.0, 'sc_pwd_discount' => 0.0, 'regular_discount' => 0.0, 'cash_payment' => 0.0, 'card_payment' => 0.0, 'other_tender' => 0.0];

                $days[] = array_merge(['date' => $d], $s);

                $summary['gross_sales'] += (float) ($s['gross_sales'] ?? 0.0);
                $summary['net_sales'] += (float) ($s['net_sales'] ?? 0.0);
                $summary['transaction_count'] += (int) ($s['transaction_count'] ?? 0);
                $summary['guest_count'] += (int) ($s['guest_count'] ?? 0);
                foreach (['vatable_sales','vat_exempt_sales','vat_amount','sc_pwd_discount','regular_discount','cash_payment','card_payment','other_tender'] as $k) {
                    $summary[$k] += (float) ($s[$k] ?? 0.0);
                }
            }

            // Round numeric fields
            foreach (['gross_sales','net_sales','vatable_sales','vat_exempt_sales','vat_amount','sc_pwd_discount','regular_discount','cash_payment','card_payment','other_tender'] as $k) {
                $summary[$k] = round($summary[$k], 2);
            }

            return ['summary' => $summary, 'days' => $days];
        } catch (\Throwable $e) {
            Log::warning('WeeklyReportService failed: ' . $e->getMessage(), ['from' => $dateFrom, 'to' => $dateTo, 'tenant' => $tenantId]);
            return ['summary' => ['gross_sales' => 0.0, 'net_sales' => 0.0, 'transaction_count' => 0, 'guest_count' => 0], 'days' => []];
        }
    }
}
