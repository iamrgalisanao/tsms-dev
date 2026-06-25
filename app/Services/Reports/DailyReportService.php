<?php

namespace App\Services\Reports;

class DailyReportService
{
    public function __construct(private readonly ?SalesReportDataService $salesReportData = null)
    {
    }

    /**
     * Return a summary and optional hourly breakdown for a single date.
     *
     * @param  string       $date         Y-m-d
     * @param  int|null     $tenantId     null → all tenants
     * @param  int|null     $terminalId   null → all terminals
     * @param  bool         $includeHourly  include 'hours' key in result
     * @return array{summary: array, hours?: array}
     */
    public function getDailySummary(
        string $date,
        $tenantId = null,
        $terminalId = null,
        bool $includeHourly = false
    ): array {
        $summary = $this->commercialRow($this->cmsrResult($date, $date, $tenantId)->totals);

        $result = ['summary' => $summary];

        if ($includeHourly) {
            $result['hours'] = (new HourlyReportService())->getHourlyAggregates($date, $date, $tenantId, $terminalId);
        }

        return $result;
    }

    private function cmsrResult(string $from, string $to, $tenantId): SalesReportResult
    {
        $service = $this->salesReportData ?? app(SalesReportDataService::class);

        return $service->getCmsrReportData(SalesReportFilter::forTenantDateRange($tenantId, $from, $to));
    }

    private function commercialRow(array $metrics): array
    {
        return [
            'gross_sales'        => round((float) ($metrics['gross_sales'] ?? 0), 2),
            'net_sales'          => round((float) ($metrics['net_total'] ?? $metrics['net_sales'] ?? 0), 2),
            'vatable_sales'      => round((float) ($metrics['vatable_sales'] ?? 0), 2),
            'vat_amount'         => round((float) ($metrics['vat_amount'] ?? 0), 2),
            'vat_exempt_sales'   => round((float) ($metrics['sc_vat_exempt_sales'] ?? 0), 2),
            'sc_pwd_discount'    => round((float) ($metrics['senior_pwd'] ?? 0), 2),
            'regular_discount'   => round((float) (($metrics['regular_discount'] ?? 0) + ($metrics['total_promotions'] ?? 0)), 2),
            'transaction_count'  => (int) ($metrics['transaction_count'] ?? 0),
            'guest_count'        => 0,
            'cash_payment'       => 0.0,
            'card_payment'       => 0.0,
            'other_tender'       => 0.0,
        ];
    }
}
