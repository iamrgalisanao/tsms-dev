<?php

namespace App\Jobs;

use App\Models\WebhookLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;

class DispatchWebhookRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected WebhookLog $log;

    /**
     * Create a new job instance.
     */
    public function __construct(WebhookLog $log)
    {
        $this->log = $log;

        // NOTE: cannot use `public $queue = 'webhook-callbacks';` — Queueable
        // already declares an untyped `public $queue;` (default null), and
        // PHP 8.4 treats a class re-declaring a trait property with a
        // different default value as an incompatible composition (hard
        // fatal error at class-load time). `onQueue()` here is an equally
        // explicit, approved assignment mechanism (see T046a in
        // specs/001-100-tenant-resilience/tasks.md).
        $this->onQueue('webhook-callbacks');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->log->status === 'SUCCESS' || $this->log->retry_count >= $this->log->max_retries) {
            return; // Do not retry if already successful or exceeded limit
        }

        $this->log->last_attempt_at = now();
        $this->log->retry_count += 1;

        try {
            $response = Http::timeout(10)->post(
                $this->log->terminal->webhook_url,
                json_decode($this->log->response_body, true)
            );

            $this->log->http_code = $response->status();
            $this->log->status = $response->successful() ? 'SUCCESS' : 'FAILED';
            $this->log->error_message = $response->successful() ? null : $response->body();

        } catch (\Throwable $e) {
            $this->log->status = 'FAILED';
            $this->log->error_message = $e->getMessage();
        }

        // Schedule next retry
        if ($this->log->status !== 'SUCCESS') {
            $delay = pow(2, $this->log->retry_count) * 60; // exponential backoff
            $this->log->next_retry_at = now()->addSeconds($delay);
        }

        $this->log->save();
    }
}
