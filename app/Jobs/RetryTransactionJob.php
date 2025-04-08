<?php

namespace App\Jobs;

use App\Models\IntegrationLog;
use App\Models\TerminalToken;
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
        // sleep(10);
        if ($this->log->status !== 'FAILED') {
            return;
        }

        // Step 1: Fetch endpoint
        $endpoint = config('app.retry_transaction_endpoint');
        if (!$endpoint) {
            throw new \RuntimeException('Missing RETRY_TRANSACTION_ENDPOINT config.');
        }

        // Step 2: Fetch token tied to the specific terminal
        $token = TerminalToken::where('terminal_id', $this->log->terminal_id)
            ->latest()
            ->first();

        if (!$token || !$token->token) {
            $this->log->response_metadata = ['error' => 'No valid terminal token found'];
            $this->log->error_message = 'Missing JWT token';
            $this->log->status = 'FAILED';
            $this->log->retry_reason = 'TOKEN_MISSING';
            $this->log->save();
            return;
        }

        try {
            $startTime = microtime(true);

            $response = Http::withToken($token->token)
                ->acceptJson()
                ->post($endpoint, $this->log->request_payload);

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
            $this->log->validation_status = isset($jsonPayload['errors']) ? 'FAILED' : 'PASSED';
            $this->log->retry_reason = 'RETRY_ATTEMPT';
            $this->log->source_ip = gethostbyname(gethostname());
            $this->log->response_metadata = [
                'headers' => $headers,
                'latency_ms' => $this->log->response_time,
            ];

            $this->log->save();
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
        }
    }
}

