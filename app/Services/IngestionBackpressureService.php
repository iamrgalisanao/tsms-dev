<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class IngestionBackpressureService
{
    public function __construct(private readonly IngestionQueueRouter $queueRouter)
    {
    }

    public function checkIntake(int|string|null $tenantId): array
    {
        return $this->checkQueue($this->queueRouter->intakeQueueForTenant($tenantId), 'intake');
    }

    public function checkProcessing(int|string|null $tenantId): array
    {
        return $this->checkQueue($this->queueRouter->processingQueueForTenant($tenantId), 'processing');
    }

    public function checkQueue(string $queueName, string $queueType = 'intake'): array
    {
        if (!$this->enabled()) {
            return $this->result(false, false, $queueName, $queueType, null, $this->threshold(), 'disabled');
        }

        try {
            $depth = $this->queueDepth($queueName);
            $overloaded = $depth >= $this->threshold();
            $enforced = $overloaded && $this->enforced();

            if ($overloaded) {
                Log::warning('Ingestion backpressure threshold reached', [
                    'queue' => $queueName,
                    'queue_type' => $queueType,
                    'depth' => $depth,
                    'threshold' => $this->threshold(),
                    'mode' => $this->mode(),
                    'enforced' => $enforced,
                ]);
            }

            return $this->result($overloaded, $enforced, $queueName, $queueType, $depth, $this->threshold(), $this->mode());
        } catch (\Throwable $e) {
            Log::error('Failed to evaluate ingestion backpressure', [
                'queue' => $queueName,
                'queue_type' => $queueType,
                'error' => $e->getMessage(),
            ]);

            return $this->result(false, false, $queueName, $queueType, null, $this->threshold(), 'error');
        }
    }

    public function rejectionPayload(array $result): array
    {
        return [
            'success' => false,
            'message' => 'Ingestion temporarily throttled due to queue backpressure. Retry later.',
            'error_code' => 'INGESTION_BACKPRESSURE',
            'retry_after_seconds' => $this->retryAfterSeconds(),
            'retry_after' => now()->addSeconds($this->retryAfterSeconds())->toISOString(),
            'backpressure' => [
                'queue' => $result['queue'],
                'queue_type' => $result['queue_type'],
                'queue_depth' => $result['queue_depth'],
                'threshold' => $result['threshold'],
                'mode' => $result['mode'],
            ],
        ];
    }

    public function rejectionStatus(): int
    {
        return (int) config('tsms.intake.backpressure.reject_status', 429);
    }

    private function queueDepth(string $queueName): int
    {
        $redisConnection = config('queue.connections.redis.connection', 'default');

        return (int) Redis::connection($redisConnection)->llen('queues:' . $queueName);
    }

    private function enabled(): bool
    {
        return (bool) config('tsms.intake.backpressure.enabled', true);
    }

    private function enforced(): bool
    {
        return $this->mode() === 'enforce';
    }

    private function mode(): string
    {
        return config('tsms.intake.backpressure.mode', 'observe') === 'enforce' ? 'enforce' : 'observe';
    }

    private function threshold(): int
    {
        return (int) config('tsms.intake.backpressure.max_queue_depth', 5000);
    }

    private function retryAfterSeconds(): int
    {
        return max(1, (int) config('tsms.intake.backpressure.retry_after_seconds', 60));
    }

    private function result(
        bool $overloaded,
        bool $enforced,
        string $queueName,
        string $queueType,
        ?int $queueDepth,
        int $threshold,
        string $mode
    ): array {
        return [
            'overloaded' => $overloaded,
            'enforced' => $enforced,
            'queue' => $queueName,
            'queue_type' => $queueType,
            'queue_depth' => $queueDepth,
            'threshold' => $threshold,
            'mode' => $mode,
        ];
    }
}
