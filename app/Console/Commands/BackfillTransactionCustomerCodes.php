<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTransactionCustomerCodes extends Command
{
    protected $signature = 'tsms:backfill-transaction-customer-codes {--chunk=1000} {--dry-run} {--include-mismatched}';
    protected $description = 'Backfill transactions.customer_code from tenants.customer_code for data hygiene';

    public function handle(): int
    {
    $chunk = (int) $this->option('chunk');
    $dry   = (bool) $this->option('dry-run');
    $includeMismatch = (bool) $this->option('include-mismatched');

        $this->info('Starting backfill of transactions.customer_code from tenants.customer_code');

        $total = 0;

        DB::table('transactions')
            ->select('transactions.id', 'transactions.tenant_id', 'transactions.customer_code', 'tenants.customer_code as tenant_code')
            ->leftJoin('tenants', 'tenants.id', '=', 'transactions.tenant_id')
            ->where(function ($q) use ($includeMismatch) {
                $q->whereNull('transactions.customer_code')
                  ->orWhere('transactions.customer_code', '=', '');
                if ($includeMismatch) {
                    $q->orWhereColumn('transactions.customer_code', '<>', 'tenants.customer_code');
                }
            })
            ->orderBy('transactions.id')
            ->chunkById($chunk, function ($rows) use (&$total, $dry) {
                $updates = [];
                foreach ($rows as $row) {
                    if (!empty($row->tenant_code)) {
                        $updates[$row->id] = $row->tenant_code;
                    }
                }

                if (empty($updates)) {
                    return true;
                }

                $total += count($updates);

                if ($dry) {
                    $this->line("[dry-run] Would update " . count($updates) . " rows");
                    return true;
                }

                // Perform updates in a single query per chunk using CASE
                $ids = implode(',', array_map('intval', array_keys($updates)));
                $cases = collect($updates)->map(function ($code, $id) {
                    // SQL-standard escaping for single quotes
                    $code = str_replace("'", "''", $code);
                    return "WHEN id = {$id} THEN '{$code}'";
                })->implode(' ');

                $sql = "UPDATE transactions SET customer_code = CASE {$cases} END WHERE id IN ({$ids})";
                DB::statement($sql);

                $this->line('Updated ' . count($updates) . ' rows');
            });

        $this->info("Backfill complete. Updated {$total} rows.");
        return self::SUCCESS;
    }
}
