<?php

namespace App\Services;

use App\Models\Incident;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class IncidentFactory
{
    /**
     * Record or update an Incident based on a failed transaction or submission event.
     *
     * Expected keys in $context (all optional but recommended):
     * - submission_uuid (string)
     * - correlation_id (string)
     * - tenant_id (int)
     * - terminal_id (int)
     * - reason_code (string)
     * - source (string)          // e.g. SUBMISSION_EVENT_ITEM, SYSTEM_LOG
     * - failed_count (int)
     * - reason_details (array)
     */
    public function recordFailure(array $context): void
    {
        try {
            $submissionUuid = $context['submission_uuid'] ?? null;
            $correlationId = $context['correlation_id'] ?? null;

            if (empty($submissionUuid) && empty($correlationId)) {
                // Without at least one of these, we cannot reliably aggregate
                Log::debug('IncidentFactory: skipping recordFailure, no submission_uuid or correlation_id provided', [
                    'context_keys' => array_keys($context),
                ]);
                return;
            }

            $catalog = Config::get('tsms_error_catalog');
            $reasonCode = $context['reason_code'] ?? null;
            $reasonMeta = $reasonCode && isset($catalog['reasons'][$reasonCode])
                ? $catalog['reasons'][$reasonCode]
                : null;

            $categoryKey = $reasonMeta['category'] ?? 'SYSTEM_ERROR';
            $category = $catalog['categories'][$categoryKey] ?? $categoryKey;

            $humanTitle = $reasonMeta['title'] ?? ($reasonCode ?: 'Submission processing issue');
            $humanMessage = $reasonMeta['message'] ?? 'TSMS detected an issue while processing this submission.';
            $posAction = $reasonMeta['action'] ?? 'Review the detailed error information and correct the payload before resending.';

            // Find or create Incident keyed by submission_uuid + correlation_id
            $incident = Incident::query()
                ->when($submissionUuid, fn ($q) => $q->where('submission_uuid', $submissionUuid))
                ->when($correlationId, fn ($q) => $q->where('correlation_id', $correlationId))
                ->first();

            $isNew = false;
            if (!$incident) {
                $incident = new Incident();
                $incident->submission_uuid = $submissionUuid;
                $incident->correlation_id = $correlationId;
                $incident->first_seen_at = now();
                $incident->state = 'OPEN';
                $isNew = true;
            }

            // Update core fields
            $incident->tenant_id = $incident->tenant_id ?: Arr::get($context, 'tenant_id');
            $incident->terminal_id = $incident->terminal_id ?: Arr::get($context, 'terminal_id');

            if ($reasonCode) {
                $incident->reason_code = $reasonCode;
            }

            $incident->category = $category;
            $incident->human_title = $humanTitle;
            $incident->human_message = $humanMessage;
            $incident->pos_action = $posAction;

            $failedIncrement = (int) ($context['failed_count'] ?? 1);
            $incident->failed_count = max(0, (int) $incident->failed_count) + $failedIncrement;
            $incident->occurrence_count = max(0, (int) $incident->occurrence_count) + 1;

            $existingContext = is_array($incident->context) ? $incident->context : [];
            $incident->context = array_merge($existingContext, [
                'last_source' => $context['source'] ?? null,
                'last_reason_details' => $context['reason_details'] ?? null,
            ]);

            $incident->last_seen_at = now();

            // Do not auto-close incidents here; operators resolve them
            $incident->save();

            Log::info('IncidentFactory: recorded incident', [
                'incident_id' => $incident->id,
                'is_new' => $isNew,
                'submission_uuid' => $incident->submission_uuid,
                'correlation_id' => $incident->correlation_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('IncidentFactory: failed to record incident', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
