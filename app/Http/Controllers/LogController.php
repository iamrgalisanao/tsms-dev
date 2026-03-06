<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SystemLog;
use App\Models\WebhookLog;
use App\Models\SubmissionEvent;
use Illuminate\Http\Request;
use App\Models\PosTerminal;
use Illuminate\Support\Facades\Schema;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $systemLogs = SystemLog::with('user')
            ->when($request->filled('type'), function ($query) use ($request) {
                // If the requested type is one of the enum values, filter by 'type'
                $validTypes = ['payload_validation', 'integration', 'security', 'audit', 'retry', 'transaction'];
                if (in_array($request->type, $validTypes)) {
                    return $query->where('type', $request->type);
                }
                return $query->where('log_type', $request->type);
            })
            ->when($request->filled('severity'), function ($query) use ($request) {
                return $query->where('severity', $request->severity);
            })
            ->when($request->filled('date_from'), function ($query) use ($request) {
                return $query->whereDate('created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function ($query) use ($request) {
                return $query->whereDate('created_at', '<=', $request->date_to);
            })
            ->when($request->filled('terminal'), function ($query) use ($request) {
                return $query->where('terminal_uid', $request->terminal);
            })
            ->latest()
            ->paginate(15, ['*'], 'system_page');

        $auditLogs = AuditLog::with('user')
            ->when($request->filled('search'), function ($query) use ($request) {
                return $query->where('action', 'like', "%{$request->search}%")
                    ->orWhere('resource_type', 'like', "%{$request->search}%");
            })
            ->when($request->filled('type'), function ($query) use ($request) {
                // For AuditLog, map 'security' or 'AUTH' to 'action_type'
                if ($request->type === 'security' || $request->type === 'audit') {
                    return $query->where('action_type', 'AUTH');
                }
                return $query->where('action_type', $request->type);
            })
            ->when($request->filled('date_from'), function ($query) use ($request) {
                return $query->whereDate('created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function ($query) use ($request) {
                return $query->whereDate('created_at', '<=', $request->date_to);
            })
            ->latest()
            ->paginate(15, ['*'], 'audit_page');

        // Detect if legacy `endpoint` column exists; fall back to new schema fields otherwise
        $hasEndpointColumn = Schema::hasColumn('webhook_logs', 'endpoint');

        $webhookLogs = WebhookLog::with('terminal')
            ->when($request->filled('search'), function ($query) use ($request, $hasEndpointColumn) {
                $term = $request->search;

                return $query->where(function ($q) use ($term, $hasEndpointColumn) {
                    if ($hasEndpointColumn) {
                        // Legacy schema: search by endpoint URL
                        $q->where('endpoint', 'like', "%{$term}%");
                    } else {
                        // New schema: search by event_type, status, or payload JSON
                        $q->where('event_type', 'like', "%{$term}%")
                          ->orWhere('status', 'like', "%{$term}%")
                          ->orWhere('payload', 'like', "%{$term}%");
                    }
                });
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                return $query->where('status', $request->status);
            })
            ->when($request->filled('date_from'), function ($query) use ($request) {
                return $query->whereDate('created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function ($query) use ($request) {
                return $query->whereDate('created_at', '<=', $request->date_to);
            })
            ->latest()
            ->paginate(15, ['*'], 'webhook_page');

        // Submission events for the new tab
        $submissionEvents = SubmissionEvent::query()
            ->with(['tenant', 'terminal'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = $request->search;

                return $query->where(function ($q) use ($term) {
                    $q->where('submission_uuid', 'like', "%{$term}%")
                      ->orWhere('status', 'like', "%{$term}%")
                      ->orWhere('reason_code', 'like', "%{$term}%")
                      ->orWhere('correlation_id', 'like', "%{$term}%")
                      // Search inside JSON-encoded reason details for payload snippets, transaction IDs, etc.
                      ->orWhere('reason_details', 'like', "%{$term}%")
                      // Allow searching by tenant trade name
                      ->orWhereHas('tenant', function ($tenantQuery) use ($term) {
                          $tenantQuery->where('trade_name', 'like', "%{$term}%");
                      })
                      // Allow searching by terminal identifiers
                      ->orWhereHas('terminal', function ($terminalQuery) use ($term) {
                          $terminalQuery->where('serial_number', 'like', "%{$term}%")
                                        ->orWhere('machine_number', 'like', "%{$term}%");
                      });
                });
            })
            ->when($request->filled('date_from'), function ($query) use ($request) {
                return $query->whereDate('occurred_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function ($query) use ($request) {
                return $query->whereDate('occurred_at', '<=', $request->date_to);
            })
            ->when($request->filled('terminal'), function ($query) use ($request) {
                return $query->where('terminal_id', $request->terminal);
            })
            ->when($request->filled('type') && $request->type === 'payload_validation', function ($query) {
                // For payload validation focus, prefer submissions with explicit issues
                return $query->where(function ($q) {
                    $q->whereNotNull('reason_code')
                        ->orWhere('status', '!=', 'COMPLETED');
                });
            })
            ->when($request->filled('severity') && $request->severity === 'error', function ($query) {
                // Error / Critical severity maps to failed or partial submissions
                return $query->where(function ($q) {
                    $q->where('status', 'FAILED')
                        ->orWhere('status', 'REJECTED')
                        ->orWhereNotNull('reason_code');
                });
            })
            ->latest('occurred_at')
            ->latest('created_at')
            ->paginate(15, ['*'], 'submission_page');

        $stats = [
            'system' => SystemLog::count(),
            'errors' => SystemLog::where('log_type', 'error')->count(),
            'success' => SystemLog::where('log_type', 'info')->count(),
            'pending' => SystemLog::where('log_type', 'warning')->count(),
            'auth_events' => SystemLog::where('type', 'security')->count(),
            'login_success' => SystemLog::where('type', 'security')
                ->where('context->auth_event', 'login')->count(),
            'login_failed' => SystemLog::where('type', 'security')
                ->where('context->auth_event', 'login_failed')->count(),
            'total' => AuditLog::count(),
            'auth' => AuditLog::where('action_type', 'AUTH')->count(),
            'changes' => AuditLog::whereNotNull('old_values')->count(),
            'error_logs' => AuditLog::where('action', 'like', '%failed%')->count(),
            'webhook_total' => WebhookLog::count(),
            'webhook_success' => WebhookLog::where('status', 'SUCCESS')->count(),
            'webhook_errors' => WebhookLog::where('status', 'FAILED')->count(),
            'webhook_pending' => WebhookLog::where('status', 'PENDING')->count()
        ];

        // Get terminals for filter dropdown
        $terminalsList = PosTerminal::select('id', 'serial_number', 'machine_number')->get();

        if ($request->wantsJson()) {
            return response()->json([
                'systemLogs' => $systemLogs,
                'auditLogs' => $auditLogs,
                'webhookLogs' => $webhookLogs,
                'submissionEvents' => $submissionEvents,
                'stats' => $stats,
                'terminals' => $terminalsList
            ]);
        }

        return view('app');

        return view('dashboard.logs', [
            'systemLogs' => $systemLogs,
            'auditLogs' => $auditLogs,
            'webhookLogs' => $webhookLogs,
            'stats' => $stats,
            'terminals' => $terminalsList,
            'submissionEvents' => $submissionEvents
        ]);
    }

    /**
     * Show the prune form for administrators.
     */
    public function pruneForm(Request $request)
    {
        $user = auth()->user();
        if (!$user || !(method_exists($user, 'hasRole') ? $user->hasRole('admin') : (strtolower($user->role ?? '') === 'admin'))) {
            abort(403);
        }

        return view('logs.prune');
    }

    /**
     * Execute prune action (dry-run or actual deletion).
     */
    public function pruneExecute(Request $request)
    {
        $user = auth()->user();
        if (!$user || !(method_exists($user, 'hasRole') ? $user->hasRole('admin') : (strtolower($user->role ?? '') === 'admin'))) {
            abort(403);
        }

        $data = $request->validate([
            'before' => ['nullable', 'date'],
            'days' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'string'],
            'dry_run' => ['nullable', 'boolean'],
            'force' => ['nullable', 'boolean']
        ]);

        $dry = $request->boolean('dry_run');
        $force = $request->boolean('force');

        if (empty($data['before']) && empty($data['days'])) {
            return back()->with('error', 'You must provide either a `before` date or a number of `days` to prune.');
        }

        $svc = app(\App\Services\SystemLogService::class);
        $result = $svc->prune([
            'before' => $data['before'] ?? null,
            'days' => isset($data['days']) ? (int) $data['days'] : null,
            'type' => $data['type'] ?? null,
            'dry_run' => $dry || !$force,
            'chunk' => 500
        ]);

        if (!empty($result['error'])) {
            return back()->with('error', $result['error']);
        }

        if (!empty($result['dry_run'])) {
            $msg = 'Dry run: ' . $result['count'] . ' rows would be pruned.';
            if (!empty($result['sample_ids'])) {
                $msg .= ' Sample IDs: ' . implode(',', $result['sample_ids']);
            }
            return back()->with('status', $msg);
        }

        return back()->with('status', 'Prune complete. Deleted: ' . ($result['deleted'] ?? 0));
    }

    /**
     * Permanently remove a single system log (force delete).
     * Admin-only action.
     */
    public function hardDelete(Request $request, $id)
    {
        $user = auth()->user();
        if (!$user || !(method_exists($user, 'hasRole') ? $user->hasRole('admin') : (strtolower($user->role ?? '') === 'admin'))) {
            abort(403);
        }

        try {
            $log = SystemLog::withTrashed()->findOrFail($id);
            // Only allow hard delete if it's already soft-deleted, or explicitly allow (business rule)
            if (method_exists($log, 'trashed') && !$log->trashed()) {
                // For safety, require that log be soft-deleted before permanent removal
                return back()->with('error', 'Log must be soft-deleted first before permanent removal.');
            }

            $log->forceDelete();
            // Audit the admin action
            try {
                $svc = app(\App\Services\SystemLogService::class);
                $svc->logUserAction('logs.hard_delete', 'Admin permanently deleted a system log', ['log_id' => $id, 'user_id' => $user->id]);
            } catch (\Exception $e) {
                // best-effort
            }

            return back()->with('status', 'Log permanently deleted.');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to permanently delete log: ' . $e->getMessage());
        }
    }

    /**
     * Show archived (soft-deleted) system logs.
     */
    public function archived(Request $request)
    {
        $user = auth()->user();
        if (!$user || !(method_exists($user, 'hasRole') ? $user->hasRole('admin') : (strtolower($user->role ?? '') === 'admin'))) {
            abort(403);
        }

        $archivedLogs = SystemLog::onlyTrashed()->with('user')
            ->when($request->filled('type'), function ($q) use ($request) {
                return $q->where('type', $request->type);
            })
            ->latest()
            ->paginate(15);

        return view('logs.archived', compact('archivedLogs'));
    }

    /**
     * Bulk restore soft-deleted logs.
     */
    public function bulkRestore(Request $request)
    {
        $user = auth()->user();
        if (!$user || !(method_exists($user, 'hasRole') ? $user->hasRole('admin') : (strtolower($user->role ?? '') === 'admin'))) {
            abort(403);
        }

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer']
        ]);

        $ids = $data['ids'];
        try {
            SystemLog::withTrashed()->whereIn('id', $ids)->restore();
            // Audit
            try {
                app(\App\Services\SystemLogService::class)->logUserAction('logs.bulk_restore', 'Admin restored multiple system logs', ['count' => count($ids), 'ids' => $ids, 'user_id' => $user->id]);
            } catch (\Exception $e) {
            }
            return back()->with('status', 'Selected logs restored.');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to restore logs: ' . $e->getMessage());
        }
    }

    /**
     * Bulk permanently delete (forceDelete) soft-deleted logs.
     */
    public function bulkPurge(Request $request)
    {
        $user = auth()->user();
        if (!$user || !(method_exists($user, 'hasRole') ? $user->hasRole('admin') : (strtolower($user->role ?? '') === 'admin'))) {
            abort(403);
        }

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer']
        ]);

        $ids = $data['ids'];
        try {
            // Only force-delete trashed entries for safety
            $toPurge = SystemLog::onlyTrashed()->whereIn('id', $ids)->get();
            $deleted = 0;
            foreach ($toPurge as $log) {
                $log->forceDelete();
                $deleted++;
            }
            // Audit
            try {
                app(\App\Services\SystemLogService::class)->logUserAction('logs.bulk_purge', 'Admin permanently purged multiple system logs', ['count' => $deleted, 'ids' => array_column($toPurge->toArray(), 'id'), 'user_id' => $user->id]);
            } catch (\Exception $e) {
            }
            return back()->with('status', "Permanently deleted {$deleted} logs.");
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to purge logs: ' . $e->getMessage());
        }
    }

    /**
     * Bulk soft-delete selected logs (mark deleted_at).
     */
    public function bulkSoftDelete(Request $request)
    {
        $user = auth()->user();
        if (!$user || !(method_exists($user, 'hasRole') ? $user->hasRole('admin') : (strtolower($user->role ?? '') === 'admin'))) {
            abort(403);
        }

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer']
        ]);

        $ids = $data['ids'];
        try {
            $affected = SystemLog::whereIn('id', $ids)->update(['deleted_at' => now()]);
            // Audit
            try {
                app(\App\Services\SystemLogService::class)->logUserAction('logs.bulk_soft_delete', 'Admin soft-deleted multiple system logs', ['count' => $affected, 'ids' => $ids, 'user_id' => $user->id]);
            } catch (\Exception $e) {
            }
            return back()->with('status', "Soft-deleted {$affected} logs.");
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to soft-delete logs: ' . $e->getMessage());
        }
    }
}