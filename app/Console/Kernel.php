<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // WebApp Transaction Forwarding - Every 5 minutes
        $schedule->command('tsms:forward-transactions')
                 ->everyFiveMinutes()
                 ->withoutOverlapping()      // Prevents multiple instances running
                 ->runInBackground()         // Non-blocking execution
                 ->appendOutputTo(storage_path('logs/webapp-forwarding.log'))
                 ->onFailure(function () {
                     \Log::error('WebApp forwarding scheduled job failed');
                 })
                 ->when(function () {
                     // Only run if forwarding is enabled
                     return config('tsms.web_app.enabled', false);
                 });

        // Retry failed forwards - Every 15 minutes
        $schedule->command('tsms:forward-transactions --retry')
                 ->everyFifteenMinutes()
                 ->withoutOverlapping()
                 ->runInBackground()
                 ->appendOutputTo(storage_path('logs/webapp-forwarding-retry.log'))
                 ->when(function () {
                     return config('tsms.web_app.enabled', false);
                 });

        // Health monitoring - Every hour
        $schedule->call(function () {
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
        })->hourly()->runInBackground();
        
        // Cleanup old completed forwards - Daily at 2 AM
        $schedule->call(function () {
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
        })->dailyAt('02:00')->runInBackground();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
