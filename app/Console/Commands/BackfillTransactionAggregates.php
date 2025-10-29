<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\FeatureFlags;

class BackfillTransactionAggregates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * chunk-size: number of transactions processed per batch
     */
    protected $signature = 'backfill:transaction-aggregates {--chunk-size=1000} {--start-id=0} {--dry-run}';

    /**
     * The console command description.
     */
    protected $description = 'Backfill aggregated totals (discounts, vatable, vat) from child tables into transactions (id = transaction_pk) in idempotent, chunked batches.';

    public function handle()
    {
        $chunkSize = (int) $this->option('chunk-size');
        $startId = (int) $this->option('start-id');

    $dryRun = (bool) $this->option('dry-run');
        $this->info("Starting backfill with chunk size={$chunkSize}, start_id={$startId}, dry_run=" . ($dryRun ? 'true' : 'false'));

        // Honor global feature flag: when computation-based validation is disabled
        // we must avoid computing and writing aggregated monetary fields back to
        // the `transactions` table to preserve passive ingestion semantics.
        if (!FeatureFlags::computationValidationEnabled()) {
            $this->info('Computation validation disabled via feature flag; skipping aggregate backfill to avoid mutating transactions.');
            return 0;
        }

        // Process transactions in ascending id order to allow resume
        $query = DB::table('transactions')->select('id')->where('id', '>=', $startId)->orderBy('id');

        $total = $query->count();
        $this->info("Transactions to consider: {$total}");

    $processed = 0;
    $wouldUpdateTotal = 0;
    $sampleChanges = [];
    $anomalies = [];
    $anomalyCount = 0;

    $query->chunk($chunkSize, function ($transactions) use (&$processed, &$wouldUpdateTotal, &$sampleChanges, &$anomalies, &$anomalyCount, $dryRun) {
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

            // Aggregate taxes by transaction_pk using normalized schema (tax_type + amount).
            // We compute conditional sums per tax_type (CASE WHEN) so we don't rely on
            // non-existent columns in transaction_taxes across environments.
            $taxTable = 'transaction_taxes';
            // Use case-insensitive and pattern matching for tax_type because
            // historical values may use different naming conventions
            // (e.g. 'VATABLE_SALES', 'VAT', 'SC_VAT_EXEMPT_SALES').
            $taxes = DB::table($taxTable)
                ->select('transaction_pk')
                ->selectRaw("SUM(CASE WHEN LOWER(tax_type) LIKE '%vatable%' THEN COALESCE(amount,0) ELSE 0 END) as vatable_sales")
                ->selectRaw("SUM(CASE WHEN LOWER(tax_type) = 'vat' THEN COALESCE(amount,0) ELSE 0 END) as vat_amount")
                ->selectRaw("SUM(CASE WHEN LOWER(tax_type) LIKE '%sc_vat_exempt%' THEN COALESCE(amount,0) ELSE 0 END) as sc_vat_exempt_sales")
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
                        $type = (string) $adj->adjustment_type;
                        $amt = (float) $adj->total_amount; // alias from SQL query

                        // Normalize adjustment_type for robust matching across historical variants
                        $typeNorm = strtolower(trim($type));
                        $typeNorm = str_replace(' ', '_', $typeNorm);
                        $typeNorm = preg_replace('/[^a-z0-9_]/', '', $typeNorm);

                        // Match common variants for promo, senior, and pwd discounts
                        if (in_array($typeNorm, ['promo', 'promo_discount', 'discount_promo'])) {
                            $promo += $amt;
                        } elseif (in_array($typeNorm, ['senior', 'senior_discount', 'senior_citizen_discount'])) {
                            $senior += $amt;
                        } elseif (in_array($typeNorm, ['pwd', 'pwd_discount', 'pwddiscount', 'pwd_citizen_discount'])) {
                            $pwd += $amt;
                        }
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

            // Apply updates idempotently (or simulate when dry-run)
            // chunk updates but preserve transaction id keys so we log and apply
            // updates against the correct transaction ids (array_chunk would
            // otherwise reindex numeric keys and produce tx ids like 0,1,2...).
            foreach (array_chunk($updates, 200, true) as $chunk) {
                foreach ($chunk as $txId => $cols) {
                    // Read current values
                    $current = DB::table('transactions')->where('id', $txId)->first($this->columnsToSelect(array_keys($cols)));
                    $doUpdate = false;
                    $set = [];
                    foreach ($cols as $k => $v) {
                        $currVal = $current->{$k} ?? 0;
                        // compare rounded values to 2 decimals to avoid noise from
                        // tiny decimal differences or representation issues.
                        $roundedCurr = round((float) $currVal, 2);
                        $roundedNew = round((float) $v, 2);
                        // If computed value is zero but stored value is non-zero,
                        // this looks like an anomaly: do not overwrite automatically.
                        if ($roundedNew === 0.00 && $roundedCurr !== 0.00) {
                            $anomalyCount++;
                            if (count($anomalies) < 20) {
                                $anomalies[] = ['id' => $txId, 'column' => $k, 'current' => $roundedCurr, 'computed' => $roundedNew];
                            }
                            // skip applying this column update
                            continue;
                        }
                        if ($roundedCurr !== $roundedNew) {
                            $set[$k] = $v;
                            $doUpdate = true;
                        }
                    }
                    if ($doUpdate) {
                        $wouldUpdateTotal++;
                        if ($dryRun) {
                            // collect a small sample of changes to display
                            if (count($sampleChanges) < 10) {
                                $sampleChanges[] = ['id' => $txId, 'changes' => $set, 'current' => $current];
                            }
                            // do not write
                        } else {
                            DB::table('transactions')->where('id', $txId)->update($set + ['updated_at' => now()]);
                            $this->info("Updated tx {$txId}: " . implode(', ', array_map(function($k, $val){ return "$k=$val"; }, array_keys($set), $set)));
                        }
                    }
                }
            }

            $processed += count($ids);
            $this->info("Processed {$processed} transactions... (would-update-so-far={$wouldUpdateTotal})");
        });

        $this->info('Backfill complete');
        if ($dryRun) {
            $this->info("Dry-run summary: total transactions scanned={$processed}, transactions that would be updated={$wouldUpdateTotal}");
            if (!empty($sampleChanges)) {
                $this->info("Sample changes (up to 10):");
                foreach ($sampleChanges as $s) {
                    $this->line("- tx {$s['id']}");
                    foreach ($s['changes'] as $k => $v) {
                        $curr = $s['current']->{$k} ?? 0;
                        $this->line("    {$k}: {$curr} -> {$v}");
                    }
                }
            }
            if ($anomalyCount > 0) {
                $this->info("Anomalies detected (computed zero while stored non-zero) = {$anomalyCount}");
                if (!empty($anomalies)) {
                    $this->info("Sample anomalies (up to 20):");
                    foreach ($anomalies as $a) {
                        $this->line("- tx {$a['id']}: {$a['column']}: {$a['current']} -> {$a['computed']}");
                    }
                }
            }
        }
        return 0;
    }

    protected function columnsToSelect(array $cols)
    {
        // always include id to read
        return array_merge(['id'], $cols);
    }
}
