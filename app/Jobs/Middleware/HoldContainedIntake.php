<?php

namespace App\Jobs\Middleware;

use App\Models\TransactionIntake;
use App\Support\IntakeContainmentFloor;
use App\Support\LogContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Holds pre-floor intake jobs during the temporary containment. Not calling
 * $next lets CallQueuedHandler delete the job without a failure, before the
 * WithoutOverlapping lock is touched and without any state change or ingest.
 */
class HoldContainedIntake
{
    public function handle(object $job, callable $next): mixed
    {
        $containment = IntakeContainmentFloor::current();

        if ($containment->isInvalid()) {
            $containment->alertIfNeeded();
            $this->logHeld($job, null, 'invalid_floor');

            return null;
        }

        if (! $containment->isArmed()) {
            $containment->alertIfNeeded();

            return $next($job);
        }

        // Selects no payload data. A failed read propagates so the job is not lost.
        $row = TransactionIntake::query()
            ->whereKey($job->intakeId)
            ->toBase()
            ->first(['received_at', 'tenant_id']);

        if ($row === null) {
            return $next($job);
        }

        if ($row->received_at === null) {
            $this->logHeld($job, $row->tenant_id, 'null_received_at');

            return null;
        }

        if (CarbonImmutable::parse($row->received_at, config('app.timezone'))->isBefore($containment->floor)) {
            $this->logHeld($job, $row->tenant_id, 'before_floor');

            return null;
        }

        return $next($job);
    }

    private function logHeld(object $job, mixed $tenantId, string $reason): void
    {
        Log::info('ProcessTransactionIntakeJob: held by containment floor', LogContext::ingestion([
            'tenant_id' => $tenantId,
            'correlation_id' => $job->traceId,
            'decision' => 'held',
            'reason' => $reason,
        ], [
            'intake_id' => $job->intakeId,
        ]));
    }
}
