<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTransactionIntakeJob;
use App\Jobs\ProcessTransactionJob;
use App\Models\TransactionIntake;
use App\Services\TransactionIngestService;
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
        {--repair-missing : Re-ingest processed intake records that have no matching transaction row}';

    protected $description = 'Scan for stranded intake records and repair processed intake records missing transaction rows';

    public function handle(TransactionIngestService $ingestService): int
    {
        if ($this->option('dry-run') || $this->option('repair-missing')) {
            return $this->reconcileProcessedMissingTransactions($ingestService);
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

    private function reconcileProcessedMissingTransactions(TransactionIngestService $ingestService): int
    {
        $repair = (bool) $this->option('repair-missing');
        $limit = max(1, (int) $this->option('limit'));

        $missing = [];
        $repaired = 0;
        $skipped = 0;
        $failed = 0;

        $this->processedIntakeQuery()
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (TransactionIntake $intake) use ($ingestService, $repair, &$missing, &$repaired, &$skipped, &$failed) {
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

                $result = $this->repairProcessedIntake($intake, $transactionId, $ingestService);
                if ($result === 'repaired') {
                    $repaired++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                } else {
                    $failed++;
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
            $this->info("Repair complete. Repaired: {$repaired}. Skipped: {$skipped}. Failed: {$failed}.");
        } else {
            $this->warn('Dry run only. Re-run with --repair-missing to re-ingest and queue transaction processing.');
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

    private function repairProcessedIntake(
        TransactionIntake $intake,
        string $transactionId,
        TransactionIngestService $ingestService
    ): string {
        $transactionPayload = data_get($intake->payload, 'transaction');

        if (! is_array($transactionPayload)) {
            Log::warning('ReconcileStrandedIntake: missing transaction payload for processed intake repair', [
                'intake_id' => $intake->id,
                'submission_uuid' => $intake->submission_uuid,
            ]);

            return 'skipped';
        }

        try {
            $payload = array_merge($transactionPayload, [
                'submission_uuid' => $intake->submission_uuid,
                'submission_timestamp' => data_get($intake->payload, 'submission_timestamp'),
                'tenant_id' => $intake->tenant_id,
                'terminal_id' => $intake->terminal_id,
                'payload_checksum' => $intake->payload_checksum,
            ]);

            $result = $ingestService->ingest($payload);
            $status = $result['status'] ?? 'failed';

            if (in_array($status, ['accepted', 'success', 'already_processed'], true) && isset($result['id'])) {
                if ($status === 'accepted') {
                    $shard = (int) ($intake->tenant_id % 8);
                    ProcessTransactionJob::dispatch($result['id'])
                        ->onQueue('transaction-processing:s' . $shard)
                        ->afterCommit();
                }

                Log::info('ReconcileStrandedIntake: repaired processed intake missing transaction row', [
                    'intake_id' => $intake->id,
                    'submission_uuid' => $intake->submission_uuid,
                    'transaction_id' => $transactionId,
                    'transaction_pk' => $result['id'],
                    'status' => $status,
                ]);

                return 'repaired';
            }

            Log::warning('ReconcileStrandedIntake: failed to repair processed intake missing transaction row', [
                'intake_id' => $intake->id,
                'submission_uuid' => $intake->submission_uuid,
                'transaction_id' => $transactionId,
                'result' => $result,
            ]);

            return 'failed';
        } catch (\Throwable $e) {
            Log::error('ReconcileStrandedIntake: exception while repairing processed intake', [
                'intake_id' => $intake->id,
                'submission_uuid' => $intake->submission_uuid,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }
}
