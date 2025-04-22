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
     * Get the backoff times for retrying the job.
     *
     * This method defines the intervals (in seconds) at which the job
     * should be retried in case of failure. The backoff times are
     * specified as an array of integers.
     *
     * @return array The array of backoff times in seconds.
     */
    public function backoff(): array
    {
        return [ 10, 30, 60, 120, 300];
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

        if ($this->log->retry_count >= $this->log->max_retries) {
            $this->log->status = 'PERMANENTLY_FAILED';
            $this->log->retry_reason = 'MAX_RETRIES_EXCEEDED';
            $this->log->next_retry_at = null;
            $this->log->save();
        
            $this->triggerWebhook(); // notify webhook of terminal failure
            return;
        }
        // Check if the log status is 'FAILED' before proceeding
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

        /**
         * Checks the status of the log and triggers a webhook if the status is either 'SUCCESS' or 'PERMANENTLY_FAILED'.
         *
         * @return void
         */
        if (in_array($this->log->status, ['SUCCESS', 'PERMANENTLY_FAILED'])) {
            $this->triggerWebhook();
        }
            

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


            /**
             * Checks the status of the log and triggers a webhook if the status is 'SUCCESS'.
             *
             * @return void
             */
            if ($this->log->status === 'SUCCESS') {
                $this->triggerWebhook(); 
            }

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

    /**
     * Triggers a webhook for the associated terminal if a webhook URL is configured.
     *
     * This method sends a POST request to the terminal's webhook URL with details
     * about the transaction log, including transaction ID, status, HTTP status code,
     * validation status, retry attempts, retry reason, and the finalized timestamp.
     *
     * @return void
     */
    protected function triggerWebhook()
    {
        $terminal = $this->log->terminal;

        if (!$terminal || !$terminal->webhook_url) {
            return; // No webhook configured
        }

        Http::post($terminal->webhook_url, [
            'transaction_id'     => $this->log->request_payload['transaction_id'] ?? null,
            'status'             => $this->log->status,
            'http_status_code'   => $this->log->http_status_code,
            'validation_status'  => $this->log->validation_status,
            'retry_attempts'     => $this->log->retry_attempts,
            'retry_reason'       => $this->log->retry_reason,
            'finalized_at'       => now()->toDateTimeString(),
        ]);
    }



}

