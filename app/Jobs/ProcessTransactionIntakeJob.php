<?php

namespace App\Jobs;

use App\Models\TransactionIntake;
use App\Services\TransactionIngestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTransactionIntakeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $intakeId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $intakeId)
    {
        $this->intakeId = $intakeId;
    }

    /**
     * Execute the job.
     */
    public function handle(TransactionIngestService $ingestService): void
    {
        $intake = TransactionIntake::find($this->intakeId);

        if (!$intake) {
            Log::error('ProcessTransactionIntakeJob: Intake record not found', ['id' => $this->intakeId]);
            return;
        }

        if ($intake->processing_status === TransactionIntake::PROCESSING_STATUS_PROCESSED) {
            Log::info('ProcessTransactionIntakeJob: Already processed', ['intake_id' => $this->intakeId]);
            return;
        }

        $intake->update([
            'processing_status' => TransactionIntake::PROCESSING_STATUS_PROCESSING,
            'attempt_count' => $intake->attempt_count + 1,
        ]);

        try {
            // Prepare payload for the existing ingest service
            // We include the submission_uuid and other metadata
            $payload = array_merge($intake->payload['transaction'], [
                'submission_uuid' => $intake->submission_uuid,
                'submission_timestamp' => $intake->payload['submission_timestamp'],
                'tenant_id' => $intake->tenant_id,
                'terminal_id' => $intake->terminal_id,
                'payload_checksum' => $intake->payload_checksum,
            ]);

            // Call the existing ingest service which handles normalization, transactions, adjustments, and taxes.
            $result = $ingestService->ingest($payload);

            if ($result['status'] === 'accepted' || $result['status'] === 'already_processed') {
                $status = ($result['status'] === 'already_processed') 
                    ? TransactionIntake::PROCESSING_STATUS_DUPLICATE 
                    : TransactionIntake::PROCESSING_STATUS_PROCESSED;

                $intake->update([
                    'processing_status' => $status,
                    'processed_at' => now(),
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]);

                // Trigger the second stage: ProcessTransactionJob (Validation/Audit)
                if (isset($result['id'])) {
                    $shard = (int) ($intake->tenant_id % 8);
                    ProcessTransactionJob::dispatch($result['id'])
                        ->onQueue('transaction-processing:s' . $shard)
                        ->afterCommit();
                }

                Log::info('ProcessTransactionIntakeJob: Success', [
                    'intake_id' => $this->intakeId,
                    'status' => $status,
                    'transaction_pk' => $result['id'] ?? null
                ]);
            } else {
                // Persistent failure or business logic error
                $intake->update([
                    'processing_status' => TransactionIntake::PROCESSING_STATUS_FAILED_PERMANENT,
                    'last_error_code' => $result['message'] ?? 'INGEST_FAILED',
                    'last_error_message' => $result['details'] ?? 'Unknown ingest failure',
                ]);

                Log::warning('ProcessTransactionIntakeJob: Permanent failure', [
                    'intake_id' => $this->intakeId,
                    'message' => $result['message'] ?? 'none'
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ProcessTransactionIntakeJob: Exception', [
                'intake_id' => $this->intakeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $intake->update([
                'processing_status' => TransactionIntake::PROCESSING_STATUS_FAILED_RETRYABLE,
                'last_error_code' => 'EXCEPTION',
                'last_error_message' => $e->getMessage(),
            ]);

            // Rethrow to trigger queue retry if within limits
            throw $e;
        }
    }
}
