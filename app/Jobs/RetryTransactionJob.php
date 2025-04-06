<?php

namespace App\Jobs;

use App\Models\IntegrationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;

class RetryTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $log;

    public function __construct(IntegrationLog $log)
    {
        $this->log = $log;
    }

    public function handle(): void
    {
        // Optional: Early exit if status isn't FAILED
        if ($this->log->status !== 'FAILED') {
            return;
        }

        try {
            // Simulate a resend attempt
            $response = Http::post(config('app.retry_transaction_endpoint'), $this->log->request_payload);

            $this->log->status = $response->successful() ? 'SUCCESS' : 'FAILED';
            $this->log->response_payload = $response->json();
            $this->log->http_status_code = $response->status();
            $this->log->retry_count += 1;
            $this->log->next_retry_at = now()->addMinutes(5); // configurable delay
            $this->log->save();
        } catch (\Throwable $e) {
            $this->log->status = 'FAILED';
            $this->log->error_message = $e->getMessage();
            $this->log->retry_count += 1;
            $this->log->next_retry_at = now()->addMinutes(5);
            $this->log->save();
        }
    }
}

