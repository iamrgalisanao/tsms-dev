<?php

namespace App\Console\Commands;

use App\Models\TransactionIntake;
use App\Jobs\ProcessTransactionIntakeJob;
use Illuminate\Console\Command;

class RecategorizeDuplicates extends Command
{
    protected $signature = 'tsms:recover-duplicates {--limit=500 : Number of records to process per batch}';
    protected $description = 'Recover intake records that failed due to timezone-based conflict detection gaps.';

    public function handle()
    {
        $this->info('Starting duplicate recovery...');

        $failedRecords = TransactionIntake::where('processing_status', 'FAILED_PERMANENT')
            ->where('last_error_code', 'insert ignored but no existing transaction found')
            ->limit($this->option('limit'))
            ->get();

        if ($failedRecords->isEmpty()) {
            $this->info('No stranded duplicates found.');
            return;
        }

        $bar = $this->output->createProgressBar(count($failedRecords));
        $bar->start();

        foreach ($failedRecords as $record) {
            // Simply re-dispatch. The updated service logic will now correctly 
            // identify these as 'already_processed' (status: accepted).
            ProcessTransactionIntakeJob::dispatch($record->id)
                ->onQueue('transaction-intake');

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Dispatched ' . count($failedRecords) . ' records for reprocessing.');
        $this->info('The Fail Rate on your dashboard should drop as these jobs complete.');
    }
}
