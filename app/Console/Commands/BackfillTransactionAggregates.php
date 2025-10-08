<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillTransactionAggregates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * chunk-size: number of transactions processed per batch
     */
    protected $signature = 'backfill:transaction-aggregates {--chunk-size=1000} {--start-id=0}';

    /**
     * The console command description.
     */
    protected $description = 'Backfill aggregated totals (discounts, vatable, vat) from child tables into transactions (id = transaction_pk) in idempotent, chunked batches.';

    public function handle()
    {
        $chunkSize = (int) $this->option('chunk-size');
        $startId = (int) $this->option('start-id');

        $this->info("Starting backfill with chunk size={$chunkSize}, start_id={$startId}");

        // Process transactions in ascending id order to allow resume
        $query = DB::table('transactions')->select('id')->where('id', '>=', $startId)->orderBy('id');

        $total = $query->count();
        $this->info("Transactions to consider: {$total}");

        $processed = 0;

        $query->chunk($chunkSize, function ($transactions) use (&$processed) {
            $ids = collect($transactions)->pluck('id')->values()->all();
            if (empty($ids)) {
                return false;
            }

            // Aggregate adjustments by transaction_pk
            $adjustments = DB::table('transaction_adjustments')
                ->select('transaction_pk', DB::raw('SUM(COALESCE(amount,0)) as total_amount'), 'adjustment_type')
                ->whereIn('transaction_pk', $ids)
                ->groupBy('transaction_pk', 'adjustment_type')
                ->get()
                ->groupBy('transaction_pk');

            // Aggregate taxes by transaction_pk
            $taxes = DB::table('transaction_taxes')
                ->select('transaction_pk', DB::raw('SUM(COALESCE(vatable_sales,0)) as vatable_sales'), DB::raw('SUM(COALESCE(vat_amount,0)) as vat_amount'), DB::raw('SUM(COALESCE(sc_vat_exempt_sales,0)) as sc_vat_exempt_sales'))
                ->whereIn('transaction_pk', $ids)
                ->groupBy('transaction_pk')
                ->get()
                ->keyBy('transaction_pk');

            // Prepare updates per transaction
            $updates = [];
            foreach ($ids as $id) {
                $row = [];

                // discounts
                $promo = 0; $senior = 0; $pwd = 0;
                if (isset($adjustments[$id])) {
                    foreach ($adjustments[$id] as $adj) {
                        $type = $adj->adjustment_type;
                        $amt = (float) $adj->total_amount;
                        if ($type === 'promo') $promo += $amt;
                        elseif ($type === 'senior') $senior += $amt;
                        elseif ($type === 'pwd') $pwd += $amt;
                        // ignore other types here; future types can be added
                    }
                }

                if (Schema::hasColumn('transactions', 'promo_discount')) {
                    $row['promo_discount'] = $promo;
                }
                if (Schema::hasColumn('transactions', 'senior_discount')) {
                    $row['senior_discount'] = $senior;
                }
                if (Schema::hasColumn('transactions', 'pwd_discount')) {
                    $row['pwd_discount'] = $pwd;
                }

                // taxes
                if (isset($taxes[$id])) {
                    $t = $taxes[$id];
                    $row['vatable_sales'] = $t->vatable_sales ?? 0;
                    $row['vat_amount'] = $t->vat_amount ?? 0;
                    $row['sc_vat_exempt_sales'] = $t->sc_vat_exempt_sales ?? 0;
                }

                if (!empty($row)) {
                    $updates[$id] = $row;
                }
            }

            // Apply updates idempotently
            foreach (array_chunk($updates, 200) as $chunk) {
                foreach ($chunk as $txId => $cols) {
                    // Only update when values differ to minimize writes
                    $current = DB::table('transactions')->where('id', $txId)->first($this->columnsToSelect(array_keys($cols)));
                    $doUpdate = false;
                    $set = [];
                    foreach ($cols as $k => $v) {
                        $currVal = $current->{$k} ?? 0;
                        if ((float) $currVal !== (float) $v) {
                            $set[$k] = $v;
                            $doUpdate = true;
                        }
                    }
                    if ($doUpdate) {
                        DB::table('transactions')->where('id', $txId)->update($set + ['updated_at' => now()]);
                        $this->info("Updated tx {$txId}: " . implode(', ', array_map(function($k, $val){ return "$k=$val"; }, array_keys($set), $set)));
                    }
                }
            }

            $processed += count($ids);
            $this->info("Processed {$processed} transactions...");
        });

        $this->info('Backfill complete');
        return 0;
    }

    protected function columnsToSelect(array $cols)
    {
        // always include id to read
        return array_merge(['id'], $cols);
    }
}
