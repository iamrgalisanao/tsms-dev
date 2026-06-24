<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesReportDataService
{
    public function __construct(private readonly FinanceCalculationService $finance)
    {
    }

    public function getCmsrReportData(SalesReportFilter $filter): SalesReportResult
    {
        $startDate = $filter->startDate();
        $endDate = $filter->endDate();
        $tenantId = $filter->tenantId;
        $reportDateExpr = $this->reportDateExpression('COALESCE(transaction_timestamp, created_at)', 'original_payload');
        $joinedReportDateExpr = $this->reportDateExpression('COALESCE(transactions.transaction_timestamp, transactions.created_at)', 'transactions.original_payload');

        if ($summary = $this->dailySummaryResult($filter, $reportDateExpr, $joinedReportDateExpr)) {
            return $summary;
        }

        $grossExpr = Schema::hasColumn('transactions', 'gross_sales') ? 'SUM(gross_sales)' : '0';
        $netExpr = Schema::hasColumn('transactions', 'net_sales') ? 'SUM(net_sales)' : '0';
        $vatableExpr = Schema::hasColumn('transactions', 'vatable_sales') ? 'SUM(vatable_sales)' : '0';
        $scVatExpr = Schema::hasColumn('transactions', 'sc_vat_exempt_sales') ? 'SUM(sc_vat_exempt_sales)' : '0';
        $vatExpr = Schema::hasColumn('transactions', 'vat_amount') ? 'SUM(vat_amount)' : '0';
        $promoWithExpr = (Schema::hasColumn('transactions', 'promo_discount') && Schema::hasColumn('transactions', 'promo_status'))
            ? "SUM(IF(promo_status = 'WITH_APPROVAL', promo_discount, 0))"
            : '0';
        $promoWithoutExpr = Schema::hasColumn('transactions', 'promo_discount')
            ? (Schema::hasColumn('transactions', 'promo_status')
                ? "SUM(IF(promo_status != 'WITH_APPROVAL' OR promo_status IS NULL, promo_discount, 0))"
                : 'SUM(promo_discount)')
            : '0';
        $seniorExpr = Schema::hasColumn('transactions', 'senior_discount') ? 'SUM(senior_discount)' : '0';
        $pwdExpr = Schema::hasColumn('transactions', 'pwd_discount') ? 'SUM(pwd_discount)' : '0';
        $regularExpr = Schema::hasColumn('transactions', 'discount_total') ? 'SUM(discount_total)' : '0';
        $serviceChargeExpr = Schema::hasColumn('transactions', 'service_charge') ? 'SUM(service_charge)' : '0';
        $managementServiceChargeExpr = Schema::hasColumn('transactions', 'management_service_charge') ? 'SUM(management_service_charge)' : '0';
        $otherTaxExpr = Schema::hasColumn('transactions', 'tax_exempt') ? 'SUM(tax_exempt)' : '0';

        $query = Transaction::query()
            ->selectRaw("
                {$reportDateExpr} as report_date,
                {$grossExpr} as gross_sales,
                {$netExpr} as net_sales,
                {$vatableExpr} as vatable_sales,
                {$scVatExpr} as sc_vat_exempt_sales,
                {$vatExpr} as vat_amount,
                {$seniorExpr} as senior_discount,
                {$pwdExpr} as pwd_discount,
                {$regularExpr} as regular_discount,
                {$serviceChargeExpr} as service_charge_distributed,
                {$managementServiceChargeExpr} as service_charge_retained,
                {$otherTaxExpr} as transaction_other_tax,
                {$promoWithExpr} as promo_with_approval,
                {$promoWithoutExpr} as promo_without_approval
            ")
            ->whereRaw("{$reportDateExpr} BETWEEN ? AND ?", [$startDate, $endDate]);

        if ($filter->hasTenantScope()) {
            $query->where('tenant_id', $tenantId);
        }

        if ($this->excludeVoids()) {
            $query->where('transaction_type', '!=', 'VOID')->whereNull('voided_at');
        }

        $dailyMain = $query->groupBy('report_date')->get()->keyBy('report_date');
        $dailyAdj = $this->adjustmentRows($startDate, $endDate, $tenantId, $joinedReportDateExpr, $filter->hasTenantScope());
        $dailyTax = $this->taxRows($startDate, $endDate, $tenantId, $joinedReportDateExpr, $filter->hasTenantScope());
        $dailyPayloadAdjustments = $this->payloadAdjustmentTotals($startDate, $endDate, $tenantId, $reportDateExpr, $filter->hasTenantScope());

        [$dailyTotals, $totals] = $this->deriveDailyTotals($dailyMain, $dailyAdj, $dailyTax, $dailyPayloadAdjustments);

        return new SalesReportResult(
            $filter->year(),
            $filter->month(),
            $dailyTotals,
            $totals,
            'raw_transactions',
        );
    }

    private function dailySummaryResult(SalesReportFilter $filter, string $reportDateExpr, string $joinedReportDateExpr): ?SalesReportResult
    {
        if (! Schema::hasTable('daily_transaction_summaries') || ! Schema::hasTable('report_refresh_states')) {
            return null;
        }

        $startDate = $filter->startDate();
        $endDate = $filter->endDate();
        $tenantId = $filter->tenantId;

        if (! $this->hasCompleteDailySummaryRefresh($startDate, $endDate, $tenantId, $filter->hasTenantScope())) {
            return null;
        }

        $rows = DB::table('daily_transaction_summaries')
            ->selectRaw('business_date as report_date')
            ->selectRaw('SUM(gross_sales) as gross_sales')
            ->selectRaw('SUM(net_sales) as net_sales')
            ->selectRaw('SUM(vatable_sales) as vatable_sales')
            ->selectRaw('SUM(sc_vat_exempt_sales) as sc_vat_exempt_sales')
            ->selectRaw('SUM(vat_amount) as vat_amount')
            ->selectRaw('SUM(promo_with_approval) as promo_with_approval')
            ->selectRaw('SUM(promo_without_approval) as promo_without_approval')
            ->selectRaw('SUM(employee_discount) as employee_discount')
            ->selectRaw('SUM(senior_discount) as senior_discount')
            ->selectRaw('SUM(pwd_discount) as pwd_discount')
            ->selectRaw('SUM(vip_discount) as vip_discount')
            ->selectRaw('SUM(other_tax) as other_tax')
            ->selectRaw('SUM(service_charge_distributed) as service_charge_distributed')
            ->selectRaw('SUM(service_charge_retained) as service_charge_retained')
            ->whereBetween('business_date', [$startDate, $endDate])
            ->when($filter->hasTenantScope(), fn ($query) => $query->where('tenant_id', $tenantId))
            ->groupBy('business_date')
            ->orderBy('business_date')
            ->get()
            ->keyBy('report_date');

        if ($rows->isEmpty()) {
            return null;
        }

        $payloadAdjustments = $this->payloadAdjustmentTotals($startDate, $endDate, $tenantId, $reportDateExpr, $filter->hasTenantScope());
        $adjustmentTotals = $this->dailyAdjustmentTotals($startDate, $endDate, $tenantId, $joinedReportDateExpr, $filter->hasTenantScope());
        $dailyTotals = [];
        $allComponents = [];

        foreach ($rows as $date => $row) {
            $adjustments = $adjustmentTotals[$date] ?? [];
            $payload = $payloadAdjustments[$date] ?? [];
            $components = [
                'vatable_sales' => (float) ($row->vatable_sales ?? 0),
                'sc_vat_exempt_sales' => (float) ($row->sc_vat_exempt_sales ?? 0),
                'vat_amount' => (float) ($row->vat_amount ?? 0),
                'promo_with_approval' => max((float) ($row->promo_with_approval ?? 0), (float) ($payload['promo_with_approval'] ?? 0)),
                'promo_without_approval' => max((float) ($row->promo_without_approval ?? 0), (float) ($payload['promo_without_approval'] ?? 0)),
                'employee_discount' => max((float) ($row->employee_discount ?? 0), (float) ($payload['employee_discount'] ?? 0)),
                'senior_discount' => max((float) ($row->senior_discount ?? 0), (float) ($adjustments['senior_discount'] ?? 0), (float) ($payload['senior_discount'] ?? 0)),
                'pwd_discount' => max((float) ($row->pwd_discount ?? 0), (float) ($adjustments['pwd_discount'] ?? 0), (float) ($payload['pwd_discount'] ?? 0)),
                'vip_discount' => max((float) ($row->vip_discount ?? 0), (float) ($payload['vip_discount'] ?? 0)),
                'other_tax' => max((float) ($row->other_tax ?? 0), (float) ($payload['other_tax'] ?? 0)),
                'service_charge_distributed' => max((float) ($row->service_charge_distributed ?? 0), (float) ($payload['service_charge_distributed'] ?? 0)),
                'service_charge_retained' => max((float) ($row->service_charge_retained ?? 0), (float) ($payload['service_charge_retained'] ?? 0)),
                'regular_discount' => 0.0,
                'gross_sales' => (float) ($row->gross_sales ?? 0),
                'net_sales' => (float) ($row->net_sales ?? 0),
            ];

            foreach ($components as $key => $value) {
                $allComponents[$key] = ($allComponents[$key] ?? 0) + $value;
            }

            $dailyTotals[$date] = $this->finance->deriveMetrics($components, ['gross_sales_basis' => 'pre_deduction']);
        }

        return new SalesReportResult(
            $filter->year(),
            $filter->month(),
            $dailyTotals,
            $this->finance->deriveMetrics($allComponents, ['gross_sales_basis' => 'pre_deduction']),
            'daily_transaction_summaries',
            now()->toIso8601String(),
        );
    }

    private function adjustmentRows(string $startDate, string $endDate, mixed $tenantId, string $reportDateExpr, bool $hasTenantScope)
    {
        $query = DB::table('transaction_adjustments')
            ->join('transactions', 'transaction_adjustments.transaction_pk', '=', 'transactions.id')
            ->selectRaw("
                {$reportDateExpr} as report_date,
                SUM(IF(transaction_adjustments.adjustment_type IN ('employee_discount', 'EMPLOYEE'), transaction_adjustments.amount, 0)) as employee_discount,
                SUM(IF(transaction_adjustments.adjustment_type IN ('senior_discount', 'senior_citizen_discount', 'senior'), transaction_adjustments.amount, 0)) as senior_discount,
                SUM(IF(transaction_adjustments.adjustment_type IN ('pwd_discount', 'pwd_citizen_discount', 'pwddiscount', 'pwd'), transaction_adjustments.amount, 0)) as pwd_discount,
                SUM(IF(transaction_adjustments.adjustment_type IN ('vip_card_discount', 'VIP'), transaction_adjustments.amount, 0)) as vip_discount
            ")
            ->whereRaw("{$reportDateExpr} BETWEEN ? AND ?", [$startDate, $endDate]);

        if ($hasTenantScope) {
            $query->where('transactions.tenant_id', $tenantId);
        }

        if ($this->excludeVoids()) {
            $query->where('transactions.transaction_type', '!=', 'VOID')->whereNull('transactions.voided_at');
        }

        return $query->groupBy('report_date')->get()->keyBy('report_date');
    }

    private function taxRows(string $startDate, string $endDate, mixed $tenantId, string $reportDateExpr, bool $hasTenantScope)
    {
        $query = DB::table('transaction_taxes')
            ->join('transactions', 'transaction_taxes.transaction_pk', '=', 'transactions.id')
            ->selectRaw("
                {$reportDateExpr} as report_date,
                SUM(IF(transaction_taxes.tax_type IN ('SC_VAT_EXEMPT_SALES', 'VAT_EXEMPT_SALES', 'VATEXEMPT_SALES', 'VAT-EXEMPT', 'EXEMPT', 'VATEXEMPT'), transaction_taxes.amount, 0)) as sc_vat_exempt_fallback,
                SUM(IF(transaction_taxes.tax_type NOT IN ('VAT', 'VAT_AMOUNT', 'VATABLE_SALES', 'SC_VAT_EXEMPT_SALES', 'VAT-EXEMPT', 'EXEMPT', 'VATEXEMPT', 'VATEXEMPT_SALES', 'VAT_EXEMPT_SALES', 'ZERO_RATED', 'NON-VAT', 'NON_VAT', 'ZERO-RATED'), transaction_taxes.amount, 0)) as other_tax_basis
            ")
            ->whereRaw("{$reportDateExpr} BETWEEN ? AND ?", [$startDate, $endDate]);

        if ($hasTenantScope) {
            $query->where('transactions.tenant_id', $tenantId);
        }

        if ($this->excludeVoids()) {
            $query->where('transactions.transaction_type', '!=', 'VOID')->whereNull('transactions.voided_at');
        }

        return $query->groupBy('report_date')->get()->keyBy('report_date');
    }

    private function deriveDailyTotals($dailyMain, $dailyAdj, $dailyTax, array $dailyPayloadAdjustments): array
    {
        $dailyTotals = [];
        $allComponents = [];
        $allDates = $dailyMain->keys()->union($dailyAdj->keys())->union($dailyTax->keys())->sort();

        foreach ($allDates as $date) {
            $tx = $dailyMain->get($date);
            $adj = $dailyAdj->get($date);
            $tax = $dailyTax->get($date);
            $payload = $dailyPayloadAdjustments[$date] ?? [];

            $components = [
                'vatable_sales' => (float) ($tx->vatable_sales ?? 0),
                'sc_vat_exempt_sales' => (float) ($tx->sc_vat_exempt_sales ?? 0),
                'vat_amount' => (float) ($tx->vat_amount ?? 0),
                'promo_with_approval' => max((float) ($tx->promo_with_approval ?? 0), (float) ($payload['promo_with_approval'] ?? 0)),
                'promo_without_approval' => max((float) ($tx->promo_without_approval ?? 0), (float) ($payload['promo_without_approval'] ?? 0)),
                'employee_discount' => max((float) ($adj->employee_discount ?? 0), (float) ($payload['employee_discount'] ?? 0)),
                'senior_discount' => max((float) ($tx->senior_discount ?? 0), (float) ($adj->senior_discount ?? 0), (float) ($payload['senior_discount'] ?? 0)),
                'pwd_discount' => max((float) ($tx->pwd_discount ?? 0), (float) ($adj->pwd_discount ?? 0), (float) ($payload['pwd_discount'] ?? 0)),
                'vip_discount' => max((float) ($adj->vip_discount ?? 0), (float) ($payload['vip_discount'] ?? 0)),
                'other_tax' => max((float) ($tx->transaction_other_tax ?? 0), (float) ($tax->other_tax_basis ?? 0), (float) ($payload['other_tax'] ?? 0)),
                'service_charge_distributed' => max((float) ($tx->service_charge_distributed ?? 0), (float) ($payload['service_charge_distributed'] ?? 0)),
                'service_charge_retained' => max((float) ($tx->service_charge_retained ?? 0), (float) ($payload['service_charge_retained'] ?? 0)),
                'regular_discount' => 0.0,
                'gross_sales' => (float) ($tx->gross_sales ?? 0),
                'net_sales' => (float) ($tx->net_sales ?? 0),
            ];

            if ($components['sc_vat_exempt_sales'] === 0.0 && isset($tax->sc_vat_exempt_fallback)) {
                $components['sc_vat_exempt_sales'] = (float) $tax->sc_vat_exempt_fallback;
            }

            foreach ($components as $key => $value) {
                $allComponents[$key] = ($allComponents[$key] ?? 0) + $value;
            }

            $dailyTotals[$date] = $this->finance->deriveMetrics($components, ['gross_sales_basis' => 'pre_deduction']);
        }

        return [$dailyTotals, $this->finance->deriveMetrics($allComponents, ['gross_sales_basis' => 'pre_deduction'])];
    }

    private function payloadAdjustmentTotals(string $startDate, string $endDate, mixed $tenantId, string $reportDateExpr, bool $hasTenantScope): array
    {
        if (! Schema::hasColumn('transactions', 'original_payload')) {
            return [];
        }

        $rows = DB::table('transactions')
            ->selectRaw($reportDateExpr . ' as report_date')
            ->addSelect('original_payload')
            ->whereRaw($reportDateExpr . ' BETWEEN ? AND ?', [$startDate, $endDate]);

        if (Schema::hasColumn('transactions', 'promo_status')) {
            $rows->addSelect('promo_status');
        }

        if ($hasTenantScope) {
            $rows->where('tenant_id', $tenantId);
        }

        if ($this->excludeVoids()) {
            $rows->where('transaction_type', '!=', 'VOID')->whereNull('voided_at');
        }

        $totals = [];
        foreach ($rows->cursor() as $row) {
            $date = (string) $row->report_date;
            $totals[$date] ??= [
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

            foreach ($this->finance->adjustmentComponentsFromPayload($row->original_payload, $row->promo_status ?? null) as $key => $value) {
                $totals[$date][$key] += $value;
            }
        }

        return $totals;
    }

    private function dailyAdjustmentTotals(string $startDate, string $endDate, mixed $tenantId, string $reportDateExpr, bool $hasTenantScope): array
    {
        if (! Schema::hasTable('transaction_adjustments')) {
            return [];
        }

        $query = DB::table('transaction_adjustments')
            ->join('transactions', 'transaction_adjustments.transaction_pk', '=', 'transactions.id')
            ->selectRaw($reportDateExpr . ' as report_date')
            ->selectRaw("SUM(IF(transaction_adjustments.adjustment_type IN ('senior_discount', 'senior_citizen_discount', 'senior'), transaction_adjustments.amount, 0)) as senior_discount")
            ->selectRaw("SUM(IF(transaction_adjustments.adjustment_type IN ('pwd_discount', 'pwd_citizen_discount', 'pwddiscount', 'pwd'), transaction_adjustments.amount, 0)) as pwd_discount")
            ->whereRaw($reportDateExpr . ' BETWEEN ? AND ?', [$startDate, $endDate]);

        if ($hasTenantScope) {
            $query->where('transactions.tenant_id', $tenantId);
        }

        if ($this->excludeVoids()) {
            $query->where('transactions.transaction_type', '!=', 'VOID')
                ->whereNull('transactions.voided_at');
        }

        return $query->groupBy('report_date')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->report_date => [
                'senior_discount' => (float) ($row->senior_discount ?? 0),
                'pwd_discount' => (float) ($row->pwd_discount ?? 0),
            ]])
            ->all();
    }

    private function hasCompleteDailySummaryRefresh(string $startDate, string $endDate, mixed $tenantId, bool $hasTenantScope): bool
    {
        $expectedDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
        $query = DB::table('report_refresh_states')
            ->where('report_type', 'daily_transaction_summaries')
            ->where('status', 'completed')
            ->whereBetween('business_date', [$startDate, $endDate]);

        if ($hasTenantScope) {
            $query->where(function ($nested) use ($tenantId) {
                $nested->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            });
        } else {
            $query->whereNull('tenant_id');
        }

        return (int) $query->distinct('business_date')->count('business_date') >= $expectedDays;
    }

    private function reportDateExpression(string $timestampExpression, string $payloadExpression): string
    {
        if (! Schema::hasColumn('transactions', 'original_payload')) {
            return $this->localReportDateExpression($timestampExpression);
        }

        $driver = DB::connection()->getDriverName();
        $localDateExpression = $this->localReportDateExpression($timestampExpression);

        if ($driver === 'pgsql') {
            $payloadTimestamp = "COALESCE(({$payloadExpression})::jsonb->>'transaction_timestamp', ({$payloadExpression})::jsonb#>>'{transaction,transaction_timestamp}')";

            return "CASE
                WHEN {$payloadExpression} IS NOT NULL
                    AND {$payloadExpression} != ''
                    AND {$payloadTimestamp} IS NOT NULL
                    AND {$payloadTimestamp} !~ '(Z|[+-][0-9]{2}:?[0-9]{2})$'
                THEN DATE({$timestampExpression})
                ELSE {$localDateExpression}
            END";
        }

        $payloadTimestamp = "CASE
            WHEN {$payloadExpression} IS NOT NULL AND {$payloadExpression} != '' AND JSON_VALID({$payloadExpression})
            THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT({$payloadExpression}, '$.transaction_timestamp')), JSON_UNQUOTE(JSON_EXTRACT({$payloadExpression}, '$.transaction.transaction_timestamp')))
            ELSE NULL
        END";

        if ($driver === 'sqlite') {
            $payloadTimestamp = "COALESCE(json_extract({$payloadExpression}, '$.transaction_timestamp'), json_extract({$payloadExpression}, '$.transaction.transaction_timestamp'))";

            return "CASE
                WHEN {$payloadExpression} IS NOT NULL
                    AND {$payloadExpression} != ''
                    AND {$payloadTimestamp} IS NOT NULL
                    AND {$payloadTimestamp} NOT LIKE '%Z'
                    AND {$payloadTimestamp} NOT GLOB '*[+-][0-9][0-9]:[0-9][0-9]'
                    AND {$payloadTimestamp} NOT GLOB '*[+-][0-9][0-9][0-9][0-9]'
                THEN DATE({$timestampExpression})
                ELSE {$localDateExpression}
            END";
        }

        return "CASE
            WHEN {$payloadExpression} IS NOT NULL
                AND {$payloadExpression} != ''
                AND {$payloadTimestamp} IS NOT NULL
                AND {$payloadTimestamp} NOT REGEXP '(Z|[+-][0-9]{2}:?[0-9]{2})$'
            THEN DATE({$timestampExpression})
            ELSE {$localDateExpression}
        END";
    }

    private function localReportDateExpression(string $timestampExpression): string
    {
        $offsetMinutes = Carbon::now($this->reportTimezone())->utcOffset();
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $modifier = sprintf('%+d minutes', $offsetMinutes);

            return "DATE(datetime({$timestampExpression}, '{$modifier}'))";
        }

        if ($driver === 'pgsql') {
            $operator = $offsetMinutes >= 0 ? '+' : '-';
            $minutes = abs($offsetMinutes);

            return "DATE({$timestampExpression} {$operator} INTERVAL '{$minutes} minutes')";
        }

        $function = $offsetMinutes >= 0 ? 'DATE_ADD' : 'DATE_SUB';
        $minutes = abs($offsetMinutes);

        return "DATE({$function}({$timestampExpression}, INTERVAL {$minutes} MINUTE))";
    }

    private function reportTimezone(): string
    {
        return config('tsms.transaction_logs.timezone', 'Asia/Manila') ?: 'Asia/Manila';
    }

    private function excludeVoids(): bool
    {
        return config('tsms.reporting.exclude_voids_from_totals', true);
    }
}
