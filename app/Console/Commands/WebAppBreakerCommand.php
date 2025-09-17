<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Services\WebAppForwardingService;

class WebAppBreakerCommand extends Command
{
    protected $signature = 'webapp:breaker {action=status : status|reset}';
    protected $description = 'Inspect or reset the WebApp forwarding circuit breaker state';

    private string $keyBase = 'webapp_forwarding_circuit_breaker';

    public function handle(): int
    {
        $action = $this->argument('action');
        if (!in_array($action, ['status','reset'])) {
            $this->error('Invalid action. Use status|reset');
            return 1;
        }

        if ($action === 'reset') {
            Cache::forget($this->keyBase . '_failures');
            Cache::forget($this->keyBase . '_last_failure');
            $this->info('Circuit breaker reset.');
        }

        $failures = Cache::get($this->keyBase . '_failures', 0);
        $last     = Cache::get($this->keyBase . '_last_failure');

        $svc = app(WebAppForwardingService::class);
        $stats = $svc->getForwardingStats();
        $isOpen = $stats['circuit_breaker']['is_open'];

        $this->line('Breaker State : ' . ($isOpen ? '<fg=red>OPEN</>' : '<fg=green>CLOSED</>'));
        $this->line('Failures      : ' . $failures);
        $this->line('Last Failure  : ' . ($last ?: 'n/a'));
        $this->line('Threshold     : ' . config('tsms.circuit_breaker.threshold', 5));
        $this->line('Cooldown (min): ' . config('tsms.circuit_breaker.cooldown', 10));

        return 0;
    }
}
