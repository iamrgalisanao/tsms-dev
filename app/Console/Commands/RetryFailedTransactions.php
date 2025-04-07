<?php

namespace App\Console\Commands;

use App\Models\IntegrationLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Console\Command;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RetryFailedTransactions extends Command

{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected IntegrationLog $log; // ✅ Properly typed property
    protected $signature = 'transactions:retry-failed';
    protected $description = 'Dispatch retry jobs for all failed transactions';

    public function __construct()
    {
        parent::__construct();     
    }



   

    public function handle(): void
    {
        // Initialize $log with a valid IntegrationLog instance
        $this->log = IntegrationLog::where('status', 'FAILED')->first();

        if (!$this->log) {
            $this->error('No failed transactions found.');
            return;
        }
        // Now $this->log is recognized correctly

        if ($this->log->status !== 'FAILED') {
            return;
        }

        try {
            $startTime = microtime(true);

            $response = Http::post(
                config('app.retry_transaction_endpoint'),
                $this->log->request_payload
            );

            $endTime = microtime(true);
            $headers = $response->headers();
            $body = $response->body();
            $jsonPayload = json_decode($body, true);

            $this->log->status = $response->successful() ? 'SUCCESS' : 'FAILED';
            $this->log->response_payload = $jsonPayload ?? $body;
            $this->log->http_status_code = $response->status();
            $this->log->retry_count += 1;
            $this->log->next_retry_at = now()->addMinutes(5);
            $this->log->response_time = round(($endTime - $startTime) * 1000, 2);

            $this->log->retry_reason = 'RETRY_ATTEMPT';
            $this->log->source_ip = gethostbyname(gethostname());

            $this->log->validation_status = is_array($jsonPayload) && isset($jsonPayload['errors'])
                ? 'FAILED'
                : 'PASSED';

            $this->log->response_metadata = [
                'headers' => $headers,
                'latency_ms' => $this->log->response_time,
            ];

            $this->log->save();
            $this->info("✅ Retry attempt complete. Status: {$this->log->status}, HTTP: {$this->log->http_status_code}");
        } catch (\Throwable $e) {
            $this->log->status = 'FAILED';
            $this->log->error_message = $e->getMessage();
            $this->log->retry_count += 1;
            $this->log->next_retry_at = now()->addMinutes(5);
            $this->log->retry_reason = 'RETRY_EXCEPTION';

            $this->log->response_metadata = [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];

            $this->log->save();
            $this->error("❌ Retry attempt failed: {$e->getMessage()}");
        }

    }
}

