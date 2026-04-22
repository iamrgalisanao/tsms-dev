<?php

namespace App\Services;

use App\Models\TransactionIntake;
use App\Rules\UuidV4;
use App\Rules\ReceiptNumber;
use App\Support\Metrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TransactionIntakeService
{
    protected PayloadChecksumService $checksumService;

    public function __construct(PayloadChecksumService $checksumService)
    {
        $this->checksumService = $checksumService;
    }
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
        $tenantId = $request->user()->tenant_id ?? 0;
        $shardQueue = $this->getShardQueue($tenantId);

        // 1. Stage 1: Structural & Format Validation (Gatekeeping)
        $validator = Validator::make($payload, [
            'submission_uuid' => ['required', 'string', new UuidV4()],
            'submission_timestamp' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?$/'],
            'payload_checksum' => 'required|string|min:64|max:64|regex:/^[0-9a-f]{64}$/i',
            'transaction' => 'required|array',
            'transaction.transaction_id' => 'required|string',
            'transaction.receipt_no' => ['required', new ReceiptNumber()],
        ]);

        if ($validator->fails()) {
            $this->persistRejection($payload, $validator->errors()->toArray(), $sourceIp, $traceId, $receivedAt, 'STRUCTURAL_VALIDATION_FAILURE');
            
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Structural validation failed',
                'errors' => $validator->errors()->toArray(),
            ];
        }

        // 2. Stage 2: Cryptographic Integrity (Synchronous Checksum)
        $checksumResult = $this->checksumService->validateSubmissionChecksums($payload);
        if (!$checksumResult['valid']) {
            $this->persistRejection($payload, $checksumResult['errors'], $sourceIp, $traceId, $receivedAt, 'CRYPTOGRAPHIC_INTEGRITY_FAILURE');

            return [
                'success' => false,
                'status' => 422,
                'message' => 'Cryptographic integrity check failed. Payload may have been tampered with or canonicalization logic is incorrect.',
                'errors' => $checksumResult['errors'],
                'hint' => 'Ensure you are using the V2.1/V2.2 canonicalization strategy (ksort + 2-decimal strings).'
            ];
        }

        Metrics::incr('intake.received_count');

        // 3. Proactive Backpressure Check (Shard-Aware Fail-Fast)
        if ($this->isSystemOverloaded($shardQueue)) {
            return [
                'success' => false,
                'status' => 429,
                'message' => 'System is currently experiencing high load. Please retry in a few minutes.',
            ];
        }

        // 4. Check for duplicate submission_uuid
        $existing = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->first();
        if ($existing) {
            return [
                'success' => true,
                'status' => 200,
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

            $pilotTenants = config('tsms.rollout.pilot_tenants', []);
            $isPilot = in_array($intake->tenant_id, $pilotTenants);

            // Dispatch processing job (Legacy Async Path for Zero-Impact fix)
            \App\Jobs\ProcessTransactionIntakeJob::dispatch($intake->id)
                ->onQueue('transaction-intake')
                ->afterCommit();

            $intake->update([
                'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
                'queued_at' => now(),
            ]);

            Log::info('TransactionIntakeService: Intake queued asychronously', [
                'tenant_id' => $intake->tenant_id,
                'is_pilot' => $isPilot,
                'submission_uuid' => $intake->submission_uuid
            ]);

            // Performance: Intake Dispatch Latency (Sync path)
            $latency = now()->diffInMilliseconds($receivedAt);
            Metrics::timing('intake.dispatch_latency', $latency);
            Metrics::bucket('intake.dispatch_latency', $latency);
            Metrics::incr('intake.accepted_count');
            Metrics::incr("tenant.{$intake->tenant_id}.intake_count");

            return [
                'success' => true,
                'status' => 200,
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

    protected function persistRejection(array $payload, array $errors, string $sourceIp, string $traceId, \Carbon\Carbon $receivedAt, string $errorCode = 'LAYER_A_VALIDATION_FAILURE'): void
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
                    'last_error_code' => $errorCode,
                    'last_error_message' => json_encode($errors),
                    'trace_id' => $traceId,
                    'received_at' => $receivedAt,
                ]);

                Metrics::incr('intake.rejected_count');
            }
        } catch (\Exception $e) {
            Log::warning('TransactionIntakeService: Failed to persist rejection audit', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Determine if the system is currently under excessive load for a specific shard.
     * This checks the depth of the specific shard's ingestion queue in Redis.
     */
    protected function isSystemOverloaded(string $queueName): bool
    {
        if (!config('tsms.intake.backpressure.enabled', true)) {
            return false;
        }

        try {
            $threshold = config('tsms.intake.backpressure.max_queue_depth', 5000);
            
            // Laravel's Redis queue prefix is usually 'queues:'
            $fullQueueName = 'queues:' . $queueName;
            $currentDepth = \Illuminate\Support\Facades\Redis::connection('horizon')->llen($fullQueueName);

            if ($currentDepth >= $threshold) {
                Log::warning('TransactionIntakeService: Backpressure triggered on shard', [
                    'queue' => $queueName,
                    'current_depth' => $currentDepth,
                    'threshold' => $threshold
                ]);
                return true;
            }
        } catch (\Exception $e) {
            Log::error('TransactionIntakeService: Failed to check queue depth for backpressure', ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Determine the correct shard queue for a given tenant.
     * Pilot tenants go to the VIP lane; others are hashed into balanced shards.
     */
    protected function getShardQueue(int $tenantId): string
    {
        $pilotTenants = config('tsms.rollout.pilot_tenants', []);
        
        if (in_array($tenantId, $pilotTenants)) {
            return 'transaction-intake:s-' . config('tsms.intake.vip_shard', 'vip');
        }

        $shardCount = config('tsms.intake.shard_count', 8);
        $shardIndex = crc32((string) $tenantId) % $shardCount;

        return "transaction-intake:s{$shardIndex}";
    }
}
