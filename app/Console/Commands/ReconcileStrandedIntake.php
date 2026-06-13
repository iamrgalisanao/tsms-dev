<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTransactionIntakeJob;
use App\Models\TransactionIntake;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileStrandedIntake extends Command
{
    protected $signature = 'tsms:reconcile-intake
        {--from= : Received-at start date/time for missing processed intake scan}
        {--to= : Received-at end date/time for missing processed intake scan}
        {--tenant= : Limit missing processed intake scan to one tenant ID}
        {--terminal= : Limit missing processed intake scan to one terminal ID}
        {--limit=100 : Maximum processed intake records to inspect}
        {--dry-run : Report processed intakes without matching transactions, without repairing}
        {--repair-missing : Re-queue processed intake records that have no matching transaction row}';

    protected $description = 'Scan for stranded intake records and reconcile processed intake records missing transaction rows';

    public function handle(): int
    {
        if ($this->option('dry-run') || $this->option('repair-missing')) {
            return $this->reconcileProcessedMissingTransactions();
        }

        $threshold = now()->subMinutes(2);

        $stranded = TransactionIntake::query()
            ->where('intake_status', TransactionIntake::INTAKE_STATUS_ACCEPTED)
            ->where('received_at', '<=', $threshold)
            ->get();

        if ($stranded->isEmpty()) {
            $this->info('No stranded accepted intake records found.');
            return self::SUCCESS;
        }

        $this->info("Found {$stranded->count()} stranded intake record(s). Re-dispatching...");

        foreach ($stranded as $intake) {
            try {
                ProcessTransactionIntakeJob::dispatch($intake->id)
                    ->onQueue('transaction-intake')
                    ->afterCommit();

                $intake->update([
                    'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
                    'queued_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::error('ReconcileStrandedIntake: failed to re-dispatch stranded intake', [
                    'intake_id' => $intake->id,
                    'submission_uuid' => $intake->submission_uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function reconcileProcessedMissingTransactions(): int
    {
        $repair = (bool) $this->option('repair-missing');
        $limit = max(1, (int) $this->option('limit'));

        $missing = [];
        $repaired = 0;
        $skipped = 0;
        $failed = 0;

        $ingestServiceAvailable = class_exists('App\\Services\\TransactionIngestService');

        $this->processedIntakeQuery()
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (TransactionIntake $intake) use ($repair, $ingestServiceAvailable, &$missing, &$repaired, &$skipped, &$failed) {
                $transactionId = data_get($intake->payload, 'transaction.transaction_id');

                if (!$transactionId) {
                    $skipped++;
                    return;
                }

                if ($this->transactionExists($intake, $transactionId)) {
                    return;
                }

                $missing[] = [
                    'intake_id' => $intake->id,
                    'submission_uuid' => $intake->submission_uuid,
                    'tenant_id' => $intake->tenant_id,
                    'terminal_id' => $intake->terminal_id,
                    'transaction_id' => $transactionId,
                    'receipt_no' => data_get($intake->payload, 'transaction.receipt_no'),
                    'received_at' => optional($intake->received_at)->toDateTimeString(),
                    'processed_at' => optional($intake->processed_at)->toDateTimeString(),
                ];

                if (!$repair) {
                    return;
                }

                if (!$ingestServiceAvailable) {
                    $skipped++;
                    Log::warning('ReconcileStrandedIntake: repair skipped because TransactionIngestService is unavailable', [
                        'intake_id' => $intake->id,
                        'submission_uuid' => $intake->submission_uuid,
                        'transaction_id' => $transactionId,
                    ]);
                    return;
                }

                try {
                    // Re-queue this intake for normal processing path.
                    $intake->update([
                        'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
                        'processing_status' => TransactionIntake::PROCESSING_STATUS_FAILED_RETRYABLE,
                        'queued_at' => now(),
                    ]);

                    ProcessTransactionIntakeJob::dispatch($intake->id)
                        ->onQueue('transaction-intake')
                        ->afterCommit();

                    $repaired++;
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error('ReconcileStrandedIntake: failed to re-queue missing processed intake', [
                        'intake_id' => $intake->id,
                        'submission_uuid' => $intake->submission_uuid,
                        'transaction_id' => $transactionId,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        if ($missing === []) {
            $this->info('No processed intake records with missing transaction rows were found.');
            return self::SUCCESS;
        }

        $this->table(
            ['Intake ID', 'Submission UUID', 'Tenant', 'Terminal', 'Transaction ID', 'Receipt', 'Received', 'Processed'],
            array_map(fn (array $row) => [
                $row['intake_id'],
                $row['submission_uuid'],
                $row['tenant_id'],
                $row['terminal_id'],
                $row['transaction_id'],
                $row['receipt_no'],
                $row['received_at'],
                $row['processed_at'],
            ], $missing)
        );

        $this->info('Missing processed intake records found: ' . count($missing));

        if ($repair) {
            if (!$ingestServiceAvailable) {
                $this->warn('Repair mode requested, but TransactionIngestService is unavailable in this build. Missing items were reported only.');
            }
            $this->info("Repair summary. Re-queued: {$repaired}. Skipped: {$skipped}. Failed: {$failed}.");
        } else {
            $this->warn('Dry run only. Re-run with --repair-missing to attempt re-queue.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function processedIntakeQuery(): Builder
    {
        $query = TransactionIntake::query()
            ->where('processing_status', TransactionIntake::PROCESSING_STATUS_PROCESSED);

        if ($from = $this->option('from')) {
            $query->where('received_at', '>=', $from);
        }

        if ($to = $this->option('to')) {
            $query->where('received_at', '<=', $to);
        }

        if ($tenant = $this->option('tenant')) {
            $query->where('tenant_id', (int) $tenant);
        }

        if ($terminal = $this->option('terminal')) {
            $query->where('terminal_id', (int) $terminal);
        }

        return $query;
    }

    private function transactionExists(TransactionIntake $intake, string $transactionId): bool
    {
        return DB::table('transactions')
            ->where(function ($query) use ($intake, $transactionId) {
                $query->where('transaction_id', $transactionId)
                    ->orWhere('submission_uuid', $intake->submission_uuid);
            })
            ->exists();
    }
}
