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
        
        // Debug log entry creation
        \Log::info("[RetryJob] Constructed with log ID: {$log->id}, Status: {$log->status}");
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
    // Refresh models from database to ensure we have the latest data
    try {
        $this->log = $this->log->fresh();
        if ($this->terminal) {
            $this->terminal = $this->terminal->fresh();
        }
        
        \Log::info("[RetryJob] Starting job execution for log ID: {$this->log->id}, Status: {$this->log->status}");
        
        // Check terminal configurations
        \Log::info("[RetryJob] Terminal info - ID: {$this->terminal->id}, retry_enabled: " . 
                   var_export($this->terminal->retry_enabled, true) . 
                   ", max_retries: {$this->terminal->max_retries}");
        
        if (!$this->terminal || !$this->terminal->retry_enabled) {
            \Log::warning("[RetryJob] Terminal not found or retry not enabled for log ID: {$this->log->id}");
            return;
        }

        if ($this->log->status !== 'FAILED') {
            \Log::info("[RetryJob] Skipping retry for non-failed log ID: {$this->log->id}, Current status: {$this->log->status}");
            return;
        }
        
        // Immediately update retry_count to show we're processing this
        $this->log->retry_count += 1;
        $this->log->retry_reason = 'RETRY_INITIATED';
        $this->log->next_retry_at = now()->addSeconds($this->terminal->retry_interval_sec ?? 300);
        $this->log->save();
        
        \Log::info("[RetryJob] Initial log update completed, retry_count: {$this->log->retry_count}");
    } catch (\Exception $e) {
        \Log::error("[RetryJob] Exception in initialization: " . $e->getMessage());
        \Log::error("[RetryJob] " . $e->getTraceAsString());
    }

    // Check if max retries exceeded
    if ($this->terminal->max_retries > 0 && $this->log->retry_count >= $this->terminal->max_retries) {
        $this->log->status = 'PERMANENTLY_FAILED';
        $this->log->retry_reason = 'MAX_RETRIES_EXCEEDED';
        $this->log->save();
        return;
    }

    $endpoint = config('app.retry_transaction_endpoint');
    if (!$endpoint) {
        throw new \RuntimeException('Missing RETRY_TRANSACTION_ENDPOINT config.');
    }

    $token = TerminalToken::where('terminal_id', $this->log->terminal_id)->latest()->first();

    // Stop if already succeeded or permanently failed
    if (in_array($this->log->status, ['SUCCESS', 'PERMANENTLY_FAILED'])) {
        return;
    }

    // Token is missing or invalid
    if (!$token || !$token->access_token) {
        $this->log->retry_count += 1;
        $this->log->next_retry_at = now()->addSeconds($this->terminal->retry_interval_sec ?? 300);
        $this->log->status = 'FAILED';
        $this->log->retry_reason = 'TOKEN_MISSING';
        $this->log->error_message = 'Missing JWT token';
        $this->log->response_metadata = ['error' => 'No valid terminal token found'];
        $this->log->save();
        \Log::warning("[Retry] No valid token for terminal {$this->log->terminal_id}, log_id: {$this->log->id}");
        return;
    }

    try {
        $startTime = microtime(true);

        $payload = json_decode($this->log->request_payload, true);

        $response = Http::timeout(10)
            ->withToken($token->access_token)
            ->acceptJson()
            ->post($endpoint, $payload);

        $endTime = microtime(true);
        $latency = round(($endTime - $startTime) * 1000, 2);

        $responseBody = $response->json() ?? $response->body();

        $this->log->status = $response->successful() ? 'SUCCESS' : 'FAILED';
        $this->log->response_payload = $responseBody;
        $this->log->http_status_code = $response->status();
        $this->log->retry_count += 1;
        $this->log->next_retry_at = now()->addSeconds($this->terminal->retry_interval_sec ?? 300);
        $this->log->response_time = $latency;
        $this->log->validation_status = isset($responseBody['errors']) ? 'FAILED' : 'PASSED';
        $this->log->retry_reason = 'RETRY_ATTEMPT';
        $this->log->source_ip = gethostbyname(gethostname());
        $this->log->response_metadata = [
            'headers' => $response->headers(),
            'latency_ms' => $latency,
        ];

        $this->log->save();
    } catch (\Throwable $e) {
        $this->log->status = 'FAILED';
        $this->log->retry_count += 1;
        $this->log->next_retry_at = now()->addSeconds($this->terminal->retry_interval_sec ?? 300);
        $this->log->retry_reason = 'RETRY_EXCEPTION';
        $this->log->http_status_code = 0;
        $this->log->error_message = $e->getMessage();
        $this->log->response_metadata = [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ];
        $this->log->save();

        \Log::info("Retry attempt #{$this->log->retry_count} for Log ID: {$this->log->id}");
        \Log::info('Exception: ' . $e->getMessage());
    }
}


}
