<?php

namespace App\Services\Reports;

use Carbon\Carbon;

class SalesReportFilter
{
    public function __construct(
        public readonly mixed $tenantId,
        public readonly Carbon $monthDate,
    ) {
    }

    public static function forTenantMonth(mixed $tenantId, ?string $rawMonth): self
    {
        try {
            $monthDate = Carbon::parse(($rawMonth ?: now()->format('Y-m')) . '-01');
        } catch (\Throwable) {
            $monthDate = now()->startOfMonth();
        }

        return new self($tenantId, $monthDate);
    }

    public static function forTenantYearMonth(mixed $tenantId, int|string|null $year, int|string|null $month): self
    {
        $year = (int) ($year ?: now()->year);
        $month = (int) ($month ?: now()->format('m'));

        return new self($tenantId, Carbon::create($year, $month, 1));
    }

    public function startDate(): string
    {
        return $this->monthDate->copy()->startOfMonth()->toDateString();
    }

    public function endDate(): string
    {
        return $this->monthDate->copy()->endOfMonth()->toDateString();
    }

    public function year(): int
    {
        return (int) $this->monthDate->year;
    }

    public function month(): string
    {
        return $this->monthDate->format('m');
    }

    public function hasTenantScope(): bool
    {
        return filled($this->tenantId) && $this->tenantId !== 'all';
    }
}
