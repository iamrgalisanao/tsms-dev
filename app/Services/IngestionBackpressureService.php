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

    public function checkAggregate(int|string|null $tenantId, ?array $processing = null): array
    {
        $intake = $this->checkIntake($tenantId);
        $processingResult = $this->isValidSubDecision($processing) ? $processing : $this->checkProcessing($tenantId);

        return [
            'enforced' => $intake['enforced'] || $processingResult['enforced'],
            'degraded' => ($intake['enforced'] && $intake['degraded']) || ($processingResult['enforced'] && $processingResult['degraded']),
            'overloaded' => ($intake['enforced'] && $intake['overloaded']) || ($processingResult['enforced'] && $processingResult['overloaded']),
            'intake' => $intake,
            'processing' => $processingResult,
        ];
    }

    private function isValidSubDecision(?array $result): bool
    {
        return $result !== null && isset($result['enforced'], $result['degraded'], $result['overloaded'], $result['queue_type']);
    }

    public function checkQueue(string $queueName, string $queueType = 'intake'): array
    {
        if (!$this->enabled()) {
            return $this->result(false, false, false, $queueName, $queueType, null, $this->threshold(), 'disabled');
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

            return $this->result($overloaded, false, $enforced, $queueName, $queueType, $depth, $this->threshold(), $this->mode());
        } catch (\Throwable $e) {
            $failClosed = $this->enforced(); // true only when mode === 'enforce'

            Log::error('Failed to evaluate ingestion backpressure', [
                'queue' => $queueName,
                'queue_type' => $queueType,
                'mode' => $this->mode(),
                'fail_closed' => $failClosed,
                'error' => $e->getMessage(),
            ]);

            return $this->result(false, true, $failClosed, $queueName, $queueType, null, $this->threshold(), $this->mode());
        }
    }

    public function degradedStatus(): int
    {
        return 503; // contract pins this to 503, unlike the configurable rejectionStatus()
    }

    public function degradedPayload(array $result, ?string $correlationId = null): array
    {
        return [
            'success' => false,
            'error_code' => 'INGESTION_DEGRADED',
            'message' => 'Ingestion health could not be evaluated. Retry later.',
            'retry_after_seconds' => $this->retryAfterSeconds(),
            'retry_after' => now()->addSeconds($this->retryAfterSeconds())->toISOString(),
            'correlation_id' => $correlationId,
            'reason' => $this->degradedReason($result),
        ];
    }

    public function rejectionPayload(array $result, ?string $correlationId = null): array
    {
        return [
            'success' => false,
            'message' => 'Ingestion temporarily throttled due to queue backpressure. Retry later.',
            'error_code' => 'INGESTION_BACKPRESSURE',
            'retry_after_seconds' => $this->retryAfterSeconds(),
            'retry_after' => now()->addSeconds($this->retryAfterSeconds())->toISOString(),
            'correlation_id' => $correlationId,
            'backpressure' => $this->isAggregateResult($result)
                ? $this->aggregateBackpressureContext($result)
                : $this->singleBackpressureContext($result),
        ];
    }

    public function rejectionStatus(): int
    {
        return (int) config('tsms.intake.backpressure.reject_status', 429);
    }

    private function isAggregateResult(array $result): bool
    {
        return isset($result['intake'], $result['processing']);
    }

    private function singleBackpressureContext(array $result): array
    {
        return [
            'queue' => $result['queue'],
            'queue_type' => $result['queue_type'],
            'queue_depth' => $result['queue_depth'],
            'threshold' => $result['threshold'],
            'mode' => $result['mode'],
            'reason' => $result['queue_type'] . '_queue_depth_exceeded',
        ];
    }

    private function aggregateBackpressureContext(array $result): array
    {
        return [
            'intake' => $this->subDecisionContext($result['intake']),
            'processing' => $this->subDecisionContext($result['processing']),
            'reason' => $this->aggregateReason($result, 'overloaded', 'queue_depth_exceeded'),
        ];
    }

    private function subDecisionContext(array $sub): array
    {
        return [
            'queue' => $sub['queue'],
            'queue_type' => $sub['queue_type'],
            'queue_depth' => $sub['queue_depth'],
            'threshold' => $sub['threshold'],
            'mode' => $sub['mode'],
            'overloaded' => $sub['overloaded'],
            'degraded' => $sub['degraded'],
        ];
    }

    private function degradedReason(array $result): string
    {
        if ($this->isAggregateResult($result)) {
            return $this->aggregateReason($result, 'degraded', 'health_check_failed');
        }

        return $result['queue_type'] . '_health_check_failed';
    }

    private function aggregateReason(array $result, string $flag, string $suffix): string
    {
        $intakeFlag = $result['intake']['enforced'] && $result['intake'][$flag];
        $processingFlag = $result['processing']['enforced'] && $result['processing'][$flag];

        if ($intakeFlag && $processingFlag) {
            return "intake_and_processing_{$suffix}";
        }

        return $intakeFlag ? "intake_{$suffix}" : "processing_{$suffix}";
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
        bool $degraded,
        bool $enforced,
        string $queueName,
        string $queueType,
        ?int $queueDepth,
        int $threshold,
        string $mode
    ): array {
        return [
            'overloaded' => $overloaded,
            'degraded' => $degraded,
            'enforced' => $enforced,
            'queue' => $queueName,
            'queue_type' => $queueType,
            'queue_depth' => $queueDepth,
            'threshold' => $threshold,
            'mode' => $mode,
        ];
    }
}
