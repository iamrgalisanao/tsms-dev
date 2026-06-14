<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DuplicateReceiptReport extends Command
{
    protected $signature = 'tsms:duplicate-receipts
        {--from= : Transaction timestamp start date/time}
        {--to= : Transaction timestamp end date/time}
        {--tenant= : Limit to one tenant ID}
        {--terminal= : Limit to one terminal ID}
        {--limit=100 : Maximum duplicate groups or legacy conflicts to show per section}
        {--json : Emit machine-readable JSON instead of tables}';

    protected $description = 'Read-only duplicate receipt monitoring/audit report by tenant, terminal, receipt, and transaction date';

    public function handle(): int
    {
        if (! Schema::hasTable('transactions') || ! Schema::hasColumn('transactions', 'receipt_no')) {
            $this->warn('transactions.receipt_no is not available.');
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $duplicateGroups = $this->duplicateReceiptGroups($limit);
        $legacyConflicts = $this->legacyPayloadReceiptConflicts($limit);

        if ($this->option('json')) {
            $this->line(json_encode([
                'duplicate_groups' => $duplicateGroups,
                'legacy_payload_conflicts' => $legacyConflicts,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Duplicate receipt monitoring / audit report');

        if ($duplicateGroups === []) {
            $this->line('No duplicate populated receipt groups found.');
        } else {
            $this->warn('Duplicate populated receipt groups: ' . count($duplicateGroups));
            $this->table(
                ['Tenant', 'Terminal', 'Receipt', 'Date', 'Count', 'Transaction IDs', 'Hardware IDs'],
                array_map(fn (array $row) => [
                    $row['tenant_id'],
                    $row['terminal_id'],
                    $row['receipt_no'],
                    $row['transaction_date'],
                    $row['count'],
                    implode(', ', $row['transaction_ids']),
                    implode(', ', $row['hardware_ids']),
                ], $duplicateGroups)
            );
        }

        if ($legacyConflicts === []) {
            $this->line('No legacy payload receipt conflicts found.');
        } else {
            $this->warn('Legacy payload receipt conflicts: ' . count($legacyConflicts));
            $this->table(
                ['Legacy TX PK', 'Tenant', 'Terminal', 'Receipt', 'Date', 'Legacy TXID', 'Existing TXID', 'Existing TX PK'],
                array_map(fn (array $row) => [
                    $row['legacy_transaction_pk'],
                    $row['tenant_id'],
                    $row['terminal_id'],
                    $row['receipt_no'],
                    $row['transaction_date'],
                    $row['legacy_transaction_id'],
                    $row['existing_transaction_id'],
                    $row['existing_transaction_pk'],
                ], $legacyConflicts)
            );
        }

        return self::SUCCESS;
    }

    private function duplicateReceiptGroups(int $limit): array
    {
        $groups = $this->baseTransactionQuery()
            ->select([
                'tenant_id',
                'terminal_id',
                DB::raw('TRIM(receipt_no) as receipt_no'),
                DB::raw('DATE(transaction_timestamp) as transaction_date'),
                DB::raw('COUNT(*) as tx_count'),
            ])
            ->whereNotNull('receipt_no')
            ->whereRaw("TRIM(receipt_no) <> ''")
            ->groupBy('tenant_id', 'terminal_id', DB::raw('TRIM(receipt_no)'), DB::raw('DATE(transaction_timestamp)'))
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('tx_count')
            ->limit($limit)
            ->get();

        return $groups
            ->map(function ($group) {
                $transactions = DB::table('transactions')
                    ->select(['id', 'transaction_id', 'hardware_id', 'gross_sales', 'net_sales', 'transaction_timestamp'])
                    ->where('tenant_id', $group->tenant_id)
                    ->where('terminal_id', $group->terminal_id)
                    ->whereRaw('TRIM(receipt_no) = TRIM(?)', [$group->receipt_no])
                    ->whereDate('transaction_timestamp', $group->transaction_date)
                    ->orderBy('transaction_timestamp')
                    ->get();

                return [
                    'tenant_id' => $group->tenant_id,
                    'terminal_id' => $group->terminal_id,
                    'receipt_no' => $group->receipt_no,
                    'transaction_date' => $group->transaction_date,
                    'count' => (int) $group->tx_count,
                    'transaction_pks' => $transactions->pluck('id')->all(),
                    'transaction_ids' => $transactions->pluck('transaction_id')->filter()->values()->all(),
                    'hardware_ids' => $transactions->pluck('hardware_id')->filter()->unique()->values()->all(),
                    'amounts' => $transactions->map(fn ($tx) => [
                        'gross_sales' => $tx->gross_sales,
                        'net_sales' => $tx->net_sales,
                        'transaction_timestamp' => $tx->transaction_timestamp,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function legacyPayloadReceiptConflicts(int $limit): array
    {
        if (! Schema::hasColumn('transactions', 'original_payload')) {
            return [];
        }

        $conflicts = [];

        $this->baseTransactionQuery()
            ->select(['id', 'tenant_id', 'terminal_id', 'transaction_id', 'transaction_timestamp', 'original_payload'])
            ->whereNull('receipt_no')
            ->whereNotNull('original_payload')
            ->orderBy('id')
            ->chunkById(500, function ($transactions) use (&$conflicts, $limit) {
                foreach ($transactions as $transaction) {
                    if (count($conflicts) >= $limit) {
                        return false;
                    }

                    $receiptNo = $this->receiptNoFromPayload($transaction->original_payload);
                    if ($receiptNo === null || empty($transaction->transaction_timestamp)) {
                        continue;
                    }

                    $date = date('Y-m-d', strtotime((string) $transaction->transaction_timestamp));
                    $existing = DB::table('transactions')
                        ->select(['id', 'transaction_id', 'hardware_id', 'gross_sales', 'net_sales', 'transaction_timestamp'])
                        ->where('tenant_id', $transaction->tenant_id)
                        ->where('terminal_id', $transaction->terminal_id)
                        ->whereRaw('TRIM(receipt_no) = TRIM(?)', [$receiptNo])
                        ->whereDate('transaction_timestamp', $date)
                        ->orderBy('transaction_timestamp')
                        ->first();

                    if (! $existing) {
                        continue;
                    }

                    $conflicts[] = [
                        'legacy_transaction_pk' => $transaction->id,
                        'tenant_id' => $transaction->tenant_id,
                        'terminal_id' => $transaction->terminal_id,
                        'receipt_no' => $receiptNo,
                        'transaction_date' => $date,
                        'legacy_transaction_id' => $transaction->transaction_id,
                        'existing_transaction_pk' => $existing->id,
                        'existing_transaction_id' => $existing->transaction_id,
                        'existing_hardware_id' => $existing->hardware_id,
                        'existing_gross_sales' => $existing->gross_sales,
                        'existing_net_sales' => $existing->net_sales,
                        'existing_transaction_timestamp' => $existing->transaction_timestamp,
                    ];
                }

                return true;
            });

        return $conflicts;
    }

    private function baseTransactionQuery()
    {
        $query = DB::table('transactions');

        if ($from = $this->option('from')) {
            $query->where('transaction_timestamp', '>=', $from);
        }

        if ($to = $this->option('to')) {
            $query->where('transaction_timestamp', '<=', $to);
        }

        if ($tenant = $this->option('tenant')) {
            $query->where('tenant_id', (int) $tenant);
        }

        if ($terminal = $this->option('terminal')) {
            $query->where('terminal_id', (int) $terminal);
        }

        return $query;
    }

    private function receiptNoFromPayload(?string $payload): ?string
    {
        if ($payload === null || trim($payload) === '') {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return null;
        }

        $receiptNo = data_get($decoded, 'receipt_no') ?? data_get($decoded, 'transaction.receipt_no');
        if (! is_string($receiptNo) && ! is_numeric($receiptNo)) {
            return null;
        }

        $receiptNo = trim((string) $receiptNo);

        return $receiptNo === '' ? null : $receiptNo;
    }
}
