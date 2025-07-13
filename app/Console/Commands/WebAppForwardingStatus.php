<?php

namespace App\Console\Commands;

use App\Services\WebAppForwardingService;
use App\Models\WebappTransactionForward;
use Illuminate\Console\Command;

class WebAppForwardingStatus extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'tsms:forwarding-status 
                           {--watch : Watch mode - refresh every 10 seconds}
                           {--detailed : Show detailed breakdown}';

    /**
     * The console command description.
     */
    protected $description = 'Show web app forwarding status and health';

    /**
     * Execute the console command.
     */
    public function handle(WebAppForwardingService $forwardingService): int
    {
        if ($this->option('watch')) {
            return $this->watchMode($forwardingService);
        }

        $this->displayStatus($forwardingService);
        return 0;
    }

    /**
     * Watch mode - continuously refresh status
     */
    private function watchMode(WebAppForwardingService $forwardingService): int
    {
        $this->info('WebApp Forwarding Status - Watch Mode (Press Ctrl+C to exit)');
        $this->line('Refreshing every 10 seconds...');
        $this->line('');

        while (true) {
            // Clear screen
            $this->line("\033[2J\033[H");
            
            $this->line('🔄 Last updated: ' . now()->format('Y-m-d H:i:s'));
            $this->line('');
            
            $this->displayStatus($forwardingService);
            
            sleep(10);
        }
    }

    /**
     * Display current status
     */
    private function displayStatus(WebAppForwardingService $forwardingService): void
    {
        $stats = $forwardingService->getForwardingStats();
        
        // Configuration status
        $this->info('📋 Configuration Status:');
        $enabled = config('tsms.web_app.enabled', false);
        $endpoint = config('tsms.web_app.endpoint', 'not configured');
        $this->line("   Enabled: " . ($enabled ? '<fg=green>YES</>' : '<fg=red>NO</>'));
        $this->line("   Endpoint: {$endpoint}");
        $this->line("   Batch Size: " . config('tsms.web_app.batch_size', 50));
        $this->line('');

        // Transaction counts
        $this->info('📊 Transaction Status:');
        $this->line("   Unforwarded: {$stats['unforwarded_transactions']}");
        $this->line("   Pending forwards: {$stats['pending_forwards']}");
        $this->line("   Completed forwards: {$stats['completed_forwards']}");
        $this->line("   Failed forwards: {$stats['failed_forwards']}");
        $this->line('');

        // Circuit breaker status
        $cb = $stats['circuit_breaker'];
        $this->info('🔧 Circuit Breaker:');
        $status = $cb['is_open'] ? '<fg=red>OPEN</>' : '<fg=green>CLOSED</>';
        $this->line("   Status: {$status}");
        $this->line("   Failures: {$cb['failures']}");
        
        if ($cb['last_failure']) {
            $lastFailure = \Carbon\Carbon::parse($cb['last_failure'])->diffForHumans();
            $this->line("   Last failure: {$lastFailure}");
        }
        $this->line('');

        // Ready for retry
        $readyForRetry = WebappTransactionForward::readyForRetry()->count();
        if ($readyForRetry > 0) {
            $this->warn("🔄 {$readyForRetry} forwards ready for retry");
            $this->line('');
        }

        // Show detailed breakdown if requested
        if ($this->option('detailed')) {
            $this->displayDetailedBreakdown();
        }

        // Recent activity
        $this->displayRecentActivity();

        // System health check
        $this->displayHealthCheck($forwardingService);
    }

    /**
     * Display detailed breakdown
     */
    private function displayDetailedBreakdown(): void
    {
        $this->info('📈 Detailed Breakdown:');
        
        // Forwards by status in last 24 hours
        $statuses = ['pending', 'in_progress', 'completed', 'failed'];
        foreach ($statuses as $status) {
            $count = WebappTransactionForward::where('status', $status)
                ->where('created_at', '>=', now()->subDay())
                ->count();
            $this->line("   {$status}: {$count}");
        }
        $this->line('');

        // Failed forwards by error type (last 24 hours)
        $failedForwards = WebappTransactionForward::failed()
            ->where('updated_at', '>=', now()->subDay())
            ->whereNotNull('error_message')
            ->selectRaw('LEFT(error_message, 50) as error_type, COUNT(*) as count')
            ->groupBy('error_type')
            ->get();

        if ($failedForwards->isNotEmpty()) {
            $this->info('❌ Recent Failure Types:');
            foreach ($failedForwards as $failure) {
                $this->line("   {$failure->error_type}...: {$failure->count}");
            }
            $this->line('');
        }
    }

    /**
     * Display recent activity
     */
    private function displayRecentActivity(): void
    {
        $this->info('⏱️ Recent Activity (Last 24 hours):');
        
        $recentCompleted = WebappTransactionForward::completed()
            ->where('completed_at', '>=', now()->subDay())
            ->count();
        $recentFailed = WebappTransactionForward::failed()
            ->where('updated_at', '>=', now()->subDay())
            ->count();
        $recentCreated = WebappTransactionForward::where('created_at', '>=', now()->subDay())
            ->count();
            
        $this->line("   Created: {$recentCreated}");
        $this->line("   Completed: {$recentCompleted}");
        $this->line("   Failed: {$recentFailed}");
        
        if ($recentCreated > 0) {
            $successRate = round(($recentCompleted / $recentCreated) * 100, 1);
            $color = $successRate >= 90 ? 'green' : ($successRate >= 70 ? 'yellow' : 'red');
            $this->line("   Success Rate: <fg={$color}>{$successRate}%</>");
        }
        $this->line('');
    }

    /**
     * Display health check
     */
    private function displayHealthCheck(WebAppForwardingService $forwardingService): void
    {
        $this->info('🏥 Health Check:');
        
        $issues = [];
        
        // Check if webapp forwarding is enabled
        if (!config('tsms.web_app.enabled', false)) {
            $issues[] = 'WebApp forwarding is disabled';
        }
        
        // Check if endpoint is configured
        if (!config('tsms.web_app.endpoint')) {
            $issues[] = 'WebApp endpoint not configured';
        }
        
        // Check circuit breaker status
        $cb = $forwardingService->getCircuitBreakerStatus();
        if ($cb['is_open']) {
            $issues[] = 'Circuit breaker is open';
        }
        
        // Check for old pending forwards
        $oldPending = WebappTransactionForward::pending()
            ->where('created_at', '<', now()->subHour())
            ->count();
        if ($oldPending > 0) {
            $issues[] = "{$oldPending} forwards pending for over 1 hour";
        }
        
        // Check for excessive failures
        $recentFailures = WebappTransactionForward::failed()
            ->where('updated_at', '>=', now()->subHour())
            ->count();
        if ($recentFailures > 10) {
            $issues[] = "{$recentFailures} failures in the last hour";
        }
        
        if (empty($issues)) {
            $this->line('   <fg=green>✅ All systems healthy</>', true);
        } else {
            foreach ($issues as $issue) {
                $this->line("   <fg=red>❌ {$issue}</>");
            }
        }
        
        $this->line('');
        
        // Show commands
        $this->comment('💡 Available commands:');
        $this->line('   php artisan tsms:forward-transactions --dry-run  # Test run');
        $this->line('   php artisan tsms:forward-transactions            # Forward transactions');
        $this->line('   php artisan tsms:forward-transactions --retry    # Retry failed forwards');
        $this->line('   php artisan tsms:forward-transactions --force    # Bypass circuit breaker');
    }
}
