<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Support\TenantBreakerObserver;

class TenantBreakerObserveTestCommand extends Command
{
    protected $signature = 'tenant-breaker:observe-test {tenant_id} {--attempts=10} {--failures=5}';
    protected $description = 'Simulate per-tenant circuit breaker observation counters for quick manual testing';

    public function handle(TenantBreakerObserver $observer): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $attempts = (int) $this->option('attempts');
        $failures = (int) $this->option('failures');

        $this->info("Simulating attempts={$attempts}, failures={$failures} for tenant {$tenantId}");

        for ($i=0; $i<$attempts; $i++) {
            $observer->recordAttempt($tenantId);
        }
        for ($i=0; $i<$failures; $i++) {
            $observer->recordRetryableFailure($tenantId);
        }

        $eval = $observer->evaluate($tenantId);
        $this->line(json_encode($eval, JSON_PRETTY_PRINT));
        if ($eval && ($eval['eligible'] ?? false) && ($eval['over_threshold'] ?? false)) {
            $this->warn('Threshold would trigger observation warning log in real flow.');
        } else {
            $this->info('Threshold not crossed.');
        }
        return self::SUCCESS;
    }
}
