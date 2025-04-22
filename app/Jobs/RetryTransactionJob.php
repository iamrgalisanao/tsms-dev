<?php

namespace App\Jobs;

use App\Models\IntegrationLog;
use App\Models\PosTerminal;
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
    protected $terminal;

    public function __construct(IntegrationLog $log)
    {
        $this->log = $log;
        $this->terminal = PosTerminal::find($log->terminal_id);
    }

    /**
     * Terminal-aware retry interval.
     */
    public function backoff(): array
    {
        if ($this->terminal && $this->terminal->retry_enabled) {
            $interval = $this->terminal->retry_interval_sec ?? 60;
            return [$interval];
        }

        return [60]; // default fallback
    }

    public function handle(): void
    {
        if (!$this->terminal || !$this->terminal->retry_enabled) {
            return;
        }

        if ($this->log->status !== 'FAILED') {
            return;
        }

        // Abort if retries exceed max allowed
        if ($this->terminal->max_retries > 0 &&
            $this->log->retry_count >= $this->terminal->max_retries) {
            $this->log->status = 'PERMANENTLY_FAILED';
            $this->log->retry_reason = 'MAX_RETRIES_EXCEEDED';
            $this->log->save();
            return;
        }

        $endpoint = config('app.retry_transaction_endpoint');
        if (!$endpoint) {
            throw new \RuntimeException('Missing RETRY_TRANSACTION_ENDPOINT config.');
        }

        $token = TerminalToken::where('terminal_id', $this->log->terminal_id)
            ->latest()
            ->first();

        if (in_array($this->log->status, ['SUCCESS', 'PERMANENTLY_FAILED'])) {
            return;
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
            $responseTime = round(($endTime - $startTime) * 1000, 2);
            $headers = $response->headers();
            $body = $response->body();
            $jsonPayload = json_decode($body, true);

            $this->log->status = $response->successful() ? 'SUCCESS' : 'FAILED';
            $this->log->response_payload = $jsonPayload ?? $body;
            $this->log->http_status_code = $response->status();
            $this->log->retry_count += 1;
            $this->log->next_retry_at = now()->addSeconds($this->terminal->retry_interval_sec ?? 300);
            $this->log->response_time = $responseTime;
            $this->log->validation_status = isset($jsonPayload['errors']) ? 'FAILED' : 'PASSED';
            $this->log->retry_reason = 'RETRY_ATTEMPT';
            $this->log->source_ip = gethostbyname(gethostname());
            $this->log->response_metadata = [
                'headers' => $headers,
                'latency_ms' => $responseTime,
            ];

            $this->log->save();
        } catch (\Throwable $e) {
            $this->log->status = 'FAILED';
            $this->log->error_message = $e->getMessage();
            $this->log->retry_count += 1;
            $this->log->next_retry_at = now()->addSeconds($this->terminal->retry_interval_sec ?? 300);
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
