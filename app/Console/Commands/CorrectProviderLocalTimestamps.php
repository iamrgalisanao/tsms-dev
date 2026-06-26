<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CorrectProviderLocalTimestamps extends Command
{
    protected $signature = 'tsms:correct-provider-local-timestamps
        {--tenant= : Required tenant id to scope the correction}
        {--provider= : Optional POS provider id to scope terminals}
        {--terminal= : Optional terminal id to scope correction}
        {--from= : Required current stored UTC timestamp lower bound, e.g. "2026-06-23 00:00:00"}
        {--to= : Required current stored UTC timestamp upper bound, e.g. "2026-06-23 23:59:59"}
        {--timezone=Asia/Manila : Local timezone the provider timestamp actually represents}
        {--chunk=500 : Chunk size}
        {--limit= : Optional maximum rows to inspect}
        {--apply : Actually update rows; omitted means dry-run}
        {--refresh-summaries : Refresh daily summaries for affected local dates after --apply}';

    protected $description = 'Dry-run/apply correction for provider timestamps sent as local wall-clock time with a UTC-looking Z format.';

    public function handle(): int
    {
        if (! Schema::hasColumn('transactions', 'original_payload')) {
            $this->error('transactions.original_payload is required for this correction.');

            return self::FAILURE;
        }

        $tenantId = $this->option('tenant');
        $from = $this->option('from');
        $to = $this->option('to');
        $timezone = (string) $this->option('timezone');
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

        $query = DB::table('transactions as t')
            ->join('pos_terminals as term', 't.terminal_id', '=', 'term.id')
            ->leftJoin('tenants as tn', 't.tenant_id', '=', 'tn.id')
            ->where('t.tenant_id', $tenantId)
            ->whereBetween('t.transaction_timestamp', [$from, $to])
            ->whereNotNull('t.original_payload')
            ->when($this->option('provider'), fn ($q, $providerId) => $q->where('term.provider_id', $providerId))
            ->when($this->option('terminal'), fn ($q, $terminalId) => $q->where('t.terminal_id', $terminalId))
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
                'tn.trade_name',
            ]);

        $inspected = 0;
        $eligible = 0;
        $updated = 0;
        $skipped = 0;
        $sample = [];
        $affectedLocalDates = [];

        $this->info(($apply ? 'APPLY' : 'DRY-RUN') . " provider local timestamp correction");
        $this->line("Tenant: {$tenantId}");
        $this->line("Stored UTC window: {$from} to {$to}");
        $this->line("Provider local timezone: {$timezone}");

        $query->chunkById($chunk, function ($rows) use (
            &$inspected,
            &$eligible,
            &$updated,
            &$skipped,
            &$sample,
            &$affectedLocalDates,
            $limit,
            $timezone,
            $apply
        ) {
            foreach ($rows as $row) {
                if ($limit !== null && $inspected >= $limit) {
                    return false;
                }

                $inspected++;
                $proposal = $this->correctionProposal($row, $timezone);

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
                        'old_local_date' => $proposal['old_local_date'],
                        'new_local_date' => $proposal['new_local_date'],
                        'gross' => $proposal['gross_sales'],
                        'net' => $proposal['net_sales'],
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

                    if ($row->submission_uuid && $proposal['new_submission_timestamp'] !== null) {
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
            ['ID', 'Receipt', 'Terminal', 'Old UTC', 'New UTC', 'Old Local Date', 'New Local Date', 'Gross', 'Net'],
            array_map(fn ($row) => [
                $row['id'],
                $row['receipt'],
                $row['terminal'],
                $row['old_utc'],
                $row['new_utc'],
                $row['old_local_date'],
                $row['new_local_date'],
                $row['gross'],
                $row['net'],
            ], $sample)
        );

        $this->newLine();
        $this->line("Inspected: {$inspected}");
        $this->line("Eligible corrections: {$eligible}");
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
            $fromDate = reset($dates);
            $toDate = end($dates);
            $this->call('reports:refresh-daily-transaction-summaries', [
                '--tenant' => $tenantId,
                '--from' => $fromDate,
                '--to' => $toDate,
            ]);
        } elseif (! empty($dates)) {
            $this->warn('Daily summaries were not refreshed. Run reports:refresh-daily-transaction-summaries for the affected dates.');
        }

        return self::SUCCESS;
    }

    private function correctionProposal(object $row, string $timezone): ?array
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

        $localTimestamp = $this->providerLocalTimestamp($payloadTimestamp, $timezone);
        if ($localTimestamp === null) {
            return null;
        }
        $newTransactionTimestamp = $localTimestamp->utc()->format('Y-m-d H:i:s');

        $oldTransactionTimestamp = CarbonImmutable::parse($row->transaction_timestamp, 'UTC')
            ->utc()
            ->format('Y-m-d H:i:s');

        if ($newTransactionTimestamp === $oldTransactionTimestamp) {
            return null;
        }

        $newSubmissionTimestamp = null;
        if ($row->submission_timestamp !== null && trim((string) $row->submission_timestamp) !== '') {
            $currentSubmission = CarbonImmutable::parse($row->submission_timestamp)->format('Y-m-d H:i:s');
            $newSubmissionTimestamp = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $currentSubmission, $timezone)
                ->utc()
                ->format('Y-m-d H:i:s');
        }

        return [
            'id' => $row->id,
            'receipt_no' => $row->receipt_no,
            'serial_number' => $row->serial_number,
            'old_transaction_timestamp' => $oldTransactionTimestamp,
            'new_transaction_timestamp' => $newTransactionTimestamp,
            'new_submission_timestamp' => $newSubmissionTimestamp,
            'old_local_date' => CarbonImmutable::parse($oldTransactionTimestamp, 'UTC')->setTimezone($timezone)->toDateString(),
            'new_local_date' => CarbonImmutable::parse($newTransactionTimestamp, 'UTC')->setTimezone($timezone)->toDateString(),
            'gross_sales' => number_format((float) $row->gross_sales, 2, '.', ''),
            'net_sales' => number_format((float) $row->net_sales, 2, '.', ''),
        ];
    }

    private function providerLocalTimestamp(string $timestamp, string $timezone): ?CarbonImmutable
    {
        $value = trim($timestamp);
        if ($value === '') {
            return null;
        }

        // Treat UTC-looking provider timestamps as local wall-clock values.
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
}
