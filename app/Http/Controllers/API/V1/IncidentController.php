<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    /**
     * List incidents with basic filtering and pagination.
     */
    public function index(Request $request)
    {
        $query = Incident::query();

        if ($request->filled('submission_uuid')) {
            $query->where('submission_uuid', $request->get('submission_uuid'));
        }

        if ($request->filled('correlation_id')) {
            $query->where('correlation_id', $request->get('correlation_id'));
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', (int) $request->get('tenant_id'));
        }

        if ($request->filled('terminal_id')) {
            $query->where('terminal_id', (int) $request->get('terminal_id'));
        }

        if ($request->filled('state')) {
            $query->where('state', $request->get('state'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->get('category'));
        }

        if ($request->filled('from')) {
            $query->whereDate('first_seen_at', '>=', $request->get('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('first_seen_at', '<=', $request->get('to'));
        }

        $perPage = (int) ($request->get('per_page', 50));
        $perPage = max(1, min($perPage, 200));

        $paginator = $query
            ->orderByDesc('first_seen_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json([
            'data' => $paginator->getCollection()->transform(function (Incident $incident) {
                return [
                    'id' => $incident->id,
                    'submission_uuid' => $incident->submission_uuid,
                    'correlation_id' => $incident->correlation_id,
                    'tenant_id' => $incident->tenant_id,
                    'terminal_id' => $incident->terminal_id,
                    'category' => $incident->category,
                    'state' => $incident->state,
                    'reason_code' => $incident->reason_code,
                    'human_title' => $incident->human_title,
                    'human_message' => $incident->human_message,
                    'pos_action' => $incident->pos_action,
                    'failed_count' => $incident->failed_count,
                    'occurrence_count' => $incident->occurrence_count,
                    'first_seen_at' => optional($incident->first_seen_at)->toIso8601String(),
                    'last_seen_at' => optional($incident->last_seen_at)->toIso8601String(),
                    'resolved_at' => optional($incident->resolved_at)->toIso8601String(),
                    'context' => $incident->context,
                    'created_at' => optional($incident->created_at)->toIso8601String(),
                    'updated_at' => optional($incident->updated_at)->toIso8601String(),
                ];
            }),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Show a single incident.
     */
    public function show(int $id)
    {
        $incident = Incident::findOrFail($id);

        return response()->json([
            'id' => $incident->id,
            'submission_uuid' => $incident->submission_uuid,
            'correlation_id' => $incident->correlation_id,
            'tenant_id' => $incident->tenant_id,
            'terminal_id' => $incident->terminal_id,
            'category' => $incident->category,
            'state' => $incident->state,
            'reason_code' => $incident->reason_code,
            'human_title' => $incident->human_title,
            'human_message' => $incident->human_message,
            'pos_action' => $incident->pos_action,
            'failed_count' => $incident->failed_count,
            'occurrence_count' => $incident->occurrence_count,
            'first_seen_at' => optional($incident->first_seen_at)->toIso8601String(),
            'last_seen_at' => optional($incident->last_seen_at)->toIso8601String(),
            'resolved_at' => optional($incident->resolved_at)->toIso8601String(),
            'context' => $incident->context,
            'created_at' => optional($incident->created_at)->toIso8601String(),
            'updated_at' => optional($incident->updated_at)->toIso8601String(),
        ]);
    }
}
