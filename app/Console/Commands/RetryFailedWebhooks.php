<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WebhookLog;
use App\Jobs\DispatchWebhookRetryJob;
use Illuminate\Support\Carbon;

class RetryFailedWebhooks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webhooks:retry-failed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch retry jobs for failed webhooks that are scheduled for retry.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $now = now();

        $failedLogs = WebhookLog::where('status', 'FAILED')
            ->where('next_retry_at', '<=', $now)
            ->whereColumn('retry_count', '<', 'max_retries')
            ->get();

        if ($failedLogs->isEmpty()) {
            $this->info('✅ No failed webhooks due for retry.');
            return;
        }

        foreach ($failedLogs as $log) {
            DispatchWebhookRetryJob::dispatch($log);
            $this->info("🔁 Retrying webhook log ID: {$log->id}");
        }

        $this->info('✅ Retry dispatch complete.');
    }
}
