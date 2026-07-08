<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class RepairOvercorrectedProviderTimestamps extends Command
{
    protected $signature = 'tsms:repair-overcorrected-provider-timestamps
        {--tenant= : Required tenant id to scope the repair}
        {--provider= : Optional POS provider id to scope terminals}
        {--terminal= : Optional terminal id to scope repair}
        {--from= : Required current stored UTC timestamp lower bound, e.g. "2026-07-03 00:00:00"}
        {--to= : Required current stored UTC timestamp upper bound, e.g. "2026-07-05 23:59:59"}
        {--timezone=Asia/Manila : Local timezone the provider timestamp actually represents}
        {--payload-mode=local_time_with_z : Interpret payload timestamp as local_time_with_z or true_utc}
        {--chunk=500 : Chunk size}
        {--limit= : Optional maximum rows to inspect}
        {--apply : Actually update rows; omitted means dry-run}
        {--refresh-summaries : Refresh daily summaries for affected local dates after --apply}';

    protected $description = 'Dry-run/apply repair for rows where provider local-time correction was applied one extra time.';

    public function handle(): int
    {
        if (! Schema::hasColumn('transactions', 'original_payload')) {
            $this->error('transactions.original_payload is required for this repair.');

            return self::FAILURE;
        }

        $tenantId = $this->option('tenant');
        $from = $this->option('from');
        $to = $this->option('to');
        $timezone = (string) $this->option('timezone');
        $payloadMode = (string) $this->option('payload-mode');
        $apply = (bool) $this->option('apply');
        $chunk = max((int) $this->option('chunk'), 1);
        $limit = $this->option('limit') !== null ? max((int) $this->option('limit'), 1) : null;

        if (! $tenantId || ! $from || ! $to) {
            $this->error('Required options: --tenant, --from, --to.');

            return self::FAILURE;
        }

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $this->error("Invalid timezone: {$timezone}");

            return self::FAILURE;
        }

        if (! in_array($payloadMode, ['local_time_with_z', 'true_utc'], true)) {
            $this->error('Invalid payload mode. Use local_time_with_z or true_utc.');

            return self::FAILURE;
        }

        $query = $this->repairQuery((int) $tenantId, $from, $to)
            ->when($this->option('provider'), fn ($q, $providerId) => $q->where('term.provider_id', $providerId))
            ->when($this->option('terminal'), fn ($q, $terminalId) => $q->where('t.terminal_id', $terminalId));

        $backup = null;
        if ($apply) {
            $backup = $this->createBackup((int) $tenantId, $from, $to);
        }

        $inspected = 0;
        $eligible = 0;
        $updated = 0;
        $skipped = 0;
        $sample = [];
        $affectedLocalDates = [];

        $this->info(($apply ? 'APPLY' : 'DRY-RUN') . ' overcorrected provider timestamp repair');
        $this->line("Tenant: {$tenantId}");
        $this->line("Stored UTC window: {$from} to {$to}");
        $this->line("Provider local timezone: {$timezone}");
        $this->line("Payload timestamp mode: {$payloadMode}");
        if ($backup !== null) {
            $this->line("Backup: {$backup['path']}");
        }

        $query->chunkById($chunk, function ($rows) use (
            &$inspected,
            &$eligible,
            &$updated,
            &$skipped,
            &$sample,
            &$affectedLocalDates,
            $limit,
            $timezone,
            $payloadMode,
            $apply
        ) {
            foreach ($rows as $row) {
                if ($limit !== null && $inspected >= $limit) {
                    return false;
                }

                $inspected++;
                $proposal = $this->repairProposal($row, $timezone, $payloadMode);

                if ($proposal === null) {
                    $skipped++;
                    continue;
                }

                $eligible++;
                $affectedLocalDates[$proposal['old_local_date']] = true;
                $affectedLocalDates[$proposal['new_local_date']] = true;

                if (count($sample) < 20) {
                    $sample[] = [
                        'id' => $proposal['id'],
                        'receipt' => $proposal['receipt_no'],
                        'terminal' => $proposal['serial_number'],
                        'old_utc' => $proposal['old_transaction_timestamp'],
                        'new_utc' => $proposal['new_transaction_timestamp'],
                        'payload_local' => $proposal['payload_local_timestamp'],
                        'offset' => $proposal['repair_offset_minutes'],
                    ];
                }

                if ($apply) {
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

                    $updated++;
                }
            }

            return true;
        }, 't.id', 'id');

        $this->newLine();
        $this->table(
            ['ID', 'Receipt', 'Terminal', 'Old UTC', 'New UTC', 'Payload Local', 'Repair Offset'],
            array_map(fn ($row) => [
                $row['id'],
                $row['receipt'],
                $row['terminal'],
                $row['old_utc'],
                $row['new_utc'],
                $row['payload_local'],
                $row['offset'],
            ], $sample)
        );

        $this->newLine();
        $this->line("Inspected: {$inspected}");
        $this->line("Eligible repairs: {$eligible}");
        $this->line("Skipped: {$skipped}");
        $this->line($apply ? "Updated: {$updated}" : 'Updated: 0 (dry-run)');

        $dates = array_keys($affectedLocalDates);
        sort($dates);
        $this->line('Affected local dates: ' . (empty($dates) ? 'none' : implode(', ', $dates)));

        if (! $apply) {
            $this->warn('Dry-run only. Re-run with --apply to update rows.');

            return self::SUCCESS;
        }

        if ($this->option('refresh-summaries') && ! empty($dates)) {
            Artisan::call('reports:refresh-daily-transaction-summaries', [
                '--tenant' => $tenantId,
                '--from' => reset($dates),
                '--to' => end($dates),
            ]);
            $this->line(Artisan::output());
        } elseif (! empty($dates)) {
            $this->warn('Daily summaries were not refreshed. Run reports:refresh-daily-transaction-summaries for the affected dates.');
        }

        return self::SUCCESS;
    }

    private function repairQuery(int $tenantId, string $from, string $to)
    {
        return DB::table('transactions as t')
            ->join('pos_terminals as term', 't.terminal_id', '=', 'term.id')
            ->where('t.tenant_id', $tenantId)
            ->whereBetween('t.transaction_timestamp', [$from, $to])
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
            ]);
    }

    private function repairProposal(object $row, string $timezone, string $payloadMode): ?array
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

        $expectedUtc = $this->expectedPayloadUtc($payloadTimestamp, $timezone, $payloadMode);
        if ($expectedUtc === null) {
            return null;
        }

        $offsetMinutes = $expectedUtc->setTimezone($timezone)->utcOffset();
        $overcorrectedUtc = $expectedUtc->subMinutes($offsetMinutes);
        $oldUtc = CarbonImmutable::parse($row->transaction_timestamp, 'UTC')->utc();

        if ($oldUtc->format('Y-m-d H:i:s') !== $overcorrectedUtc->format('Y-m-d H:i:s')) {
            return null;
        }

        $newSubmissionTimestamp = null;
        if ($row->submission_timestamp !== null && trim((string) $row->submission_timestamp) !== '') {
            $newSubmissionTimestamp = CarbonImmutable::parse($row->submission_timestamp, 'UTC')
                ->addMinutes($offsetMinutes)
                ->format('Y-m-d H:i:s');
        }

        return [
            'id' => $row->id,
            'receipt_no' => $row->receipt_no,
            'serial_number' => $row->serial_number,
            'old_transaction_timestamp' => $oldUtc->format('Y-m-d H:i:s'),
            'new_transaction_timestamp' => $expectedUtc->format('Y-m-d H:i:s'),
            'new_submission_timestamp' => $newSubmissionTimestamp,
            'payload_local_timestamp' => $expectedUtc->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'payload_mode' => $payloadMode,
            'repair_offset_minutes' => $offsetMinutes,
            'old_local_date' => $oldUtc->setTimezone($timezone)->toDateString(),
            'new_local_date' => $expectedUtc->setTimezone($timezone)->toDateString(),
            'gross_sales' => number_format((float) $row->gross_sales, 2, '.', ''),
            'net_sales' => number_format((float) $row->net_sales, 2, '.', ''),
        ];
    }

    private function expectedPayloadUtc(string $timestamp, string $timezone, string $payloadMode): ?CarbonImmutable
    {
        if ($payloadMode === 'true_utc') {
            try {
                return CarbonImmutable::parse($timestamp)->utc();
            } catch (\Throwable) {
                return null;
            }
        }

        return $this->providerLocalTimestamp($timestamp, $timezone)?->utc();
    }

    private function providerLocalTimestamp(string $timestamp, string $timezone): ?CarbonImmutable
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
                return CarbonImmutable::createFromFormat($format, $value, $timezone);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function createBackup(int $tenantId, string $from, string $to): array
    {
        $path = sprintf(
            'admin-corrections/tenant_%d_overcorrected_%s_%s.json',
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
        try {
            fwrite($handle, "{\n");
            fwrite($handle, '"created_at": ' . json_encode(now()->toDateTimeString()) . ",\n");
            fwrite($handle, '"reason": "repair-overcorrected-provider-timestamps",' . "\n");
            fwrite($handle, '"window": ' . json_encode(compact('from', 'to')) . ",\n");
            fwrite($handle, "\"transactions\": [\n");

            $first = true;
            $this->repairQuery($tenantId, $from, $to)
                ->cursor()
                ->each(function ($row) use ($handle, &$first, &$transactionCount) {
                    $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if ($encoded === false) {
                        throw new \RuntimeException('Could not encode backup JSON row.');
                    }

                    fwrite($handle, ($first ? '' : ",\n") . $encoded);
                    $first = false;
                    $transactionCount++;
                });

            fwrite($handle, "\n]\n}\n");
        } catch (\Throwable $e) {
            fclose($handle);
            $disk->delete($path);

            throw $e;
        }

        fclose($handle);

        return [
            'path' => $fullPath,
            'transactions' => $transactionCount,
        ];
    }
}
