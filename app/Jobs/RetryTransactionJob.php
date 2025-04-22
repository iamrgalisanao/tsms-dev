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
use Illuminate\Support\Facades\Log;

class RetryTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected IntegrationLog $log;
    protected ?PosTerminal $terminal;

    public function __construct(IntegrationLog $log)
    {
        $this->log = $log;
        $this->terminal = PosTerminal::find($log->terminal_id);
    }

    /**
     * Configure backoff interval per terminal settings.
     */
    public function backoff(): array
    {
        return [$this->terminal->retry_interval_sec ?? 60];
    }

    public function handle(): void
    {
        if (!$this->terminal || !$this->terminal->retry_enabled) {
            Log::warning("[Retry] Terminal missing or retry disabled", ['terminal_id' => $this->log->terminal_id]);
            return;
        }

        if (!in_array($this->log->status, ['FAILED'])) {
            Log::info("[Retry] Skipped log with status: {$this->log->status}", ['log_id' => $this->log->id]);
            return;
        }

        if ($this->terminal->max_retries > 0 && $this->log->retry_count >= $this->terminal->max_retries) {
            $this->log->update([
                'status' => 'PERMANENTLY_FAILED',
                'retry_reason' => 'MAX_RETRIES_EXCEEDED',
            ]);
            Log::info("[Retry] Max retries exceeded", ['log_id' => $this->log->id]);
            return;
        }

        $endpoint = config('app.retry_transaction_endpoint');
        if (!$endpoint) {
            Log::error("[Retry] Missing RETRY_TRANSACTION_ENDPOINT config");
            throw new \RuntimeException('Missing RETRY_TRANSACTION_ENDPOINT config.');
        }

        $token = TerminalToken::where('terminal_id', $this->log->terminal_id)->latest()->first();
        if (!$token || !$token->token) {
            $this->log->update([
                'status' => 'FAILED',
                'retry_reason' => 'TOKEN_MISSING',
                'error_message' => 'No valid terminal token found',
                'response_metadata' => ['error' => 'Missing JWT token'],
            ]);
            Log::warning("[Retry] No valid terminal token found", ['log_id' => $this->log->id]);
            return;
        }

        try {
            $start = microtime(true);

            $response = Http::withToken($token->token)
                ->acceptJson()
                ->post($endpoint, $this->log->request_payload);

            $end = microtime(true);
            $latency = round(($end - $start) * 1000, 2); // ms
            $json = json_decode($response->body(), true);

            $this->log->update([
                'status' => $response->successful() ? 'SUCCESS' : 'FAILED',
                'http_status_code' => $response->status(),
                'retry_count' => $this->log->retry_count + 1,
                'next_retry_at' => now()->addSeconds($this->terminal->retry_interval_sec ?? 300),
                'validation_status' => isset($json['errors']) ? 'FAILED' : 'PASSED',
                'response_payload' => $json ?? $response->body(),
                'retry_reason' => 'RETRY_ATTEMPT',
                'response_time' => $latency,
                'source_ip' => gethostbyname(gethostname()),
                'response_metadata' => [
                    'headers' => $response->headers(),
                    'latency_ms' => $latency,
                ],
            ]);

            Log::info("[Retry] Retry attempt recorded", ['log_id' => $this->log->id]);
        } catch (\Throwable $e) {
            $this->log->update([
                'status' => 'FAILED',
                'retry_count' => $this->log->retry_count + 1,
                'next_retry_at' => now()->addSeconds($this->terminal->retry_interval_sec ?? 300),
                'retry_reason' => 'RETRY_EXCEPTION',
                'error_message' => $e->getMessage(),
                'response_metadata' => [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
            ]);

            Log::error("[Retry] Exception during retry", [
                'log_id' => $this->log->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
