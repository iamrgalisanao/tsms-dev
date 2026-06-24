<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RefreshDailyTransactionSummaries extends Command
{
    protected $signature = 'reports:refresh-daily-transaction-summaries
        {--from= : Start business date, YYYY-MM-DD}
        {--to= : End business date, YYYY-MM-DD}
        {--tenant= : Optional tenant id}
        {--days=2 : Rolling day count when --from/--to are omitted}';

    protected $description = 'Refresh derived daily transaction summaries from authoritative raw transactions.';

    public function handle(): int
    {
        if (! Schema::hasTable('daily_transaction_summaries') || ! Schema::hasTable('report_refresh_states')) {
            $this->error('Summary tables are missing. Run migrations first.');

            return self::FAILURE;
        }

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))->toDateString()
            : now()->toDateString();
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))->toDateString()
            : Carbon::parse($to)->subDays(max((int) $this->option('days'), 1) - 1)->toDateString();
        $tenantId = $this->option('tenant');

        if ($from > $to) {
            $this->error('--from must be before or equal to --to.');

            return self::FAILURE;
        }

        $dateExpr = $this->localReportDateExpression('COALESCE(t.transaction_timestamp, t.created_at)');
        $receiptExpr = Schema::hasColumn('transactions', 'receipt_no')
            ? "COUNT(DISTINCT NULLIF(t.receipt_no, ''))"
            : 'COUNT(*)';
        $grossExpr = Schema::hasColumn('transactions', 'gross_sales') ? 'COALESCE(SUM(t.gross_sales),0)' : '0';
        $netExpr = Schema::hasColumn('transactions', 'net_sales') ? 'COALESCE(SUM(t.net_sales),0)' : '0';
        $vatableExpr = Schema::hasColumn('transactions', 'vatable_sales') ? 'COALESCE(SUM(t.vatable_sales),0)' : '0';
        $vatExpr = Schema::hasColumn('transactions', 'vat_amount') ? 'COALESCE(SUM(t.vat_amount),0)' : '0';
        $scVatExpr = Schema::hasColumn('transactions', 'sc_vat_exempt_sales') ? 'COALESCE(SUM(t.sc_vat_exempt_sales),0)' : '0';
        $refundExpr = Schema::hasColumn('transactions', 'refund_amount') ? 'COALESCE(SUM(t.refund_amount),0)' : '0';
        $promoWithExpr = (Schema::hasColumn('transactions', 'promo_discount') && Schema::hasColumn('transactions', 'promo_status'))
            ? "COALESCE(SUM(CASE WHEN t.promo_status = 'WITH_APPROVAL' THEN t.promo_discount ELSE 0 END),0)"
            : '0';
        $promoWithoutExpr = Schema::hasColumn('transactions', 'promo_discount')
            ? (Schema::hasColumn('transactions', 'promo_status')
                ? "COALESCE(SUM(CASE WHEN t.promo_status != 'WITH_APPROVAL' OR t.promo_status IS NULL THEN t.promo_discount ELSE 0 END),0)"
                : 'COALESCE(SUM(t.promo_discount),0)')
            : '0';
        $seniorExpr = Schema::hasColumn('transactions', 'senior_discount') ? 'COALESCE(SUM(t.senior_discount),0)' : '0';
        $pwdExpr = Schema::hasColumn('transactions', 'pwd_discount') ? 'COALESCE(SUM(t.pwd_discount),0)' : '0';
        $regularExpr = Schema::hasColumn('transactions', 'discount_total') ? 'COALESCE(SUM(t.discount_total),0)' : '0';
        $serviceChargeExpr = Schema::hasColumn('transactions', 'service_charge') ? 'COALESCE(SUM(t.service_charge),0)' : '0';
        $managementServiceChargeExpr = Schema::hasColumn('transactions', 'management_service_charge') ? 'COALESCE(SUM(t.management_service_charge),0)' : '0';
        $otherTaxExpr = Schema::hasColumn('transactions', 'tax_exempt') ? 'COALESCE(SUM(t.tax_exempt),0)' : '0';
        $excludeVoids = config('tsms.reporting.exclude_voids_from_totals', true);
        $hasAdjustmentPk = Schema::hasTable('transaction_adjustments')
            && Schema::hasColumn('transaction_adjustments', 'transaction_pk');
        $hasTaxPk = Schema::hasTable('transaction_taxes')
            && Schema::hasColumn('transaction_taxes', 'transaction_pk');

        $query = DB::table('transactions as t')
            ->selectRaw('t.tenant_id, t.terminal_id')
            ->selectRaw($dateExpr . ' as business_date')
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw($receiptExpr . ' as unique_receipts')
            ->selectRaw($grossExpr . ' as gross_sales')
            ->selectRaw($netExpr . ' as net_sales')
            ->selectRaw($vatableExpr . ' as vatable_sales')
            ->selectRaw($vatExpr . ' as vat_amount')
            ->selectRaw($scVatExpr . ' as sc_vat_exempt_sales')
            ->selectRaw($refundExpr . ' as refund_amount')
            ->selectRaw($promoWithExpr . ' as promo_with_approval')
            ->selectRaw($promoWithoutExpr . ' as promo_without_approval')
            ->selectRaw($seniorExpr . ' as senior_discount')
            ->selectRaw($pwdExpr . ' as pwd_discount')
            ->selectRaw($regularExpr . ' as regular_discount')
            ->selectRaw($serviceChargeExpr . ' as service_charge_distributed')
            ->selectRaw($managementServiceChargeExpr . ' as service_charge_retained')
            ->selectRaw($otherTaxExpr . ' as transaction_other_tax')
            ->whereRaw($dateExpr . ' BETWEEN ? AND ?', [$from, $to])
            ->when($tenantId, fn ($q) => $q->where('t.tenant_id', $tenantId));

        if ($excludeVoids && Schema::hasColumn('transactions', 'transaction_type')) {
            $query->where('t.transaction_type', '!=', 'VOID')->whereNull('t.voided_at');
        }

        if ($hasAdjustmentPk) {
            $adjustments = DB::table('transaction_adjustments')
                ->selectRaw('transaction_pk')
                ->selectRaw("SUM(CASE WHEN adjustment_type IN ('employee_discount', 'EMPLOYEE') THEN amount ELSE 0 END) as employee_discount")
                ->selectRaw("SUM(CASE WHEN adjustment_type IN ('vip_card_discount', 'VIP') THEN amount ELSE 0 END) as vip_discount")
                ->groupBy('transaction_pk');

            $query->leftJoinSub($adjustments, 'adj_totals', fn ($join) => $join->on('adj_totals.transaction_pk', '=', 't.id'))
                ->selectRaw('COALESCE(SUM(COALESCE(adj_totals.employee_discount,0)),0) as employee_discount')
                ->selectRaw('COALESCE(SUM(COALESCE(adj_totals.vip_discount,0)),0) as vip_discount');
        } else {
            $query->selectRaw('0 as employee_discount')->selectRaw('0 as vip_discount');
        }

        if ($hasTaxPk) {
            $taxes = DB::table('transaction_taxes')
                ->selectRaw('transaction_pk')
                ->selectRaw("SUM(CASE WHEN tax_type NOT IN ('VAT', 'VAT_AMOUNT', 'VATABLE_SALES', 'SC_VAT_EXEMPT_SALES', 'VAT-EXEMPT', 'EXEMPT', 'VATEXEMPT', 'VATEXEMPT_SALES', 'VAT_EXEMPT_SALES', 'ZERO_RATED', 'NON-VAT', 'NON_VAT', 'ZERO-RATED') THEN amount ELSE 0 END) as other_tax")
                ->groupBy('transaction_pk');

            $query->leftJoinSub($taxes, 'tax_totals', fn ($join) => $join->on('tax_totals.transaction_pk', '=', 't.id'))
                ->selectRaw('COALESCE(SUM(COALESCE(tax_totals.other_tax,0)),0) as other_tax');
        } else {
            $query->selectRaw('0 as other_tax');
        }

        $rows = $query
            ->groupBy('t.tenant_id', 't.terminal_id', 'business_date')
            ->orderBy('business_date')
            ->get();
        $payloadAdjustments = $this->payloadAdjustmentTotals($from, $to, $tenantId, $dateExpr);

        $affectedDates = collect();
        for ($date = Carbon::parse($from); $date->lte(Carbon::parse($to)); $date->addDay()) {
            $affectedDates->push($date->toDateString());
        }

        DB::transaction(function () use ($rows, $affectedDates, $tenantId, $payloadAdjustments) {
            DB::table('daily_transaction_summaries')
                ->whereBetween('business_date', [$affectedDates->first(), $affectedDates->last()])
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->delete();

            foreach ($rows as $row) {
                $payload = $payloadAdjustments[$row->tenant_id][$row->terminal_id][$row->business_date] ?? [];

                DB::table('daily_transaction_summaries')->insert([
                    'tenant_id' => $row->tenant_id,
                    'terminal_id' => $row->terminal_id,
                    'business_date' => $row->business_date,
                    'transaction_count' => (int) $row->transaction_count,
                    'unique_receipts' => (int) $row->unique_receipts,
                    'gross_sales' => $row->gross_sales,
                    'net_sales' => $row->net_sales,
                    'vatable_sales' => $row->vatable_sales,
                    'vat_amount' => $row->vat_amount,
                    'sc_vat_exempt_sales' => $row->sc_vat_exempt_sales,
                    'refund_amount' => $row->refund_amount,
                    'promo_with_approval' => max((float) $row->promo_with_approval, (float) ($payload['promo_with_approval'] ?? 0)),
                    'promo_without_approval' => max((float) $row->promo_without_approval, (float) ($payload['promo_without_approval'] ?? 0)),
                    'employee_discount' => max((float) $row->employee_discount, (float) ($payload['employee_discount'] ?? 0)),
                    'senior_discount' => max((float) $row->senior_discount, (float) ($payload['senior_discount'] ?? 0)),
                    'pwd_discount' => max((float) $row->pwd_discount, (float) ($payload['pwd_discount'] ?? 0)),
                    'vip_discount' => max((float) $row->vip_discount, (float) ($payload['vip_discount'] ?? 0)),
                    'regular_discount' => $row->regular_discount,
                    'other_tax' => max((float) $row->transaction_other_tax, (float) $row->other_tax),
                    'service_charge_distributed' => $row->service_charge_distributed,
                    'service_charge_retained' => $row->service_charge_retained,
                    'refreshed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($affectedDates as $date) {
                DB::table('report_refresh_states')->updateOrInsert([
                    'report_type' => 'daily_transaction_summaries',
                    'tenant_id' => $tenantId ?: null,
                    'business_date' => $date,
                ], [
                    'status' => 'completed',
                    'refreshed_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
            }
        });

        $this->info(sprintf(
            'Refreshed %d daily transaction summary rows for %s through %s.',
            $rows->count(),
            $from,
            $to
        ));

        return self::SUCCESS;
    }

    private function payloadAdjustmentTotals(string $from, string $to, mixed $tenantId, string $dateExpr): array
    {
        if (! Schema::hasColumn('transactions', 'original_payload')) {
            return [];
        }

        $service = app(\App\Services\Reports\FinanceCalculationService::class);
        $rows = DB::table('transactions as t')
            ->selectRaw('t.tenant_id, t.terminal_id')
            ->selectRaw($dateExpr . ' as business_date')
            ->addSelect('t.promo_status', 't.original_payload')
            ->whereRaw($dateExpr . ' BETWEEN ? AND ?', [$from, $to])
            ->when($tenantId, fn ($q) => $q->where('t.tenant_id', $tenantId));

        if (config('tsms.reporting.exclude_voids_from_totals', true) && Schema::hasColumn('transactions', 'transaction_type')) {
            $rows->where('t.transaction_type', '!=', 'VOID')->whereNull('t.voided_at');
        }

        $totals = [];
        foreach ($rows->cursor() as $row) {
            $totals[$row->tenant_id][$row->terminal_id][$row->business_date] ??= [
                'promo_with_approval' => 0.0,
                'promo_without_approval' => 0.0,
                'employee_discount' => 0.0,
                'senior_discount' => 0.0,
                'pwd_discount' => 0.0,
                'vip_discount' => 0.0,
            ];

            foreach ($service->adjustmentComponentsFromPayload($row->original_payload, $row->promo_status) as $key => $value) {
                if (array_key_exists($key, $totals[$row->tenant_id][$row->terminal_id][$row->business_date])) {
                    $totals[$row->tenant_id][$row->terminal_id][$row->business_date][$key] += $value;
                }
            }
        }

        return $totals;
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
}
