<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TxnPkIntegrityReport extends Command {
    protected $signature = 'txn:pk-integrity';
    protected $description = 'Report transaction_pk integrity across child tables (nulls, orphans, counts)';
    public function handle() {
        $tables = [
            'transaction_taxes' => 'taxes',
            'transaction_adjustments' => 'adjustments',
            'transaction_jobs' => 'jobs',
            'transaction_validations' => 'validations',
        ];
        $this->info('Transaction PK integrity report');

        foreach ($tables as $t => $label) {
            if (!DB::getSchemaBuilder()->hasTable($t)) { $this->warn("Skipping missing table: $t"); continue; }
            $rows = DB::table($t)->count();
            $nulls = DB::table($t)->whereNull('transaction_pk')->count();
            $orphans = DB::table($t.' as c')
                ->leftJoin('transactions as t','c.transaction_pk','=','t.id')
                ->whereNull('t.id')
                ->count();
            $pctNull = $rows ? round(($nulls / $rows)*100,2) : 0;
            $pctOrphan = $rows ? round(($orphans / $rows)*100,2) : 0;
            $this->line(sprintf('%s (%s): total=%d nulls=%d (%s%%) orphans=%d (%s%%)', $t, $label, $rows, $nulls, $pctNull, $orphans, $pctOrphan));
        }

        // High-level summary
        $totalChildren = array_sum(array_map(fn($t)=> DB::getSchemaBuilder()->hasTable($t)? DB::table($t)->count():0, array_keys($tables)));
        $this->info('Total child rows: '.$totalChildren);
        $this->comment('Orphans >0 indicate missing parent transactions; investigate ingestion / deletion order.');
        $this->comment('Nulls >0 indicate incomplete backfill or race conditions before pk assignment.');
        return 0;
    }
}
