<?php

namespace App\Jobs;

use App\Models\TransactionIntake;
use App\Services\IngestionQueueRouter;
use App\Services\TransactionIngestService;
use App\Support\Metrics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\Middleware\WithoutOverlapping;

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
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string)$this->intakeId))->releaseAfter(10)];
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

        if (in_array($intake->processing_status, [
            TransactionIntake::PROCESSING_STATUS_DUPLICATE,
            TransactionIntake::PROCESSING_STATUS_PROCESSED
        ])) {
            Log::info('ProcessTransactionIntakeJob: Already in terminal state', [
                'intake_id' => $this->intakeId,
                'status' => $intake->processing_status
            ]);
            return;
        }

        $initialStatus = $intake->processing_status;

        $intake->update([
            'processing_status' => TransactionIntake::PROCESSING_STATUS_PROCESSING,
            'attempt_count' => $intake->attempt_count + 1,
        ]);

        $startTime = microtime(true);

        try {
            // Prepare payload for the existing ingest service
            $payload = array_merge($intake->payload['transaction'], [
                'submission_uuid' => $intake->submission_uuid,
                'submission_timestamp' => $intake->payload['submission_timestamp'],
                'tenant_id' => $intake->tenant_id,
                'terminal_id' => $intake->terminal_id,
                'payload_checksum' => $intake->payload_checksum,
            ]);

            $isShadowMode = config('tsms.testing.capture_only') === true;
            $result = null;

            if ($isShadowMode) {
                \Illuminate\Support\Facades\DB::beginTransaction();
                $result = $ingestService->ingest($payload);
                
                // Shadow Audit Logging
                Log::channel('shadow_audit')->info('SHADOW_MODE_RESULT', [
                    'intake_id' => $this->intakeId,
                    'submission_uuid' => $intake->submission_uuid,
                    'result' => $result
                ]);
                
                \Illuminate\Support\Facades\DB::rollBack();
                
                // For Shadow Mode, we treat valid outcomes as "PROCESSED" in the intake table
                // but we don't actually persist the business rows.
                $status = $result['status'] ?? 'failed';
            } else {
                $result = $ingestService->ingest($payload);
                $status = $result['status'] ?? 'failed';
            }

            $isDuplicate = $status === 'duplicate' || ($result['message'] ?? '') === 'duplicate_receipt_conflict';

            if ($status === 'success' || $status === 'accepted' || $status === 'already_processed' || $isDuplicate) {
                $finalStatus = $isDuplicate 
                    ? TransactionIntake::PROCESSING_STATUS_DUPLICATE 
                    : TransactionIntake::PROCESSING_STATUS_PROCESSED;

                $intake->update([
                    'processing_status' => $finalStatus,
                    'processed_at' => now(),
                    'last_error_code' => $isDuplicate ? 'duplicate_receipt_conflict' : null,
                    'last_error_message' => $isDuplicate ? ($result['details'] ?? 'Duplicate detected') : ($isShadowMode ? 'SHADOW_MODE_SUCCESS' : null),
                ]);

                // Trigger second stage ONLY if not in shadow mode
                if (!$isShadowMode && isset($result['id'])) {
                    ProcessTransactionJob::dispatch($result['id'])
                        ->onQueue(app(IngestionQueueRouter::class)->processingQueueForTenant($intake->tenant_id))
                        ->afterCommit();
                }

                Log::info('ProcessTransactionIntakeJob: Success', [
                    'status' => $status,
                    'is_duplicate' => $isDuplicate,
                    'transaction_pk' => $result['id'] ?? null
                ]);

                // Performance: Processing Metrics
                $workerMs = (microtime(true) - $startTime) * 1000;
                $e2eLag = $intake->received_at->diffInSeconds(now());

                Metrics::timing('intake.worker_time', $workerMs);
                Metrics::timing('intake.processing_lag', (float) $e2eLag);
                Metrics::bucket('intake.processing_lag', (float) $e2eLag);
                Metrics::incr('intake.processed_count');

                // Self-Correction: If this was previously a failure, remove it from failed metrics
                if ($initialStatus === TransactionIntake::PROCESSING_STATUS_FAILED_PERMANENT) {
                    Metrics::decr('intake.failed_count');
                }
            } else {
                // Genuine business failure (Math mismatch, validation error, etc.)
                $intake->update([
                    'processing_status' => TransactionIntake::PROCESSING_STATUS_FAILED_PERMANENT,
                    'last_error_code' => $result['message'] ?? 'INGEST_FAILED',
                    'last_error_message' => $result['details'] ?? 'Unknown ingest failure',
                ]);

                Log::warning('ProcessTransactionIntakeJob: Permanent failure', [
                    'message' => $result['message'] ?? 'none'
                ]);

                Metrics::incr('intake.failed_count');
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
