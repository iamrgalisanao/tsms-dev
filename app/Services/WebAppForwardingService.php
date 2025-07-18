<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\WebappTransactionForward;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class WebAppForwardingService
{
    private string $webAppEndpoint;
    private int $timeout;
    private int $batchSize;
    private string $authToken;
    private bool $verifySSL;
    private string $circuitBreakerKey = 'webapp_forwarding_circuit_breaker';

    public function __construct()
    {
        $this->webAppEndpoint = config('tsms.web_app.endpoint') ?: '';
        $this->timeout = config('tsms.web_app.timeout', 30);
        $this->batchSize = config('tsms.web_app.batch_size', 50);
        $this->authToken = config('tsms.web_app.auth_token') ?: '';
        $this->verifySSL = config('tsms.web_app.verify_ssl', true);
    }

    /**
     * Process all pending transactions for webapp forwarding
     * 
     * This is the main method called by the scheduled command
     */
    public function processUnforwardedTransactions(): array
    {
        // Check circuit breaker
        if ($this->isCircuitBreakerOpen()) {
            Log::warning('WebApp forwarding skipped - circuit breaker is open');
            return ['success' => false, 'reason' => 'circuit_breaker_open'];
        }

        // Get transactions that need forwarding (haven't been forwarded yet)
        $transactions = $this->getTransactionsForForwarding();

        if ($transactions->isEmpty()) {
            return ['success' => true, 'forwarded_count' => 0, 'reason' => 'no_transactions'];
        }

        // Create forwarding records for these transactions
        $forwardingRecords = $this->createForwardingRecords($transactions);

        // Process forwarding in batches
        $results = $this->processBatchForwarding($forwardingRecords);

        return $results;
    }

    /**
     * Get transactions that are eligible for webapp forwarding
     */
    private function getTransactionsForForwarding(): Collection
    {
        return Transaction::where('validation_status', 'VALID')
            ->whereDoesntHave('webappForward', function ($query) {
                $query->where('status', WebappTransactionForward::STATUS_COMPLETED);
            })
            ->with(['terminal', 'tenant'])
            ->orderBy('created_at', 'asc')
            ->limit($this->batchSize)
            ->get();
    }

    /**
     * Create forwarding records for transactions
     */
    private function createForwardingRecords(Collection $transactions): Collection
    {
        $batchId = 'TSMS_' . now()->format('YmdHis') . '_' . uniqid();
        $forwardingRecords = collect();

        foreach ($transactions as $transaction) {
            // Check if forwarding record already exists
            $existing = WebappTransactionForward::where('transaction_id', $transaction->id)->first();
            
            if (!$existing) {
                // Create new forwarding record
                $forward = WebappTransactionForward::create([
                    'transaction_id' => $transaction->id,
                    'batch_id' => $batchId,
                    'status' => WebappTransactionForward::STATUS_PENDING,
                    'max_attempts' => 3,
                    'request_payload' => $this->buildTransactionPayload($transaction),
                ]);
                $forward->load('transaction.terminal', 'transaction.tenant');
                $forwardingRecords->push($forward);
            } elseif ($existing->status === WebappTransactionForward::STATUS_PENDING || $existing->canRetry()) {
                // Update existing record with new batch
                $existing->update([
                    'batch_id' => $batchId,
                    'request_payload' => $this->buildTransactionPayload($existing->transaction),
                ]);
                $existing->load('transaction.terminal', 'transaction.tenant');
                $forwardingRecords->push($existing);
            }
        }

        return $forwardingRecords;
    }

    /**
     * Process batch forwarding
     */
    private function processBatchForwarding(Collection $forwardingRecords): array
    {
        if ($forwardingRecords->isEmpty()) {
            return ['success' => true, 'forwarded_count' => 0, 'reason' => 'no_records'];
        }

        $batchId = $forwardingRecords->first()->batch_id;
        
        // Mark all records as in progress
        $forwardingRecords->each(function ($forward) {
            $forward->markAsInProgress();
        });

        // Build bulk payload
        $payload = $this->buildBulkPayload($forwardingRecords, $batchId);

        try {
            // Send to webapp
            $response = $this->sendToWebApp($payload);

            if ($response->successful()) {
                // Mark all as completed
                $responseData = $response->json();
                $forwardingRecords->each(function ($forward) use ($responseData, $response) {
                    $forward->markAsCompleted($responseData, $response->status());
                });

                // Reset circuit breaker on success
                $this->resetCircuitBreaker();

                Log::info('Bulk transactions forwarded successfully', [
                    'batch_id' => $batchId,
                    'count' => $forwardingRecords->count(),
                    'transaction_ids' => $forwardingRecords->pluck('transaction.transaction_id')->toArray()
                ]);

                return [
                    'success' => true,
                    'forwarded_count' => $forwardingRecords->count(),
                    'batch_id' => $batchId,
                    'response_status' => $response->status()
                ];
            }

            // Handle HTTP errors
            $this->handleBatchFailure($forwardingRecords, 'HTTP error: ' . $response->status(), $response->status());
            $this->recordFailure();

            return [
                'success' => false,
                'error' => 'HTTP error: ' . $response->status(),
                'batch_id' => $batchId,
                'transaction_count' => $forwardingRecords->count()
            ];

        } catch (\Exception $e) {
            // Handle exceptions
            $this->handleBatchFailure($forwardingRecords, $e->getMessage());
            $this->recordFailure();

            Log::error('WebApp forwarding exception', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
                'transaction_count' => $forwardingRecords->count()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'batch_id' => $batchId,
                'transaction_count' => $forwardingRecords->count()
            ];
        }
    }

    /**
     * Handle batch failure - mark all records as failed
     */
    private function handleBatchFailure(Collection $forwardingRecords, string $errorMessage, int $statusCode = null): void
    {
        $forwardingRecords->each(function ($forward) use ($errorMessage, $statusCode) {
            $forward->markAsFailed($errorMessage, $statusCode);
        });
    }

    /**
     * Send payload to webapp
     */
    private function sendToWebApp(array $payload): \Illuminate\Http\Client\Response
    {
        $httpClient = Http::timeout($this->timeout);

        if (!$this->verifySSL) {
            $httpClient = $httpClient->withoutVerifying();
        }

        if ($this->authToken) {
            $httpClient = $httpClient->withToken($this->authToken);
        }

        return $httpClient->post($this->webAppEndpoint . '/api/transactions/bulk', $payload);
    }

    /**
     * Build payload for single transaction
     */
    private function buildTransactionPayload(Transaction $transaction): array
    {
        return [
            'tsms_id' => $transaction->id,
            'transaction_id' => $transaction->transaction_id,
            
            // Calculate amount from TSMS transaction data
            'amount' => $this->calculateTransactionAmount($transaction),
            
            'validation_status' => $transaction->validation_status,
            'checksum' => $transaction->payload_checksum,
            'submission_uuid' => $transaction->submission_uuid,
            
            // Terminal and tenant info from relationships
            'terminal_serial' => $transaction->terminal?->serial_number ?? null,
            'tenant_code' => $transaction->tenant?->customer_code ?? null,
            'tenant_name' => $transaction->tenant?->name ?? null,
            
            'transaction_timestamp' => $transaction->transaction_timestamp?->format('Y-m-d\TH:i:s.v\Z'),
            'processed_at' => $transaction->created_at?->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /**
     * Calculate transaction amount for WebApp integration
     * Uses base_amount as primary source with fallback logic
     */
    private function calculateTransactionAmount(Transaction $transaction): float
    {
        // Primary: Use base_amount (current TSMS standard)
        if (!is_null($transaction->base_amount) && $transaction->base_amount > 0) {
            return (float) $transaction->base_amount;
        }
        
        // Fallback: If base_amount is null or zero, return 0 
        // (This should rarely happen for valid transactions)
        return 0.0;
    }

    /**
     * Build bulk payload for webapp
     */
    private function buildBulkPayload(Collection $forwardingRecords, string $batchId): array
    {
        return [
            'source' => 'TSMS',
            'batch_id' => $batchId,
            'timestamp' => now()->toISOString(),
            'transaction_count' => $forwardingRecords->count(),
            'transactions' => $forwardingRecords->map(function ($forward) {
                return $forward->request_payload;
            })->toArray()
        ];
    }

    /**
     * Retry failed forwarding records
     */
    public function retryFailedForwardings(): array
    {
        $failedRecords = WebappTransactionForward::readyForRetry()
            ->with(['transaction.terminal', 'transaction.tenant'])
            ->limit($this->batchSize)
            ->get();

        if ($failedRecords->isEmpty()) {
            return ['success' => true, 'retried_count' => 0, 'reason' => 'no_failed_records'];
        }

        return $this->processBatchForwarding($failedRecords);
    }

    /**
     * Circuit breaker implementation
     */
    private function isCircuitBreakerOpen(): bool
    {
        $failures = Cache::get($this->circuitBreakerKey . '_failures', 0);
        $lastFailure = Cache::get($this->circuitBreakerKey . '_last_failure');

        // Open circuit if 5 consecutive failures
        if ($failures >= 5) {
            // Auto-reset after 10 minutes
            if ($lastFailure && now()->diffInMinutes($lastFailure) >= 10) {
                $this->resetCircuitBreaker();
                return false;
            }
            return true;
        }

        return false;
    }

    private function recordFailure(): void
    {
        $failures = Cache::get($this->circuitBreakerKey . '_failures', 0) + 1;
        Cache::put($this->circuitBreakerKey . '_failures', $failures, now()->addHour());
        Cache::put($this->circuitBreakerKey . '_last_failure', now(), now()->addHour());
    }

    private function resetCircuitBreaker(): void
    {
        Cache::forget($this->circuitBreakerKey . '_failures');
        Cache::forget($this->circuitBreakerKey . '_last_failure');
    }

    public function getCircuitBreakerStatus(): array
    {
        return [
            'is_open' => $this->isCircuitBreakerOpen(),
            'failures' => Cache::get($this->circuitBreakerKey . '_failures', 0),
            'last_failure' => Cache::get($this->circuitBreakerKey . '_last_failure'),
        ];
    }

    /**
     * Get forwarding statistics
     */
    public function getForwardingStats(): array
    {
        $totalPending = WebappTransactionForward::pending()->count();
        $totalCompleted = WebappTransactionForward::completed()->count();
        $totalFailed = WebappTransactionForward::failed()->count();
        
        $unforwardedTransactions = Transaction::where('validation_status', 'VALID')
            ->whereDoesntHave('webappForward', function ($query) {
                $query->where('status', WebappTransactionForward::STATUS_COMPLETED);
            })
            ->count();

        return [
            'unforwarded_transactions' => $unforwardedTransactions,
            'pending_forwards' => $totalPending,
            'completed_forwards' => $totalCompleted,
            'failed_forwards' => $totalFailed,
            'circuit_breaker' => $this->getCircuitBreakerStatus(),
        ];
    }
}
