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