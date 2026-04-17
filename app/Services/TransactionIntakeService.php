<?php

namespace App\Services;

use App\Models\TransactionIntake;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TransactionIntakeService
{
    /**
     * Handle the intake of a TSMS transaction submission.
     *
     * @param Request $request
     * @return array
     */
    public function handleIntake(Request $request): array
    {
        $payload = $request->all();
        $sourceIp = $request->ip();
        $traceId = $request->header('X-Correlation-ID') ?? Str::uuid()->toString();
        $receivedAt = now();

        // 1. Layer A Validation (Gatekeeping)
        $validator = Validator::make($payload, [
            'submission_uuid' => 'required|uuid',
            'submission_timestamp' => 'required|date',
            'payload_checksum' => 'required|string',
            'transaction' => 'required|array',
            'transaction.transaction_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            // Persist as REJECTED for auditability (if submission_uuid exists)
            $this->persistRejection($payload, $validator->errors()->toArray(), $sourceIp, $traceId, $receivedAt);
            
            return [
                'success' => false,
                'status' => 400,
                'message' => 'Validation failed',
                'errors' => $validator->errors()->toArray(),
            ];
        }

        // 2. Check for duplicate submission_uuid
        $existing = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->first();
        if ($existing) {
            return [
                'success' => true,
                'status' => 202,
                'message' => 'Submission already accepted',
                'data' => [
                    'submission_uuid' => $existing->submission_uuid,
                    'intake_id' => $existing->id,
                ],
            ];
        }

        // 3. Persist raw intake
        try {
            $intake = TransactionIntake::create([
                'submission_uuid' => $payload['submission_uuid'],
                'tenant_id' => $request->user()->tenant_id, // Authoritative source
                'terminal_id' => $request->user()->id,      // Authoritative source
                'payload_checksum' => $payload['payload_checksum'],
                'payload' => $payload,
                'payload_size_bytes' => strlen($request->getContent()),
                'source_ip' => $sourceIp,
                'intake_status' => TransactionIntake::INTAKE_STATUS_ACCEPTED,
                'trace_id' => $traceId,
                'received_at' => $receivedAt,
            ]);

            // Dispatch processing job
            \App\Jobs\ProcessTransactionIntakeJob::dispatch($intake->id)
                ->onQueue('transaction-intake')
                ->afterCommit();

            $intake->update([
                'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
                'queued_at' => now(),
            ]);

            return [
                'success' => true,
                'status' => 202,
                'message' => 'Submission accepted',
                'data' => [
                    'submission_uuid' => $intake->submission_uuid,
                    'intake_id' => $intake->id,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('TransactionIntakeService: Persistence failed', [
                'error' => $e->getMessage(),
                'submission_uuid' => $payload['submission_uuid'] ?? 'unknown',
            ]);

            return [
                'success' => false,
                'status' => 503,
                'message' => 'System unavailable',
            ];
        }
    }

    /**
     * Persist a rejected intake attempt for auditability.
     */
    protected function persistRejection(array $payload, array $errors, string $sourceIp, string $traceId, \Carbon\Carbon $receivedAt): void
    {
        try {
            // Only persist if we have a submission_uuid to track it
            if (isset($payload['submission_uuid'])) {
                TransactionIntake::create([
                    'submission_uuid' => $payload['submission_uuid'],
                    'tenant_id' => auth()->user()->tenant_id ?? 0,
                    'terminal_id' => auth()->user()->id ?? 0,
                    'payload_checksum' => $payload['payload_checksum'] ?? 'NONE',
                    'payload' => $payload,
                    'payload_size_bytes' => strlen(json_encode($payload)),
                    'source_ip' => $sourceIp,
                    'intake_status' => TransactionIntake::INTAKE_STATUS_REJECTED,
                    'last_error_code' => 'LAYER_A_VALIDATION_FAILURE',
                    'last_error_message' => json_encode($errors),
                    'trace_id' => $traceId,
                    'received_at' => $receivedAt,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('TransactionIntakeService: Failed to persist rejection audit', ['error' => $e->getMessage()]);
        }
    }
}
