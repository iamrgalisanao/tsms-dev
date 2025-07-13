<?php

namespace App\Console\Commands;

use App\Services\WebAppForwardingService;
use App\Jobs\ForwardTransactionsToWebAppJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ForwardTransactionsToWebApp extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'tsms:forward-transactions 
                           {--dry-run : Show what would be forwarded without executing}
                           {--force : Force forwarding even if circuit breaker is open}
                           {--retry : Only retry failed forwards instead of processing new ones}
                           {--stats : Show forwarding statistics}
                           {--queue : Queue the job for Horizon processing instead of running synchronously}';

    /**
     * The console command description.
     */
    protected $description = 'Forward validated transactions to web app in bulk (POS-safe operation)';

    /**
     * Execute the console command.
     */
    public function handle(WebAppForwardingService $forwardingService): int
    {
        try {
            // Check if webapp forwarding is enabled
            if (!config('tsms.web_app.enabled', false)) {
                $this->warn('WebApp forwarding is disabled in configuration');
                return 0;
            }

            // Show statistics if requested
            if ($this->option('stats')) {
                return $this->showStatistics($forwardingService);
            }

            // Queue mode - dispatch job to Horizon
            if ($this->option('queue')) {
                ForwardTransactionsToWebAppJob::dispatch();
                $this->info('WebApp forwarding job has been queued for processing by Horizon');
                $this->line('The job will be processed by the notification-supervisor queue worker');
                return 0;
            }

            // Dry run mode
            if ($this->option('dry-run')) {
                return $this->performDryRun($forwardingService);
            }

            // Force mode - bypass circuit breaker
            if ($this->option('force')) {
                $this->warn('Forcing forwarding (bypassing circuit breaker)');
                \Illuminate\Support\Facades\Cache::forget('webapp_forwarding_circuit_breaker_failures');
                \Illuminate\Support\Facades\Cache::forget('webapp_forwarding_circuit_breaker_last_failure');
            }

            // Retry mode - only process failed forwards
            if ($this->option('retry')) {
                return $this->performRetry($forwardingService);
            }

            // Normal execution - process unforwarded transactions
            return $this->performForwarding($forwardingService);

        } catch (\Exception $e) {
            $this->error('Command execution failed: ' . $e->getMessage());
            Log::error('WebApp forwarding command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * Perform dry run to show what would be forwarded
     */
    private function performDryRun(WebAppForwardingService $forwardingService): int
    {
        $stats = $forwardingService->getForwardingStats();
        
        $this->info('=== DRY RUN MODE ===');
        $this->line("Unforwarded transactions: {$stats['unforwarded_transactions']}");
        $this->line("Pending forwards: {$stats['pending_forwards']}");
        $this->line("Failed forwards ready for retry: " . 
                   \App\Models\WebappTransactionForward::readyForRetry()->count());
        
        $batchSize = config('tsms.web_app.batch_size', 50);
        $wouldProcess = min($stats['unforwarded_transactions'], $batchSize);
        
        $this->info("Would process {$wouldProcess} transactions in this batch");
        $this->line('');
        $this->comment('This operation is POS-safe: only reads data, no interference with POS operations');
        
        return 0;
    }

    /**
     * Perform retry of failed forwards
     */
    private function performRetry(WebAppForwardingService $forwardingService): int
    {
        $this->info('Retrying failed transaction forwards...');
        
        $result = $forwardingService->retryFailedForwardings();
        
        if ($result['success']) {
            $count = $result['retried_count'] ?? 0;
            if ($count > 0) {
                $this->info("Successfully retried {$count} failed forwards");
                $this->line("Batch ID: " . ($result['batch_id'] ?? 'N/A'));
            } else {
                $this->info('No failed forwards ready for retry');
            }
        } else {
            $this->error("Failed to retry forwards: " . ($result['error'] ?? 'Unknown error'));
            return 1;
        }
        
        return 0;
    }

    /**
     * Perform normal forwarding of unforwarded transactions
     */
    private function performForwarding(WebAppForwardingService $forwardingService): int
    {
        $this->info('Processing unforwarded transactions...');
        
        $result = $forwardingService->processUnforwardedTransactions();
        
        if ($result['success']) {
            $count = $result['forwarded_count'] ?? 0;
            if ($count > 0) {
                $this->info("Successfully forwarded {$count} transactions");
                $this->line("Batch ID: " . ($result['batch_id'] ?? 'N/A'));
                $this->comment('POS operations continue unaffected - forwarding records updated in side table');
            } else {
                $reason = $result['reason'] ?? 'no_transactions';
                $this->info("No transactions to forward ({$reason})");
            }
        } else {
            $error = $result['error'] ?? 'Unknown error';
            $reason = $result['reason'] ?? '';
            
            if ($reason === 'circuit_breaker_open') {
                $this->warn('Circuit breaker is open - skipping forwarding');
                $this->line('Use --force to bypass circuit breaker or wait for auto-recovery');
            } else {
                $this->error("Failed to forward transactions: {$error}");
            }
            
            $this->comment('POS operations continue unaffected - no changes made to transaction data');
            return 1;
        }
        
        return 0;
    }

    /**
     * Show forwarding statistics
     */
    private function showStatistics(WebAppForwardingService $forwardingService): int
    {
        $stats = $forwardingService->getForwardingStats();
        
        $this->info('=== WebApp Forwarding Statistics ===');
        $this->line('');
        
        $this->line("📋 Unforwarded transactions: {$stats['unforwarded_transactions']}");
        $this->line("⏳ Pending forwards: {$stats['pending_forwards']}");
        $this->line("✅ Completed forwards: {$stats['completed_forwards']}");
        $this->line("❌ Failed forwards: {$stats['failed_forwards']}");
        
        $this->line('');
        $this->line('🔧 Circuit Breaker Status:');
        $cb = $stats['circuit_breaker'];
        $status = $cb['is_open'] ? '<fg=red>OPEN</>' : '<fg=green>CLOSED</>';
        $this->line("   Status: {$status}");
        $this->line("   Failures: {$cb['failures']}");
        
        if ($cb['last_failure']) {
            $lastFailure = \Carbon\Carbon::parse($cb['last_failure'])->diffForHumans();
            $this->line("   Last failure: {$lastFailure}");
        }
        
        // Additional useful stats
        $readyForRetry = \App\Models\WebappTransactionForward::readyForRetry()->count();
        $this->line('');
        $this->line("🔄 Ready for retry: {$readyForRetry}");
        
        // Recent activity (last 24 hours)
        $recentCompleted = \App\Models\WebappTransactionForward::completed()
            ->where('completed_at', '>=', now()->subDay())
            ->count();
        $recentFailed = \App\Models\WebappTransactionForward::failed()
            ->where('updated_at', '>=', now()->subDay())
            ->count();
            
        $this->line('');
        $this->line('📊 Last 24 hours:');
        $this->line("   Completed: {$recentCompleted}");
        $this->line("   Failed: {$recentFailed}");
        
        return 0;
    }
}
