<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SubmissionEvent;
use App\Models\PosTerminal;

class SubmissionEventController extends Controller
{
    public function index(Request $request)
    {
        $query = SubmissionEvent::query();

        // Derive tenant scope from the authenticated token (Sanctum)
        $user = $request->user();
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $tokenTenantId = null;

        if ($token && $token->tokenable_type === PosTerminal::class) {
            $terminal = PosTerminal::find($token->tokenable_id);
            $tokenTenantId = $terminal?->tenant_id;
        }

        $isAdmin = $user && method_exists($user, 'tokenCan') && $user->tokenCan('admin:manage');

        // Enforce tenant scoping by default; admins can query any tenant
        if (!$isAdmin) {
            // Non-admins must be scoped to their tenant
            if ($tokenTenantId === null) {
                return response()->json([
                    'status' => 'forbidden',
                    'message' => 'Unable to determine tenant context for this token.'
                ], 403);
            }
            $query->where('tenant_id', $tokenTenantId);
        } else {
            // Admin may optionally filter by tenant_id
            if ($request->filled('tenant_id')) {
                $query->where('tenant_id', $request->integer('tenant_id'));
            }
        }

        if ($request->filled('terminal_id')) {
            $query->where('terminal_id', $request->integer('terminal_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->get('from'));
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->get('to'));
        }

        // Pagination guard
        $perPage = max(1, min(100, (int) $request->integer('per_page', 25)));

        $events = $query
            ->orderBy('occurred_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data'   => $events,
        ]);
    }
}
