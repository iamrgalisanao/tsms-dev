<?php

namespace App\Services;

use App\Models\SystemLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class SystemLogService
{
    public function getStats(): array
    {
        $lastDay = now()->subDay();
        $lastHour = now()->subHour();

        return [
            'system' => SystemLog::where('type', 'system')
                ->where('created_at', '>=', $lastDay)
                ->count(),
                
            'errors' => SystemLog::where('severity', 'error')
                ->where('created_at', '>=', $lastDay)
                ->count(),
                
            'retries' => SystemLog::where('type', 'retry')
                ->where('created_at', '>=', $lastDay)
                ->count(),
                
            'completed' => SystemLog::where('type', 'transaction')
                ->where('created_at', '>=', $lastHour)
                ->count()
        ];
    }

    public function log(string $type, string $message, array $context = [], string $severity = 'info'): SystemLog
    {
        return SystemLog::create([
            'type' => $type,
            'log_type' => $context['log_type'] ?? $type,
            'message' => $message,
            'severity' => $severity,
            'context' => $context,
            'terminal_uid' => $context['terminal_uid'] ?? null,
            'transaction_id' => $context['transaction_id'] ?? null
        ]);
    }

    public function logUserAction($action, $description, $context = [])
    {
        return SystemLog::create([
            'type' => 'audit',
            'log_type' => 'user_action',
            'user_id' => Auth::id(),
            'action' => $action,
            'message' => $description,
            'context' => $context,
            'severity' => 'info'
        ]);
    }

    public function logWebhook($terminal, $status, $response, $error = null)
    {
        return SystemLog::create([
            'type' => 'webhook',
            'log_type' => 'outbound',
            'terminal_id' => $terminal->id,
            'status' => $status,
            'response_payload' => $response,
            'error_message' => $error,
            'severity' => $error ? 'error' : 'info'
        ]);
    }

    public function getEnhancedStats()
    {
        $lastDay = now()->subDay();
        
        return array_merge($this->getStats(), [
            'webhook_errors' => SystemLog::where('type', 'webhook')
                ->where('severity', 'error')
                ->where('created_at', '>=', $lastDay)
                ->count(),
            'user_actions' => SystemLog::where('type', 'audit')
                ->where('created_at', '>=', $lastDay)
                ->count()
        ]);
    }

    public function getFilteredLogs(array $filters): Collection
    {
        return SystemLog::query()
            ->when(isset($filters['type']), fn($q) => $q->where('type', $filters['type']))
            ->when(isset($filters['severity']), fn($q) => $q->where('severity', $filters['severity']))
            ->when(isset($filters['date']), fn($q) => $q->whereDate('created_at', $filters['date']))
            ->when(isset($filters['search']), fn($q) => $q->where('transaction_id', 'like', "%{$filters['search']}%"))
            ->latest()
            ->get();
    }

    /**
     * Prune system logs by date or age.
     *
     * Options array may contain:
     *  - before: (string) date string (Y-m-d) to delete logs strictly before this date
     *  - days: (int) delete logs older than N days
     *  - type: (string) optional log type filter
     *  - dry_run: (bool) if true, only return counts and sample ids
     *  - chunk: (int) chunk size for deletion
     *
     * Returns array with summary information.
     */
    public function prune(array $opts = []): array
    {
        $before = $opts['before'] ?? null;
        $days = isset($opts['days']) ? (int) $opts['days'] : null;
        $type = $opts['type'] ?? null;
    $dry = !empty($opts['dry_run']);
    $hard = !empty($opts['hard']);
        $chunk = isset($opts['chunk']) ? (int) $opts['chunk'] : 500;

        $query = SystemLog::query();

        if ($type) {
            $query->where('type', $type);
        }

        if ($before) {
            try {
                $dt = \Carbon\Carbon::parse($before)->startOfDay();
                $query->where('created_at', '<', $dt);
            } catch (\Exception $e) {
                // ignore parse errors; let caller validate
            }
        } elseif ($days) {
            $dt = now()->subDays($days)->startOfDay();
            $query->where('created_at', '<', $dt);
        } else {
            // No pruning criteria — don't allow accidental full-table deletion
            return ['error' => 'No prune criteria provided (use days or before)'];
        }

        $count = $query->count();

        if ($dry) {
            $sample = $query->limit(10)->pluck('id')->toArray();
            return [
                'dry_run' => true,
                'count' => $count,
                'sample_ids' => $sample,
            ];
        }

        $deleted = 0;
        // Use chunking to avoid locking huge sets in one query
        $query->orderBy('id')->chunkById($chunk, function($rows) use (&$deleted) {
            $ids = $rows->pluck('id')->toArray();
            if (!empty($ids)) {
                if ($hard) {
                    // Permanently delete rows
                    try {
                        $affected = SystemLog::whereIn('id', $ids)->forceDelete();
                        $deleted += is_int($affected) ? $affected : count($ids);
                    } catch (\Exception $e) {
                        // Fallback: delete via query builder if forceDelete isn't available
                        try {
                            $affected = \DB::table((new SystemLog)->getTable())->whereIn('id', $ids)->delete();
                            $deleted += is_int($affected) ? $affected : count($ids);
                        } catch (\Exception $ex) {
                            // best-effort; skip
                        }
                    }
                } else {
                    // Perform a soft-delete by setting deleted_at instead of hard-deleting
                    $affected = SystemLog::whereIn('id', $ids)->update(['deleted_at' => now()]);
                    $deleted += $affected;
                }
            }
        });

        // Write an audit record for the prune operation
        try {
            SystemLog::create([
                'type' => 'system',
                'log_type' => 'prune',
                'message' => 'Pruned system logs via SystemLogService',
                'context' => [
                        'deleted' => $deleted,
                        'hard' => $hard,
                        'criteria' => ['before' => $before, 'days' => $days, 'type' => $type]
                    ],
                'severity' => 'info'
            ]);
        } catch (\Exception $e) {
            // best-effort; do not fail the prune operation
        }

        return [
            'dry_run' => false,
            'deleted' => $deleted
        ];
    }

    public function getAuditHistory(array $filters = []): Collection
    {
        return SystemLog::where('type', 'audit')
            ->with('user')
            ->when(isset($filters['user_id']), fn($q) => $q->where('user_id', $filters['user_id']))
            ->latest()
            ->get();
    }

    public function getWebhookStats(): array
    {
        $last24h = now()->subDay();
        
        return [
            'total_sent' => SystemLog::where('type', 'webhook')->count(),
            'failed' => SystemLog::where('type', 'webhook')
                ->where('severity', 'error')
                ->where('created_at', '>=', $last24h)
                ->count(),
            'success_rate' => $this->calculateWebhookSuccessRate()
        ];
    }

    private function calculateWebhookSuccessRate(): float
    {
        $total = SystemLog::where('type', 'webhook')->count();
        if ($total === 0) return 0;
        
        $successful = SystemLog::where('type', 'webhook')
            ->where('severity', '!=', 'error')
            ->count();
            
        return round(($successful / $total) * 100, 2);
    }
}