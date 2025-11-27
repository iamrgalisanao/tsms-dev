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
    public function getDailySummary(string $date, ?string $tenantId = null, ?string $terminalId = null): array
    {
        try {
            // Reuse hourly aggregates for the single date and perform aggregation.
            $hours = $this->hourlyService->getHourlyAggregates($date, $date, $tenantId, $terminalId);

            $summary = [
                'gross_sales' => 0.0,
                'net_sales' => 0.0,
                'transaction_count' => 0,
                'guest_count' => 0,
            ];

            foreach ($hours as $h) {
                $summary['gross_sales'] += isset($h['gross_sales']) ? (float) $h['gross_sales'] : 0.0;
                $summary['net_sales'] += isset($h['net_sales']) ? (float) $h['net_sales'] : 0.0;
                $summary['transaction_count'] += isset($h['transaction_count']) ? (int) $h['transaction_count'] : 0;
                $summary['guest_count'] += isset($h['guest_count']) ? (int) $h['guest_count'] : 0;
            }

            // Normalize numeric types
            $summary['gross_sales'] = round($summary['gross_sales'], 2);
            $summary['net_sales'] = round($summary['net_sales'], 2);

            return [
                'summary' => $summary,
                'hours' => $hours,
            ];
        } catch (\Throwable $e) {
            Log::warning('DailyReportService failed: ' . $e->getMessage(), ['date' => $date, 'tenant' => $tenantId]);
            return ['summary' => ['gross_sales' => 0.0, 'net_sales' => 0.0, 'transaction_count' => 0, 'guest_count' => 0], 'hours' => []];
        }
    }
}
