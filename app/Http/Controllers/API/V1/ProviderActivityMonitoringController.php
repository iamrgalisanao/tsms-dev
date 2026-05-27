<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionIntake;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProviderActivityMonitoringController extends Controller
{
    public function tenants(Request $request): JsonResponse
    {
        $overrideThreshold = $this->thresholdOverride($request);
        $rows = $this->tenantActivityRows($request, $overrideThreshold);

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'threshold_minutes' => $overrideThreshold,
                'threshold_mode' => $overrideThreshold ? 'override' : 'configured',
                'timezone' => config('app.timezone'),
                'generated_at' => now()->toISOString(),
            ],
        ]);
    }

    public function terminals(Request $request): JsonResponse
    {
        $overrideThreshold = $this->thresholdOverride($request);
        $rows = $this->terminalActivityRows($request, $overrideThreshold);

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'threshold_minutes' => $overrideThreshold,
                'threshold_mode' => $overrideThreshold ? 'override' : 'configured',
                'timezone' => config('app.timezone'),
                'generated_at' => now()->toISOString(),
            ],
        ]);
    }

    public function dailyReport(Request $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'format' => ['nullable', 'in:json,csv'],
        ]);

        $overrideThreshold = $this->thresholdOverride($request);
        $timezone = config('app.timezone');
        $reportDate = isset($validated['date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['date'], $timezone)->startOfDay()
            : CarbonImmutable::today($timezone);

        $tenantRows = $this->tenantActivityRows($request, $overrideThreshold, $reportDate);
        $terminalRows = $this->terminalActivityRows($request, $overrideThreshold, $reportDate);
        $summary = [
            'tracked_tenants' => $tenantRows->count(),
            'active' => $tenantRows->where('status', 'active')->count(),
            'silent' => $tenantRows->where('status', 'silent')->count(),
            'no_submission_today' => $tenantRows->where('status', 'no_submission_today')->count(),
            'inactive_configured' => $tenantRows->where('status', 'inactive_configured')->count(),
            'suppressed_alerts' => $tenantRows->where('alert_suppressed', true)->count(),
            'transactions_today' => $tenantRows->sum('transactions_today'),
            'transactions_yesterday' => $tenantRows->sum('transactions_yesterday'),
            'active_terminals_today' => $tenantRows->sum('active_terminals_today'),
            'silent_terminals' => $terminalRows->where('status', 'silent')->count(),
            'tracked_terminals' => $terminalRows->count(),
        ];

        $meta = [
            'date' => $reportDate->toDateString(),
            'threshold_minutes' => $overrideThreshold,
            'threshold_mode' => $overrideThreshold ? 'override' : 'configured',
            'timezone' => $timezone,
            'generated_at' => now()->toISOString(),
        ];

        if (($validated['format'] ?? 'json') === 'csv') {
            return $this->dailyReportCsv($tenantRows, $summary, $meta);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $reportDate->toDateString(),
                'summary' => $summary,
                'tenants' => $tenantRows,
            ],
            'meta' => $meta,
        ]);
    }

    public function updateTenantConfig(Request $request, int $tenant): JsonResponse
    {
        if (! $this->hasActivityConfigColumns('tenants') || ! $this->hasActivitySuppressionColumns('tenants')) {
            return $this->missingConfigMigrationResponse();
        }

        $validated = $request->validate($this->configRules());
        $tenant = Tenant::query()->findOrFail($tenant);
        $validated = $this->normalizeSuppressionConfig($validated, $request);

        $tenant->update($validated);

        return response()->json([
            'success' => true,
            'data' => [
                'tenant_id' => $tenant->id,
                'monitoring_enabled' => (bool) $tenant->activity_monitoring_enabled,
                'threshold_minutes' => $tenant->activity_threshold_minutes,
                'monitoring_notes' => $tenant->activity_monitoring_notes,
                'alert_suppressed_until' => $tenant->activity_suppressed_until?->toISOString(),
                'alert_suppression_reason' => $tenant->activity_suppression_reason,
                'alert_suppressed_by' => $tenant->activity_suppressed_by,
                'alert_suppressed_at' => $tenant->activity_suppressed_at?->toISOString(),
            ],
        ]);
    }

    public function updateTerminalConfig(Request $request, PosTerminal $terminal): JsonResponse
    {
        if (! $this->hasActivityConfigColumns('pos_terminals') || ! $this->hasActivitySuppressionColumns('pos_terminals')) {
            return $this->missingConfigMigrationResponse();
        }

        $validated = $request->validate($this->configRules());
        $validated = $this->normalizeSuppressionConfig($validated, $request);

        $terminal->update($validated);

        return response()->json([
            'success' => true,
            'data' => [
                'terminal_id' => $terminal->id,
                'monitoring_enabled' => (bool) $terminal->activity_monitoring_enabled,
                'threshold_minutes' => $terminal->activity_threshold_minutes,
                'monitoring_notes' => $terminal->activity_monitoring_notes,
                'alert_suppressed_until' => $terminal->activity_suppressed_until?->toISOString(),
                'alert_suppression_reason' => $terminal->activity_suppression_reason,
                'alert_suppressed_by' => $terminal->activity_suppressed_by,
                'alert_suppressed_at' => $terminal->activity_suppressed_at?->toISOString(),
            ],
        ]);
    }

    private function configRules(): array
    {
        return [
            'activity_monitoring_enabled' => ['required', 'boolean'],
            'activity_threshold_minutes' => ['nullable', 'integer', 'min:5', 'max:10080'],
            'activity_monitoring_notes' => ['nullable', 'string', 'max:500'],
            'activity_suppressed_until' => ['nullable', 'date', 'after:now'],
            'activity_suppression_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function thresholdOverride(Request $request): ?int
    {
        if (! $request->filled('threshold_minutes')) {
            return null;
        }

        return max(5, min((int) $request->integer('threshold_minutes'), 10080));
    }

    private function tenantThreshold(Tenant $tenant, ?int $overrideThreshold): int
    {
        return $overrideThreshold ?? (int) ($tenant->activity_threshold_minutes ?: 1440);
    }

    private function terminalThreshold(PosTerminal $terminal, ?int $overrideThreshold): int
    {
        return $overrideThreshold
            ?? (int) ($terminal->activity_threshold_minutes ?: ($terminal->tenant?->activity_threshold_minutes ?: 1440));
    }

    private function tenantActivityRows(Request $request, ?int $overrideThreshold, ?CarbonImmutable $reportDate = null)
    {
        [$todayStart, $todayEnd, $yesterdayStart, $yesterdayEnd] = $this->dateWindows($reportDate);
        $referenceTime = $this->referenceTime($reportDate);

        $tenants = $this->tenantScope(Tenant::query()->with('posTerminals:id,tenant_id,status_id'), $request)
            ->orderBy('trade_name')
            ->get($this->tenantSelectColumns());

        return $tenants->map(function (Tenant $tenant) use ($todayStart, $todayEnd, $yesterdayStart, $yesterdayEnd, $overrideThreshold, $referenceTime) {
            $threshold = $this->tenantThreshold($tenant, $overrideThreshold);
            $terminalIds = $tenant->posTerminals->pluck('id')->values();
            $lastReceivedAt = $this->lastReceivedAt($tenant->id, null, $todayEnd);
            $lastTransactionTimestamp = $this->lastTransactionTimestamp($tenant->id, null, $todayEnd);
            $transactionsToday = $this->transactionCount($tenant->id, null, $todayStart, $todayEnd);
            $transactionsYesterday = $this->transactionCount($tenant->id, null, $yesterdayStart, $yesterdayEnd);
            $activeTerminalsToday = $terminalIds->isEmpty()
                ? 0
                : Transaction::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereIn('terminal_id', $terminalIds)
                    ->whereBetween('transaction_timestamp', [$todayStart, $todayEnd])
                    ->distinct('terminal_id')
                    ->count('terminal_id');

            $suppression = $this->alertSuppressionState(
                $tenant->activity_suppressed_until ?? null,
                $tenant->activity_suppression_reason ?? null,
                $tenant->activity_suppressed_by ?? null,
                $tenant->activity_suppressed_at ?? null,
                $referenceTime
            );

            return [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->trade_name,
                'customer_code' => $tenant->customer_code,
                'transactions_today' => $transactionsToday,
                'transactions_yesterday' => $transactionsYesterday,
                'last_received_at' => $lastReceivedAt?->toISOString(),
                'last_transaction_timestamp' => $lastTransactionTimestamp?->toISOString(),
                'active_terminals_today' => $activeTerminalsToday,
                'silent_terminals' => max($terminalIds->count() - $activeTerminalsToday, 0),
                'minutes_since_last_transaction' => $this->minutesSince($lastTransactionTimestamp, $referenceTime),
                'threshold_minutes' => $threshold,
                'monitoring_enabled' => (bool) ($tenant->activity_monitoring_enabled ?? true),
                'monitoring_notes' => $tenant->activity_monitoring_notes,
                ...$suppression,
                'status' => $this->activityStatus($tenant->status, $tenant->activity_monitoring_enabled ?? true, $transactionsToday, $lastTransactionTimestamp, $threshold, $referenceTime),
            ];
        });
    }

    private function terminalActivityRows(Request $request, ?int $overrideThreshold, ?CarbonImmutable $reportDate = null)
    {
        [$todayStart, $todayEnd, $yesterdayStart, $yesterdayEnd] = $this->dateWindows($reportDate);
        $referenceTime = $this->referenceTime($reportDate);

        $terminals = $this->terminalScope(PosTerminal::query()->with([
            'tenant' => fn ($query) => $query->select($this->tenantSelectColumns()),
        ]), $request)
            ->orderBy('tenant_id')
            ->orderBy('id')
            ->get($this->terminalSelectColumns());

        return $terminals->map(function (PosTerminal $terminal) use ($todayStart, $todayEnd, $yesterdayStart, $yesterdayEnd, $overrideThreshold, $referenceTime) {
            $threshold = $this->terminalThreshold($terminal, $overrideThreshold);
            $monitoringEnabled = (bool) (($terminal->activity_monitoring_enabled ?? true) && ($terminal->tenant?->activity_monitoring_enabled ?? true));
            $lastReceivedAt = $this->lastReceivedAt($terminal->tenant_id, $terminal->id, $todayEnd);
            $lastTransactionTimestamp = $this->lastTransactionTimestamp($terminal->tenant_id, $terminal->id, $todayEnd);
            $transactionsToday = $this->transactionCount($terminal->tenant_id, $terminal->id, $todayStart, $todayEnd);

            $suppression = $this->alertSuppressionState(
                $terminal->activity_suppressed_until ?? null,
                $terminal->activity_suppression_reason ?? null,
                $terminal->activity_suppressed_by ?? null,
                $terminal->activity_suppressed_at ?? null,
                $referenceTime
            );

            return [
                'tenant_id' => $terminal->tenant_id,
                'tenant_name' => $terminal->tenant?->trade_name,
                'customer_code' => $terminal->tenant?->customer_code,
                'terminal_id' => $terminal->id,
                'serial_number' => $terminal->serial_number,
                'machine_number' => $terminal->machine_number,
                'transactions_today' => $transactionsToday,
                'transactions_yesterday' => $this->transactionCount($terminal->tenant_id, $terminal->id, $yesterdayStart, $yesterdayEnd),
                'last_received_at' => $lastReceivedAt?->toISOString(),
                'last_transaction_timestamp' => $lastTransactionTimestamp?->toISOString(),
                'last_seen_at' => $terminal->last_seen_at?->toISOString(),
                'last_sale_at' => $terminal->last_sale_at?->toISOString(),
                'minutes_since_last_transaction' => $this->minutesSince($lastTransactionTimestamp, $referenceTime),
                'threshold_minutes' => $threshold,
                'monitoring_enabled' => $monitoringEnabled,
                'monitoring_notes' => $terminal->activity_monitoring_notes,
                ...$suppression,
                'status' => $this->activityStatus($terminal->tenant?->status, $monitoringEnabled, $transactionsToday, $lastTransactionTimestamp, $threshold, $referenceTime),
            ];
        });
    }

    private function dateWindows(?CarbonImmutable $date = null): array
    {
        $todayLocal = ($date ?? CarbonImmutable::today(config('app.timezone')))->setTimezone(config('app.timezone'))->startOfDay();
        $yesterdayLocal = $todayLocal->subDay();

        $todayStart = $todayLocal->startOfDay()->utc();
        $todayEnd = $todayLocal->endOfDay()->utc();
        $yesterdayStart = $yesterdayLocal->startOfDay()->utc();
        $yesterdayEnd = $yesterdayLocal->endOfDay()->utc();

        return [$todayStart, $todayEnd, $yesterdayStart, $yesterdayEnd];
    }

    private function tenantSelectColumns(): array
    {
        return $this->existingColumns('tenants', [
            'id',
            'trade_name',
            'customer_code',
            'status',
            'activity_monitoring_enabled',
            'activity_threshold_minutes',
            'activity_monitoring_notes',
            'activity_suppressed_until',
            'activity_suppression_reason',
            'activity_suppressed_by',
            'activity_suppressed_at',
        ]);
    }

    private function terminalSelectColumns(): array
    {
        return $this->existingColumns('pos_terminals', [
            'id',
            'tenant_id',
            'serial_number',
            'machine_number',
            'status_id',
            'last_seen_at',
            'last_sale_at',
            'activity_monitoring_enabled',
            'activity_threshold_minutes',
            'activity_monitoring_notes',
            'activity_suppressed_until',
            'activity_suppression_reason',
            'activity_suppressed_by',
            'activity_suppressed_at',
        ]);
    }

    private function existingColumns(string $table, array $columns): array
    {
        return array_values(array_filter(
            $columns,
            fn (string $column) => Schema::hasColumn($table, $column)
        ));
    }

    private function hasActivityConfigColumns(string $table): bool
    {
        return Schema::hasColumn($table, 'activity_monitoring_enabled')
            && Schema::hasColumn($table, 'activity_threshold_minutes')
            && Schema::hasColumn($table, 'activity_monitoring_notes');
    }

    private function hasActivitySuppressionColumns(string $table): bool
    {
        return Schema::hasColumn($table, 'activity_suppressed_until')
            && Schema::hasColumn($table, 'activity_suppression_reason')
            && Schema::hasColumn($table, 'activity_suppressed_by')
            && Schema::hasColumn($table, 'activity_suppressed_at');
    }

    private function normalizeSuppressionConfig(array $validated, Request $request): array
    {
        if (! array_key_exists('activity_suppressed_until', $validated) || blank($validated['activity_suppressed_until'])) {
            $validated['activity_suppressed_until'] = null;
            $validated['activity_suppression_reason'] = null;
            $validated['activity_suppressed_by'] = null;
            $validated['activity_suppressed_at'] = null;

            return $validated;
        }

        $validated['activity_suppressed_until'] = CarbonImmutable::parse($validated['activity_suppressed_until'])->utc();
        $validated['activity_suppression_reason'] = $validated['activity_suppression_reason'] ?? 'Suppressed by operations';
        $validated['activity_suppressed_by'] = $request->user()?->id;
        $validated['activity_suppressed_at'] = now();

        return $validated;
    }

    private function missingConfigMigrationResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Activity monitoring configuration columns are not installed. Run the activity monitoring migration before updating monitoring settings.',
        ], 409);
    }

    private function referenceTime(?CarbonImmutable $reportDate = null): CarbonImmutable
    {
        if (! $reportDate) {
            return CarbonImmutable::now();
        }

        $today = CarbonImmutable::today(config('app.timezone'));

        if ($reportDate->isSameDay($today)) {
            return CarbonImmutable::now();
        }

        return $reportDate->setTimezone(config('app.timezone'))->endOfDay()->utc();
    }

    private function tenantScope(Builder $query, Request $request): Builder
    {
        $actor = $request->user();

        if ($actor instanceof PosTerminal) {
            return $query->whereKey($actor->tenant_id);
        }

        if ($actor instanceof User && $actor->tenant_id) {
            return $query->whereKey($actor->tenant_id);
        }

        return $query;
    }

    private function terminalScope(Builder $query, Request $request): Builder
    {
        $actor = $request->user();

        if ($actor instanceof PosTerminal) {
            return $query->whereKey($actor->id);
        }

        if ($actor instanceof User && $actor->tenant_id) {
            return $query->where('tenant_id', $actor->tenant_id);
        }

        return $query;
    }

    private function transactionCount(int $tenantId, ?int $terminalId, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Transaction::query()
            ->where('tenant_id', $tenantId)
            ->when($terminalId, fn (Builder $query) => $query->where('terminal_id', $terminalId))
            ->whereBetween('transaction_timestamp', [$start, $end])
            ->count();
    }

    private function lastReceivedAt(int $tenantId, ?int $terminalId = null, ?CarbonImmutable $until = null): ?CarbonImmutable
    {
        $value = TransactionIntake::query()
            ->where('tenant_id', $tenantId)
            ->when($terminalId, fn (Builder $query) => $query->where('terminal_id', $terminalId))
            ->when($until, fn (Builder $query) => $query->where('received_at', '<=', $until))
            ->max('received_at');

        return $value ? CarbonImmutable::parse($value) : null;
    }

    private function lastTransactionTimestamp(int $tenantId, ?int $terminalId = null, ?CarbonImmutable $until = null): ?CarbonImmutable
    {
        $value = Transaction::query()
            ->where('tenant_id', $tenantId)
            ->when($terminalId, fn (Builder $query) => $query->where('terminal_id', $terminalId))
            ->when($until, fn (Builder $query) => $query->where('transaction_timestamp', '<=', $until))
            ->max('transaction_timestamp');

        return $value ? CarbonImmutable::parse($value) : null;
    }

    private function minutesSince(?CarbonImmutable $timestamp, ?CarbonImmutable $referenceTime = null): ?int
    {
        return $timestamp ? $timestamp->diffInMinutes($referenceTime ?? now()) : null;
    }

    private function activityStatus(?string $configuredStatus, bool $monitoringEnabled, int $transactionsToday, ?CarbonImmutable $lastTransactionTimestamp, int $thresholdMinutes, ?CarbonImmutable $referenceTime = null): string
    {
        if (! $monitoringEnabled) {
            return 'inactive_configured';
        }

        if ($configuredStatus && strtolower($configuredStatus) !== 'operational') {
            return 'inactive_configured';
        }

        if (! $lastTransactionTimestamp) {
            return 'no_submission_today';
        }

        $minutesSinceLastTransaction = $this->minutesSince($lastTransactionTimestamp, $referenceTime);

        if ($minutesSinceLastTransaction <= $thresholdMinutes) {
            return $transactionsToday > 0 ? 'active' : 'no_submission_today';
        }

        return 'silent';
    }

    private function alertSuppressionState($suppressedUntil, ?string $reason, ?int $suppressedBy, $suppressedAt, CarbonImmutable $referenceTime): array
    {
        $until = $suppressedUntil ? CarbonImmutable::parse($suppressedUntil) : null;
        $at = $suppressedAt ? CarbonImmutable::parse($suppressedAt) : null;
        $isSuppressed = $until && $until->greaterThan($referenceTime);

        return [
            'alert_suppressed' => (bool) $isSuppressed,
            'alert_state' => $isSuppressed ? 'suppressed' : ($until ? 'suppression_expired' : 'eligible'),
            'alert_suppressed_until' => $until?->toISOString(),
            'alert_suppression_reason' => $reason,
            'alert_suppressed_by' => $suppressedBy,
            'alert_suppressed_at' => $at?->toISOString(),
        ];
    }

    private function dailyReportCsv($tenantRows, array $summary, array $meta): StreamedResponse
    {
        $filename = sprintf('provider-daily-heartbeat-%s.csv', $meta['date']);

        return response()->streamDownload(function () use ($tenantRows, $summary, $meta) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Report date', $meta['date']]);
            fputcsv($handle, ['Timezone', $meta['timezone']]);
            fputcsv($handle, ['Threshold mode', $meta['threshold_mode']]);
            fputcsv($handle, ['Tracked tenants', $summary['tracked_tenants']]);
            fputcsv($handle, ['Active tenants', $summary['active']]);
            fputcsv($handle, ['Silent tenants', $summary['silent']]);
            fputcsv($handle, []);
            fputcsv($handle, [
                'tenant_id',
                'tenant_name',
                'customer_code',
                'status',
                'transactions_today',
                'transactions_yesterday',
                'active_terminals_today',
                'silent_terminals',
                'last_received_at',
                'last_transaction_timestamp',
                'minutes_since_last_transaction',
                'threshold_minutes',
                'monitoring_enabled',
                'monitoring_notes',
                'alert_suppressed',
                'alert_suppressed_until',
                'alert_suppression_reason',
            ]);

            foreach ($tenantRows as $row) {
                fputcsv($handle, [
                    $row['tenant_id'],
                    $row['tenant_name'],
                    $row['customer_code'],
                    $row['status'],
                    $row['transactions_today'],
                    $row['transactions_yesterday'],
                    $row['active_terminals_today'],
                    $row['silent_terminals'],
                    $row['last_received_at'],
                    $row['last_transaction_timestamp'],
                    $row['minutes_since_last_transaction'],
                    $row['threshold_minutes'],
                    $row['monitoring_enabled'] ? 'yes' : 'no',
                    $row['monitoring_notes'],
                    $row['alert_suppressed'] ? 'yes' : 'no',
                    $row['alert_suppressed_until'],
                    $row['alert_suppression_reason'],
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
