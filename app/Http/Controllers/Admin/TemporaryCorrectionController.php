<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TemporaryCorrectionController extends Controller
{
    private const SAMPLE_LIMIT = 20;
    private const PAYLOAD_CHUNK_SIZE = 100;

    public function tenants(Request $request): JsonResponse
    {
        $data = $this->validatedWindow($request, false);
        [$offsetMinutes, $offsetLabel] = $this->parseUtcOffset($data['utc_offset'] ?? '+08:00');

        $query = DB::table('tenants')
            ->whereNull('deleted_at')
            ->orderBy('trade_name')
            ->select(['id', 'trade_name', 'customer_code', 'updated_at']);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('trade_name', 'like', "%{$search}%")
                    ->orWhere('customer_code', 'like', "%{$search}%")
                    ->orWhere('id', $search);
            });
        }

        $tenants = $query->get()
            ->map(fn ($tenant) => $this->tenantStatus($tenant, $data['from'], $data['to'], $offsetMinutes, $offsetLabel));

        if ($status = $request->query('eligibility')) {
            $tenants = $tenants->filter(fn ($tenant) => $tenant['eligibility']['status'] === $status)->values();
        }

        return response()->json([
            'success' => true,
            'data' => $tenants->values(),
            'providers' => $this->providers(),
            'meta' => [
                'from' => $data['from'],
                'to' => $data['to'],
                'utc_offset' => $offsetLabel,
                'total' => $tenants->count(),
            ],
        ]);
    }

    public function backup(Request $request): JsonResponse
    {
        $data = $this->validatedAction($request, false);

        $results = [];
        foreach ($data['tenant_ids'] as $tenantId) {
            try {
                $results[] = [
                    'tenant_id' => $tenantId,
                    'success' => true,
                    'message' => 'Backup created successfully.',
                    'backup' => $this->createBackup((int) $tenantId, $data['from'], $data['to'], 'manual'),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'tenant_id' => $tenantId,
                    'success' => false,
                    'message' => "Backup failed: {$e->getMessage()}",
                ];
            }
        }

        return response()->json($this->summaryResponse($results));
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $this->validatedAction($request, true);
        [$offsetMinutes, $offsetLabel] = $this->parseUtcOffset($data['utc_offset']);

        $results = [];
        foreach ($data['tenant_ids'] as $tenantId) {
            try {
                $results[] = $this->applyTenant(
                    (int) $tenantId,
                    $data['from'],
                    $data['to'],
                    $offsetMinutes,
                    $offsetLabel,
                    (bool) ($data['correct_timestamps'] ?? false),
                    array_key_exists('provider_id', $data) ? $data['provider_id'] : null
                );
            } catch (\Throwable $e) {
                $results[] = [
                    'tenant_id' => (int) $tenantId,
                    'success' => false,
                    'message' => $this->correctionFailureMessage($e),
                    'raw_error' => $e->getMessage(),
                ];
            }
        }

        return response()->json($this->summaryResponse($results));
    }

    private function validatedWindow(Request $request, bool $requireTenants): array
    {
        $rules = [
            'from' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'to' => ['nullable', 'date_format:Y-m-d H:i:s', 'after_or_equal:from'],
            'utc_offset' => ['nullable', 'string', 'max:16'],
        ];

        if ($requireTenants) {
            $rules['tenant_ids'] = ['required', 'array', 'min:1'];
            $rules['tenant_ids.*'] = ['integer', 'exists:tenants,id'];
        }

        $validated = $request->validate($rules);

        return [
            ...$validated,
            'from' => $validated['from'] ?? now()->startOfMonth()->format('Y-m-d H:i:s'),
            'to' => $validated['to'] ?? now()->endOfMonth()->format('Y-m-d H:i:s'),
            'utc_offset' => $validated['utc_offset'] ?? '+08:00',
        ];
    }

    private function validatedAction(Request $request, bool $requireOperation): array
    {
        $validated = $request->validate([
            'tenant_ids' => ['required', 'array', 'min:1'],
            'tenant_ids.*' => ['integer', 'exists:tenants,id'],
            'from' => ['required', 'date_format:Y-m-d H:i:s'],
            'to' => ['required', 'date_format:Y-m-d H:i:s', 'after_or_equal:from'],
            'utc_offset' => ['nullable', 'string', 'max:16'],
            'correct_timestamps' => ['nullable', 'boolean'],
            'provider_id' => ['nullable', 'integer', 'exists:pos_providers,id'],
        ]);

        $validated['utc_offset'] = $validated['utc_offset'] ?? '+08:00';

        if ($requireOperation && empty($validated['correct_timestamps']) && ! array_key_exists('provider_id', $validated)) {
            throw ValidationException::withMessages([
                'operation' => 'Choose UTC correction, provider_id update, or both.',
            ]);
        }

        return $validated;
    }

    private function tenantStatus(object $tenant, string $from, string $to, int $offsetMinutes, string $offsetLabel): array
    {
        $terminals = DB::table('pos_terminals as pt')
            ->leftJoin('pos_providers as pp', 'pt.provider_id', '=', 'pp.id')
            ->where('pt.tenant_id', $tenant->id)
            ->orderBy('pt.id')
            ->get([
                'pt.id',
                'pt.serial_number',
                'pt.machine_number',
                'pt.provider_id',
                'pt.updated_at',
                'pp.name as provider_name',
                'pp.timestamp_mode',
                'pp.timezone',
            ]);

        $providerIds = $terminals->pluck('provider_id')->uniqueStrict()->values();
        $lastTerminalUpdate = $terminals->pluck('updated_at')->filter()->max();

        if ($terminals->isEmpty()) {
            return $this->tenantPayload($tenant, $terminals, $providerIds, 'not_eligible', 'Not eligible: no registered POS terminals found.', 0, 0, 0, $lastTerminalUpdate);
        }

        $base = $this->transactionQuery((int) $tenant->id, $from, $to);
        $transactionCount = (clone $base)->count();

        if ($transactionCount === 0) {
            return $this->tenantPayload($tenant, $terminals, $providerIds, 'not_eligible', 'Not eligible: no transactions found in the selected stored UTC window.', 0, 0, 0, $lastTerminalUpdate);
        }

        $payloadCount = (clone $base)->whereNotNull('t.original_payload')->count();
        if ($payloadCount === 0) {
            return $this->tenantPayload($tenant, $terminals, $providerIds, 'not_eligible', 'Not eligible: no original payload timestamps are available for audit-safe correction.', $transactionCount, 0, 0, $lastTerminalUpdate);
        }

        $scan = $this->scanCorrections((int) $tenant->id, $from, $to, $offsetMinutes);
        $status = $scan['eligible'] > 0 ? 'eligible' : 'not_eligible';
        $reason = $scan['eligible'] > 0
            ? "Eligible: {$scan['eligible']} transaction timestamp(s) can be normalized using UTC offset {$offsetLabel}."
            : 'Not eligible: no timestamp differences found; records appear already corrected or already true UTC.';

        if ($providerIds->count() > 1) {
            $reason .= ' Review needed: tenant terminals currently have mixed provider_id values.';
        }

        return $this->tenantPayload(
            $tenant,
            $terminals,
            $providerIds,
            $status,
            $reason,
            $transactionCount,
            $payloadCount,
            $scan['eligible'],
            $lastTerminalUpdate,
            $scan['sample']
        );
    }

    private function tenantPayload(
        object $tenant,
        $terminals,
        $providerIds,
        string $status,
        string $reason,
        int $transactionCount,
        int $payloadCount,
        int $eligibleCount,
        ?string $lastTerminalUpdate,
        array $sample = []
    ): array {
        return [
            'id' => $tenant->id,
            'trade_name' => $tenant->trade_name,
            'customer_code' => $tenant->customer_code,
            'terminal_count' => $terminals->count(),
            'terminals' => $terminals->map(fn ($terminal) => [
                'id' => $terminal->id,
                'serial_number' => $terminal->serial_number,
                'machine_number' => $terminal->machine_number,
                'provider_id' => $terminal->provider_id,
                'provider_name' => $terminal->provider_name,
                'timestamp_mode' => $terminal->timestamp_mode,
                'timezone' => $terminal->timezone,
            ])->values(),
            'provider_ids' => $providerIds,
            'current_provider_id' => $providerIds->count() === 1 ? $providerIds->first() : null,
            'last_modified_at' => collect([$tenant->updated_at, $lastTerminalUpdate])->filter()->max(),
            'transaction_count' => $transactionCount,
            'payload_timestamp_count' => $payloadCount,
            'eligible_correction_count' => $eligibleCount,
            'eligibility' => [
                'status' => $status,
                'reason' => $reason,
            ],
            'sample' => $sample,
        ];
    }

    private function applyTenant(
        int $tenantId,
        string $from,
        string $to,
        int $offsetMinutes,
        string $offsetLabel,
        bool $correctTimestamps,
        ?int $providerId
    ): array {
        $backup = $this->createBackup($tenantId, $from, $to, 'pre-apply');
        $beforeProviderIds = DB::table('pos_terminals')->where('tenant_id', $tenantId)->pluck('provider_id')->uniqueStrict()->values()->all();

        $scan = $correctTimestamps
            ? $this->scanCorrections($tenantId, $from, $to, $offsetMinutes)
            : ['eligible' => 0, 'sample' => [], 'affected_dates' => []];

        if ($correctTimestamps && $scan['eligible'] === 0) {
            return [
                'tenant_id' => $tenantId,
                'success' => false,
                'message' => 'No eligible timestamp corrections found. Backup was created and no update was committed.',
                'backup' => $backup,
            ];
        }

        $updatedTransactions = 0;
        $updatedTerminals = 0;

        DB::transaction(function () use (
            $tenantId,
            $from,
            $to,
            $offsetMinutes,
            $correctTimestamps,
            $providerId,
            &$updatedTransactions,
            &$updatedTerminals
        ) {
            if ($correctTimestamps) {
                $this->transactionQuery($tenantId, $from, $to)
                    ->whereNotNull('t.original_payload')
                    ->orderBy('t.id')
                    ->select([
                        't.id',
                        't.tenant_id',
                        't.terminal_id',
                        't.transaction_id',
                        't.receipt_no',
                        't.transaction_timestamp',
                        't.submission_timestamp',
                        't.submission_uuid',
                        't.gross_sales',
                        't.net_sales',
                        't.original_payload',
                        'term.serial_number',
                        'term.provider_id',
                    ])
                    ->chunkById(self::PAYLOAD_CHUNK_SIZE, function ($rows) use ($offsetMinutes, &$updatedTransactions) {
                        foreach ($rows as $row) {
                            $proposal = $this->correctionProposal($row, $offsetMinutes);
                            if ($proposal === null) {
                                continue;
                            }

                            DB::table('transactions')
                                ->where('id', $row->id)
                                ->update([
                                    'transaction_timestamp' => $proposal['new_transaction_timestamp'],
                                    'submission_timestamp' => $proposal['new_submission_timestamp'],
                                    'updated_at' => now(),
                                ]);

                            if ($row->submission_uuid && $proposal['new_submission_timestamp'] !== null && Schema::hasTable('transaction_submissions')) {
                                DB::table('transaction_submissions')
                                    ->where('terminal_id', $row->terminal_id)
                                    ->where('submission_uuid', $row->submission_uuid)
                                    ->update([
                                        'submission_timestamp' => $proposal['new_submission_timestamp'],
                                        'updated_at' => now(),
                                    ]);
                            }

                            $updatedTransactions++;
                        }
                    }, 't.id', 'id');
            }

            if ($providerId !== null) {
                $updatedTerminals = DB::table('pos_terminals')
                    ->where('tenant_id', $tenantId)
                    ->update([
                        'provider_id' => $providerId,
                        'updated_at' => now(),
                    ]);
            }
        });

        if ($correctTimestamps && ! empty($scan['affected_dates'])) {
            $dates = $scan['affected_dates'];
            sort($dates);
            Artisan::call('reports:refresh-daily-transaction-summaries', [
                '--tenant' => $tenantId,
                '--from' => reset($dates),
                '--to' => end($dates),
            ]);
        }

        $afterProviderIds = DB::table('pos_terminals')->where('tenant_id', $tenantId)->pluck('provider_id')->uniqueStrict()->values()->all();

        return [
            'tenant_id' => $tenantId,
            'success' => true,
            'message' => 'Correction committed after backup.',
            'backup' => $backup,
            'diff' => [
                'utc_offset' => $offsetLabel,
                'transactions_updated' => $updatedTransactions,
                'terminals_updated' => $updatedTerminals,
                'provider_id' => [
                    'old' => $beforeProviderIds,
                    'new' => $afterProviderIds,
                ],
                'sample' => $scan['sample'],
                'refreshed_summary_dates' => $scan['affected_dates'],
            ],
        ];
    }

    private function createBackup(int $tenantId, string $from, string $to, string $reason): array
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->first(['id', 'trade_name', 'customer_code', 'updated_at']);
        if (! $tenant) {
            throw new \RuntimeException("Tenant {$tenantId} was not found.");
        }

        $terminals = DB::table('pos_terminals')
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'serial_number', 'machine_number', 'provider_id', 'updated_at']);

        $path = sprintf(
            'admin-corrections/tenant_%d_%s_%s.json',
            $tenantId,
            now()->format('Ymd_His'),
            substr(sha1(json_encode([$tenantId, $from, $to, microtime(true)])), 0, 8)
        );

        $disk = Storage::disk('local');
        $disk->makeDirectory('admin-corrections');
        $fullPath = storage_path("app/{$path}");
        $handle = fopen($fullPath, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Could not write backup file to storage/app/admin-corrections.');
        }

        $transactionCount = 0;
        $summaryCount = 0;

        try {
            fwrite($handle, "{\n");
            $this->writeJsonProperty($handle, 'created_at', now()->toDateTimeString());
            $this->writeJsonProperty($handle, 'created_by', optional(auth()->user())->id);
            $this->writeJsonProperty($handle, 'reason', $reason);
            $this->writeJsonProperty($handle, 'window', compact('from', 'to'));
            $this->writeJsonProperty($handle, 'tenant', $tenant);
            $this->writeJsonProperty($handle, 'terminals', $terminals->values()->all());

            fwrite($handle, "\"transactions\": [\n");
            $firstTransaction = true;
            DB::table('transactions')
                ->where('tenant_id', $tenantId)
                ->whereBetween('transaction_timestamp', [$from, $to])
                ->orderBy('id')
                ->select([
                    'id',
                    'tenant_id',
                    'terminal_id',
                    'transaction_id',
                    'receipt_no',
                    'transaction_timestamp',
                    'submission_timestamp',
                    'submission_uuid',
                    'gross_sales',
                    'net_sales',
                    'original_payload',
                    'updated_at',
                ])
                ->chunkById(self::PAYLOAD_CHUNK_SIZE, function ($rows) use ($handle, &$firstTransaction, &$transactionCount) {
                    foreach ($rows as $row) {
                        $this->writeJsonArrayItem($handle, $row, $firstTransaction);
                        $transactionCount++;
                    }
                });
            fwrite($handle, "\n],\n");

            fwrite($handle, "\"daily_transaction_summaries\": [\n");
            $firstSummary = true;
            if (Schema::hasTable('daily_transaction_summaries')) {
                DB::table('daily_transaction_summaries')
                    ->where('tenant_id', $tenantId)
                    ->whereBetween('business_date', [
                        CarbonImmutable::parse($from, 'UTC')->subDay()->toDateString(),
                        CarbonImmutable::parse($to, 'UTC')->addDay()->toDateString(),
                    ])
                    ->orderBy('business_date')
                    ->cursor()
                    ->each(function ($row) use ($handle, &$firstSummary, &$summaryCount) {
                        $this->writeJsonArrayItem($handle, $row, $firstSummary);
                        $summaryCount++;
                    });
            }
            fwrite($handle, "\n]\n");
            fwrite($handle, "}\n");
        } catch (\Throwable $e) {
            fclose($handle);
            $disk->delete($path);

            throw $e;
        }

        fclose($handle);

        return [
            'path' => $fullPath,
            'transactions' => $transactionCount,
            'terminals' => $terminals->count(),
            'summaries' => $summaryCount,
        ];
    }

    private function writeJsonProperty($handle, string $key, mixed $value): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || fwrite($handle, "\"{$key}\": {$encoded},\n") === false) {
            throw new \RuntimeException('Could not write backup JSON property.');
        }
    }

    private function writeJsonArrayItem($handle, mixed $value, bool &$first): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('Could not encode backup JSON row.');
        }

        $prefix = $first ? '' : ",\n";
        if (fwrite($handle, $prefix . $encoded) === false) {
            throw new \RuntimeException('Could not write backup JSON row.');
        }

        $first = false;
    }

    private function scanCorrections(int $tenantId, string $from, string $to, int $offsetMinutes): array
    {
        $eligible = 0;
        $sample = [];
        $affectedDates = [];

        $this->transactionQuery($tenantId, $from, $to)
            ->whereNotNull('t.original_payload')
            ->orderBy('t.id')
            ->select([
                't.id',
                't.tenant_id',
                't.terminal_id',
                't.transaction_id',
                't.receipt_no',
                't.transaction_timestamp',
                't.submission_timestamp',
                't.submission_uuid',
                't.gross_sales',
                't.net_sales',
                't.original_payload',
                'term.serial_number',
                'term.provider_id',
            ])
            ->chunkById(self::PAYLOAD_CHUNK_SIZE, function ($rows) use (&$eligible, &$sample, &$affectedDates, $offsetMinutes) {
                foreach ($rows as $row) {
                    $proposal = $this->correctionProposal($row, $offsetMinutes);
                    if ($proposal === null) {
                        continue;
                    }

                    $eligible++;
                    $affectedDates[$proposal['old_local_date']] = true;
                    $affectedDates[$proposal['new_local_date']] = true;

                    if (count($sample) < self::SAMPLE_LIMIT) {
                        $sample[] = $proposal;
                    }
                }
            }, 't.id', 'id');

        return [
            'eligible' => $eligible,
            'sample' => $sample,
            'affected_dates' => array_keys($affectedDates),
        ];
    }

    private function correctionProposal(object $row, int $offsetMinutes): ?array
    {
        $payload = json_decode((string) $row->original_payload, true);
        if (! is_array($payload)) {
            return null;
        }

        $payloadTimestamp = $payload['transaction_timestamp']
            ?? $payload['transaction']['transaction_timestamp']
            ?? null;

        if (! is_string($payloadTimestamp) || trim($payloadTimestamp) === '') {
            return null;
        }

        $newTransaction = $this->providerLocalTimestampToUtc($payloadTimestamp, $offsetMinutes);
        if ($newTransaction === null) {
            return null;
        }

        $newTransactionTimestamp = $newTransaction->format('Y-m-d H:i:s');
        $oldTransactionTimestamp = CarbonImmutable::parse($row->transaction_timestamp, 'UTC')->format('Y-m-d H:i:s');

        if ($newTransactionTimestamp === $oldTransactionTimestamp) {
            return null;
        }

        $newSubmissionTimestamp = null;
        if ($row->submission_timestamp !== null && trim((string) $row->submission_timestamp) !== '') {
            $currentSubmission = CarbonImmutable::parse($row->submission_timestamp, 'UTC')->format('Y-m-d H:i:s');
            $newSubmissionTimestamp = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $currentSubmission, 'UTC')
                ->subMinutes($offsetMinutes)
                ->format('Y-m-d H:i:s');
        }

        return [
            'id' => $row->id,
            'receipt_no' => $row->receipt_no,
            'terminal' => $row->serial_number,
            'old_transaction_timestamp' => $oldTransactionTimestamp,
            'new_transaction_timestamp' => $newTransactionTimestamp,
            'new_submission_timestamp' => $newSubmissionTimestamp,
            'old_local_date' => CarbonImmutable::parse($oldTransactionTimestamp, 'UTC')->addMinutes($offsetMinutes)->toDateString(),
            'new_local_date' => CarbonImmutable::parse($newTransactionTimestamp, 'UTC')->addMinutes($offsetMinutes)->toDateString(),
            'gross_sales' => number_format((float) $row->gross_sales, 2, '.', ''),
            'net_sales' => number_format((float) $row->net_sales, 2, '.', ''),
        ];
    }

    private function providerLocalTimestampToUtc(string $timestamp, int $offsetMinutes): ?CarbonImmutable
    {
        $value = trim($timestamp);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/\.\d+Z$/', 'Z', $value) ?? $value;
        $value = preg_replace('/Z$/', '', $value) ?? $value;
        $value = preg_replace('/([+-]\d{2}:?\d{2})$/', '', $value) ?? $value;
        $value = str_replace('T', ' ', $value);
        $value = trim($value);

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            try {
                return CarbonImmutable::createFromFormat($format, $value, 'UTC')->subMinutes($offsetMinutes);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function transactionQuery(int $tenantId, string $from, string $to)
    {
        return DB::table('transactions as t')
            ->join('pos_terminals as term', 't.terminal_id', '=', 'term.id')
            ->where('t.tenant_id', $tenantId)
            ->whereBetween('t.transaction_timestamp', [$from, $to]);
    }

    private function parseUtcOffset(string $offset): array
    {
        $value = trim($offset);

        if (preg_match('/^([+-])(\d{1,2})(?::?(\d{2}))?$/', $value, $matches)) {
            $sign = $matches[1] === '-' ? -1 : 1;
            $hours = (int) $matches[2];
            $minutes = isset($matches[3]) ? (int) $matches[3] : 0;
            if ($hours > 14 || $minutes > 59) {
                throw ValidationException::withMessages(['utc_offset' => 'UTC offset is outside the supported range.']);
            }

            $total = $sign * (($hours * 60) + $minutes);
            return [$total, sprintf('%s%02d:%02d', $sign < 0 ? '-' : '+', $hours, $minutes)];
        }

        if (is_numeric($value)) {
            $number = (float) $value;
            $total = abs($number) <= 14 ? (int) round($number * 60) : (int) round($number);
            if ($total < -840 || $total > 840) {
                throw ValidationException::withMessages(['utc_offset' => 'UTC offset is outside the supported range.']);
            }

            $sign = $total < 0 ? '-' : '+';
            $absolute = abs($total);
            return [$total, sprintf('%s%02d:%02d', $sign, intdiv($absolute, 60), $absolute % 60)];
        }

        throw ValidationException::withMessages(['utc_offset' => 'Use a UTC offset like +08:00, -05:00, or 480 minutes.']);
    }

    private function providers(): array
    {
        return DB::table('pos_providers')
            ->orderBy('id')
            ->get(['id', 'name', 'timezone', 'timestamp_mode'])
            ->map(fn ($provider) => [
                'id' => $provider->id,
                'name' => $provider->name,
                'timezone' => $provider->timezone,
                'timestamp_mode' => $provider->timestamp_mode,
            ])
            ->all();
    }

    private function summaryResponse(array $results): array
    {
        $success = collect($results)->where('success', true)->count();
        $failed = collect($results)->where('success', false)->count();

        return [
            'success' => $failed === 0,
            'summary' => [
                'succeeded' => $success,
                'failed' => $failed,
                'total' => count($results),
            ],
            'results' => $results,
        ];
    }

    private function correctionFailureMessage(\Throwable $e): string
    {
        $message = $e->getMessage();

        if (
            str_contains($message, 'transactions_ux_tx_tenant_terminal_receipt_date_unique')
            || (str_contains($message, 'Duplicate entry') && str_contains($message, 'receipt'))
        ) {
            return 'Correction blocked: moving the transaction timestamp would create a duplicate receipt for the same tenant, terminal, and transaction date. Review the affected receipt before applying this tenant correction.';
        }

        return $message;
    }
}
