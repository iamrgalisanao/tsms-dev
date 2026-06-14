<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DuplicateReceiptMonitorService
{
    public function report(array $filters = []): array
    {
        if (! Schema::hasTable('transactions') || ! Schema::hasColumn('transactions', 'receipt_no')) {
            return [
                'duplicate_groups' => [],
                'legacy_payload_conflicts' => [],
                'unavailable' => true,
            ];
        }

        $limit = max(1, (int) ($filters['limit'] ?? 100));

        return [
            'duplicate_groups' => $this->duplicateReceiptGroups($filters, $limit),
            'legacy_payload_conflicts' => $this->legacyPayloadReceiptConflicts($filters, $limit),
            'unavailable' => false,
        ];
    }

    private function duplicateReceiptGroups(array $filters, int $limit): array
    {
        $groups = $this->baseTransactionQuery($filters)
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

    private function legacyPayloadReceiptConflicts(array $filters, int $limit): array
    {
        if (! Schema::hasColumn('transactions', 'original_payload')) {
            return [];
        }

        $conflicts = [];

        $this->baseTransactionQuery($filters)
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

    private function baseTransactionQuery(array $filters)
    {
        $query = DB::table('transactions');

        if ($from = ($filters['from'] ?? null)) {
            $query->where('transaction_timestamp', '>=', $from);
        }

        if ($to = ($filters['to'] ?? null)) {
            $query->where('transaction_timestamp', '<=', $to);
        }

        if ($tenant = ($filters['tenant'] ?? null)) {
            $query->where('tenant_id', (int) $tenant);
        }

        if ($terminal = ($filters['terminal'] ?? null)) {
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
