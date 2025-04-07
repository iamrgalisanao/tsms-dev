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

    /**
     * Handles the retry logic for a failed transaction log.
     *
     * This method checks if the transaction log status is 'FAILED' before proceeding.
     * It attempts to resend the transaction request to a configured endpoint and updates
     * the log with the response details, including status, payload, HTTP status code,
     * retry count, next retry time, and response metadata such as headers and latency.
     *
     * In case of an exception, it updates the log with the error message, increments
     * the retry count, and schedules the next retry attempt.
     *
     * @return void
     */
    public function handle(): void
    
    {
       
        if ($this->log->status !== 'FAILED') {
            return;
        }
        /**
         * Retrieves the retry transaction endpoint from the application configuration.
         * Throws a RuntimeException if the endpoint is not configured.
         *
         * @throws \RuntimeException If the 'RETRY_TRANSACTION_ENDPOINT' configuration is missing.
         */
        $endpoint = config('app.retry_transaction_endpoint');
        $token = config('app.retry_transaction_token');
        if (!$endpoint || !$token) {
            throw new \RuntimeException('Missing retry transaction endpoint or token in configuration.');
        }

        try {
            $startTime = microtime(true);
        
            $response = Http::post(
                config('app.retry_transaction_endpoint'),
                $this->log->request_payload
            );
        
            $endTime = microtime(true);
        
            // Capture first before decoding
            $headers = $response->headers();
            $body = $response->body();
            $jsonPayload = json_decode($body, true);
        
            $this->log->status = $response->successful() ? 'SUCCESS' : 'FAILED';
            $this->log->response_payload = $jsonPayload ?? $body;
            $this->log->http_status_code = $response->status();
            $this->log->retry_count += 1;
            $this->log->next_retry_at = now()->addMinutes(5);
        
            $this->log->response_metadata = [
                'headers' => $headers,
                'latency_ms' => round(($endTime - $startTime) * 1000, 2),
            ];

            dd([
                'headers' => $headers,
                'latency_ms' => round(($endTime - $startTime) * 1000, 2),
                'raw_response' => $response->body(),
                'status_code' => $response->status(),
            ]);
        
            $this->log->save();
        } catch (\Throwable $e) {
            $this->log->status = 'FAILED';
            $this->log->error_message = $e->getMessage();
            $this->log->retry_count += 1;
            $this->log->next_retry_at = now()->addMinutes(5);
        
            $this->log->response_metadata = [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        
            $this->log->save();
        }
        
        
    }
}

