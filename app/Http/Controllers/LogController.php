<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SystemLog;
use App\Models\WebhookLog;
use App\Models\SubmissionEvent;
use Illuminate\Http\Request;
use App\Models\PosTerminal;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $systemLogs = SystemLog::with('user')
            ->when($request->filled('type'), function($query) use ($request) {
                return $query->where('log_type', $request->type);
            })
            ->when($request->filled('severity'), function($query) use ($request) {
                return $query->where('severity', $request->severity);
            })
            ->when($request->filled('date_from'), function($query) use ($request) {
                return $query->whereDate('created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function($query) use ($request) {
                return $query->whereDate('created_at', '<=', $request->date_to);
            })
            ->when($request->filled('terminal'), function($query) use ($request) {
                return $query->where('serial_number', $request->terminal);
            })
            ->latest()
            ->paginate(15, ['*'], 'system_page');
            
        $auditLogs = AuditLog::with('user')
            ->when($request->filled('search'), function($query) use ($request) {
                return $query->where('action', 'like', "%{$request->search}%")
                    ->orWhere('resource_type', 'like', "%{$request->search}%");
            })
            ->when($request->filled('type'), function($query) use ($request) {
                return $query->where('action_type', $request->type);
            })
            ->when($request->filled('date_from'), function($query) use ($request) {
                return $query->whereDate('created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function($query) use ($request) {
                return $query->whereDate('created_at', '<=', $request->date_to);
            })
            ->latest()
            ->paginate(15, ['*'], 'audit_page');

        $webhookLogs = WebhookLog::with('terminal')
            ->when($request->filled('search'), function($query) use ($request) {
                return $query->where('endpoint', 'like', "%{$request->search}%");
            })
            ->when($request->filled('status'), function($query) use ($request) {
                return $query->where('status', $request->status);
            })
            ->when($request->filled('date_from'), function($query) use ($request) {
                return $query->whereDate('created_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function($query) use ($request) {
                return $query->whereDate('created_at', '<=', $request->date_to);
            })
            ->latest()
            ->paginate(15, ['*'], 'webhook_page');

        // Submission events for the new tab
        $submissionEvents = SubmissionEvent::latest('occurred_at')
            ->latest('created_at')
            ->paginate(15, ['*'], 'submission_page');

        $stats = [
            'system' => SystemLog::count(),
            'errors' => SystemLog::where('severity', 'error')->count(),
            'success' => SystemLog::where('severity', 'info')->count(),
            'pending' => SystemLog::where('severity', 'pending')->count(),
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
        $terminals = PosTerminal::select('id', 'serial_number')->get();

        return view('dashboard.logs', compact('systemLogs', 'auditLogs', 'webhookLogs', 'stats', 'terminals', 'submissionEvents'));
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
            'before' => ['nullable','date'],
            'days' => ['nullable','integer','min:1'],
            'type' => ['nullable','string'],
            'dry_run' => ['nullable','boolean'],
            'force' => ['nullable','boolean']
        ]);

        $dry = $request->boolean('dry_run');
        $force = $request->boolean('force');

        if (empty($data['before']) && empty($data['days'])) {
            return back()->with('error', 'You must provide either a `before` date or a number of `days` to prune.');
        }

        $svc = app(\App\Services\SystemLogService::class);
        $result = $svc->prune([
            'before' => $data['before'] ?? null,
            'days' => isset($data['days']) ? (int)$data['days'] : null,
            'type' => $data['type'] ?? null,
            'dry_run' => $dry || !$force,
            'chunk' => 500
        ]);

        if (!empty($result['error'])) {
            return back()->with('error', $result['error']);
        }

        if (!empty($result['dry_run'])) {
            $msg = 'Dry run: '.$result['count'].' rows would be pruned.';
            if (!empty($result['sample_ids'])) {
                $msg .= ' Sample IDs: '.implode(',', $result['sample_ids']);
            }
            return back()->with('status', $msg);
        }

        return back()->with('status', 'Prune complete. Deleted: '.($result['deleted'] ?? 0));
    }
}