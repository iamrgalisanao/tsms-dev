<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTransactionIntakeJob;
use App\Models\TransactionIntake;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileStrandedIntake extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tsms:reconcile-intake';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan for and re-dispatch stranded transaction intake records (SLA: 2 minutes)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $threshold = now()->subMinutes(2);

        $stranded = TransactionIntake::where('intake_status', TransactionIntake::INTAKE_STATUS_ACCEPTED)
            ->where('received_at', '<=', $threshold)
            ->get();

        if ($stranded->isEmpty()) {
            return 0;
        }

        $this->info("Found {$stranded->count()} stranded intake records. Re-dispatching...");

        foreach ($stranded as $intake) {
            try {
                Log::info('ReconcileStrandedIntake: Re-dispatching stranded record', [
                    'intake_id' => $intake->id,
                    'submission_uuid' => $intake->submission_uuid,
                    'received_at' => $intake->received_at,
                ]);

                ProcessTransactionIntakeJob::dispatch($intake->id)
                    ->onQueue('transaction-intake')
                    ->afterCommit();

                $intake->update([
                    'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
                    'queued_at' => now(),
                ]);
            } catch (\Exception $e) {
                Log::error('ReconcileStrandedIntake: Failed to re-dispatch record', [
                    'intake_id' => $intake->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return 0;
    }
}
