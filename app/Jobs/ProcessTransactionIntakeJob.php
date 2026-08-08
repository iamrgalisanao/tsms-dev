<?php

namespace App\Jobs;

use App\Models\TransactionIntake;
use App\Services\CircuitBreaker;
use App\Services\IngestionQueueRouter;
use App\Services\TransactionIngestService;
use App\Support\Metrics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
            $isShadowMode = config('tsms.testing.capture_only') === true;
            $transactionPayloads = isset($intake->payload['transactions']) && is_array($intake->payload['transactions'])
                ? $intake->payload['transactions']
                : [$intake->payload['transaction'] ?? []];

            $results = [];
            $createdTransactionIds = [];
            $failedItems = [];

            foreach ($transactionPayloads as $index => $transactionPayload) {
                $payload = array_merge($transactionPayload, [
                    'submission_uuid' => $intake->submission_uuid,
                    'submission_timestamp' => $intake->payload['submission_timestamp'],
                    'tenant_id' => $intake->tenant_id,
                    'terminal_id' => $intake->terminal_id,
                ]);

                $result = null;

                if ($isShadowMode) {
                    \Illuminate\Support\Facades\DB::beginTransaction();
                    $result = $ingestService->ingest($payload);

                    Log::channel('shadow_audit')->info('SHADOW_MODE_RESULT', [
                        'intake_id' => $this->intakeId,
                        'submission_uuid' => $intake->submission_uuid,
                        'result' => $result
                    ]);

                    \Illuminate\Support\Facades\DB::rollBack();
                } else {
                    $result = $ingestService->ingest($payload);
                }

                $results[] = $result;
                $status = $result['status'] ?? 'failed';
                $isDuplicate = $status === 'duplicate' || ($result['message'] ?? '') === 'duplicate_receipt_conflict';

                if (!in_array($status, ['success', 'accepted', 'already_processed'], true) && !$isDuplicate) {
                    $failedItems[] = [
                        'index' => $index,
                        'transaction_id' => $payload['transaction_id'] ?? null,
                        'message' => $result['message'] ?? 'INGEST_FAILED',
                        'details' => $result['details'] ?? null,
                    ];

                    continue;
                }

                if (!$isShadowMode && isset($result['id']) && !$isDuplicate) {
                    $createdTransactionIds[] = $result['id'];
                    ProcessTransactionJob::dispatch($result['id'])
                        ->onQueue(app(IngestionQueueRouter::class)->processingQueueForTenant($intake->tenant_id))
                        ->afterCommit();
                }
            }

            $handledCount = count($results) - count($failedItems);

            if ($handledCount > 0) {
                $allDuplicates = collect($results)->every(
                    fn (array $result) => ($result['status'] ?? null) === 'duplicate'
                        || ($result['message'] ?? '') === 'duplicate_receipt_conflict'
                );
                $finalStatus = $allDuplicates
                    ? TransactionIntake::PROCESSING_STATUS_DUPLICATE
                    : TransactionIntake::PROCESSING_STATUS_PROCESSED;
                $partialFailure = $failedItems !== [];

                $intake->update([
                    'processing_status' => $finalStatus,
                    'processed_at' => now(),
                    'last_error_code' => $partialFailure
                        ? 'PARTIAL_BATCH_FAILURE'
                        : ($allDuplicates ? 'duplicate_receipt_conflict' : null),
                    'last_error_message' => $partialFailure
                        ? json_encode(['failed_items' => $failedItems], JSON_UNESCAPED_SLASHES)
                        : ($allDuplicates ? 'Duplicate detected' : ($isShadowMode ? 'SHADOW_MODE_SUCCESS' : null)),
                ]);

                Log::info('ProcessTransactionIntakeJob: Success', [
                    'status' => $finalStatus,
                    'transaction_count' => count($results),
                    'transaction_pks' => $createdTransactionIds,
                    'failed_count' => count($failedItems),
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
                    'last_error_code' => $failedItems[0]['message'] ?? 'INGEST_FAILED',
                    'last_error_message' => json_encode(['failed_items' => $failedItems], JSON_UNESCAPED_SLASHES),
                ]);

                Log::warning('ProcessTransactionIntakeJob: Permanent failure', [
                    'message' => $failedItems[0]['message'] ?? 'none',
                    'failed_count' => count($failedItems),
                ]);

                Metrics::incr('intake.failed_count');
            }
        } catch (\Throwable $e) {
            $reference = (string) Str::uuid();

            Log::error('ProcessTransactionIntakeJob: Exception', [
                'intake_id' => $this->intakeId,
                'reference' => $reference,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Only a genuine infrastructure-level failure should trip the
            // shared ingestion circuit breaker — a data/constraint-level
            // exception is a validation gap, not a downstream outage, and
            // must not falsely open the breaker for every tenant. Reuses
            // TransactionIngestService's classification so the two call
            // sites can't drift.
            if (TransactionIngestService::isInfrastructureFailure($e)) {
                (new CircuitBreaker(CircuitBreaker::INGESTION_SERVICE_KEY))->recordFailure();
            } else {
                Log::error('ProcessTransactionIntakeJob: data/constraint-level exception reached the DB despite passing validation — check for a validation gap', [
                    'intake_id' => $this->intakeId,
                    'reference' => $reference,
                ]);
            }

            $intake->update([
                'processing_status' => TransactionIntake::PROCESSING_STATUS_FAILED_RETRYABLE,
                'last_error_code' => 'EXCEPTION',
                // Never leak raw exception text into a terminal-facing field
                // (last_error_message ultimately reaches
                // SubmissionStatusController::show()). Full text is already
                // logged above under the same reference.
                'last_error_message' => 'An internal error occurred while processing this transaction. Reference: ' . $reference,
            ]);

            // Rethrow to trigger queue retry if within limits
            throw $e;
        }
    }
}
