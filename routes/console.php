<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ForwardTransactionsToWebAppJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// WebApp Transaction Forwarding - Every 5 minutes
Schedule::job(new ForwardTransactionsToWebAppJob())
    ->everyFiveMinutes()
    ->name('webapp-transaction-forwarding')
    ->withoutOverlapping()
    ->onOneServer()
    ->when(function () {
        // Only run if forwarding is enabled
        return config('tsms.web_app.enabled', false);
    });

// Health check for WebApp forwarding - Every hour
Schedule::call(function () {
    try {
        $service = app(\App\Services\WebAppForwardingService::class);
        $stats = $service->getForwardingStats();
        
        // Log health status
        \Log::info('WebApp forwarding health check', [
            'unforwarded_transactions' => $stats['unforwarded_transactions'],
            'pending_forwards' => $stats['pending_forwards'],
            'failed_forwards' => $stats['failed_forwards'],
            'circuit_breaker_open' => $stats['circuit_breaker']['is_open'],
            'circuit_breaker_failures' => $stats['circuit_breaker']['failures'],
        ]);
        
        // Alert on circuit breaker open
        if ($stats['circuit_breaker']['is_open']) {
            \Log::warning('WebApp forwarding circuit breaker is open', $stats['circuit_breaker']);
        }
        
        // Alert on high failure count
        if ($stats['failed_forwards'] > 50) {
            \Log::warning('High number of failed WebApp forwards', [
                'failed_count' => $stats['failed_forwards']
            ]);
        }
        
        // Alert on old pending forwards
        $oldPending = \App\Models\WebappTransactionForward::pending()
            ->where('created_at', '<', now()->subHour())
            ->count();
            
        if ($oldPending > 0) {
            \Log::warning('Old pending WebApp forwards detected', [
                'old_pending_count' => $oldPending
            ]);
        }

    } catch (\Exception $e) {
        \Log::error('WebApp forwarding health check failed', [
            'error' => $e->getMessage()
        ]);
    }
})->hourly()->name('webapp-forwarding-health-check')->onOneServer();

// Cleanup old completed forwards - Daily at 2 AM
Schedule::call(function () {
    try {
        $cleanupDays = config('tsms.performance.cleanup_completed_after_days', 30);
        $failedCleanupDays = config('tsms.performance.cleanup_failed_after_days', 7);
        
        if (config('tsms.performance.enable_auto_cleanup', true)) {
            // Cleanup old completed forwards
            $completedDeleted = \App\Models\WebappTransactionForward::completed()
                ->where('completed_at', '<', now()->subDays($cleanupDays))
                ->delete();
            
            // Cleanup old failed forwards that have exhausted retries
            $failedDeleted = \App\Models\WebappTransactionForward::failed()
                ->where('updated_at', '<', now()->subDays($failedCleanupDays))
                ->where('attempts', '>=', 'max_attempts')
                ->delete();
            
            if ($completedDeleted > 0 || $failedDeleted > 0) {
                \Log::info('WebApp forwarding cleanup completed', [
                    'completed_deleted' => $completedDeleted,
                    'failed_deleted' => $failedDeleted,
                ]);
            }
        }
    } catch (\Exception $e) {
        \Log::error('WebApp forwarding cleanup failed', [
            'error' => $e->getMessage()
        ]);
    }
})->dailyAt('02:00')->name('webapp-forwarding-cleanup')->onOneServer();
