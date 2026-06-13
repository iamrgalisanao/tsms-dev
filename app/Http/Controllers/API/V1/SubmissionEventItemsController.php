<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\SubmissionEventItem;
use Illuminate\Http\Request;

class SubmissionEventItemsController extends Controller
{
    public function index(Request $request, string $submission_uuid)
    {
        $user = $request->user();
        $tenantId = optional($user)->tenant_id ?? null;
        $isAdmin = $user && method_exists($user, 'tokenCan') && $user->tokenCan('admin:manage');

        $query = SubmissionEventItem::query()->where('submission_uuid', $submission_uuid);

        if (!$isAdmin && $tenantId) {
            $query->where('tenant_id', $tenantId);
        } elseif (!$isAdmin && !$tenantId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('terminal_id')) {
            $query->where('terminal_id', (int) $request->terminal_id);
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        $items = $query->orderByDesc('occurred_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }
}
