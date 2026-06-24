<?php

namespace App\Services\Reports;

class SalesReportResult
{
    public function __construct(
        public readonly int $year,
        public readonly string $month,
        public readonly array $dailyTotals,
        public readonly array $totals,
        public readonly string $source,
        public readonly ?string $generatedAt = null,
    ) {
    }

    public function toArray(): array
    {
        $payload = [
            'status' => 'success',
            'year' => $this->year,
            'month' => $this->month,
            'daily_totals' => $this->dailyTotals,
            'totals' => $this->totals,
            'source' => $this->source,
        ];

        if ($this->generatedAt !== null) {
            $payload['generated_at'] = $this->generatedAt;
        }

        return $payload;
    }
}
