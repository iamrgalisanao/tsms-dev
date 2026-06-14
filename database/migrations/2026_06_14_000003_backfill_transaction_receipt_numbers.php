<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('transactions')
            || ! Schema::hasColumn('transactions', 'receipt_no')
            || ! Schema::hasColumn('transactions', 'original_payload')
        ) {
            return;
        }

        DB::table('transactions')
            ->select(['id', 'original_payload'])
            ->whereNull('receipt_no')
            ->whereNotNull('original_payload')
            ->orderBy('id')
            ->chunkById(500, function ($transactions) {
                foreach ($transactions as $transaction) {
                    $payload = json_decode((string) $transaction->original_payload, true);
                    if (! is_array($payload)) {
                        continue;
                    }

                    $receiptNo = data_get($payload, 'receipt_no')
                        ?? data_get($payload, 'transaction.receipt_no');

                    if (! is_string($receiptNo) && ! is_numeric($receiptNo)) {
                        continue;
                    }

                    $receiptNo = trim((string) $receiptNo);
                    if ($receiptNo === '') {
                        continue;
                    }

                    DB::table('transactions')
                        ->where('id', $transaction->id)
                        ->whereNull('receipt_no')
                        ->update([
                            'receipt_no' => $receiptNo,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Data backfill only. Do not erase receipt numbers on rollback.
    }
};
