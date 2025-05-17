<?php

namespace App\Jobs;

use App\Models\IntegrationLog;
use App\Models\PosTerminal;
use App\Models\Transactions;
use App\Services\TransactionValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $payload;
    protected $terminalId;
    protected $transactionId;
    protected $contentType;
    protected $idempotencyKey;

    /**
     * Create a new job instance.
     *
     * @param array $payload The transaction data to process
     * @param int $terminalId The ID of the terminal submitting the transaction
     * @param string $transactionId The unique transaction ID
     * @param string $contentType The content type of the original request (json or text/plain)
     * @param string|null $idempotencyKey Optional idempotency key for the transaction
     * @return void
     */
    public function __construct(
        array $payload, 
        int $terminalId, 
        string $transactionId, 
        string $contentType = 'application/json',
        ?string $idempotencyKey = null
    ) {
        $this->payload = $payload;
        $this->terminalId = $terminalId;
        $this->transactionId = $transactionId;
        $this->contentType = $contentType;
        $this->idempotencyKey = $idempotencyKey ?? $transactionId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(TransactionValidationService $validator)
    {
        $startTime = microtime(true);

        try {
            Log::info('Processing transaction job', [
                'transaction_id' => $this->transactionId,
                'terminal_id' => $this->terminalId,
                'content_type' => $this->contentType
            ]);

            // Check for existing transaction with this idempotency key to prevent duplicates
            $existingTransaction = Transactions::where('transaction_id', $this->transactionId)
                ->orWhere('idempotency_key', $this->idempotencyKey)
                ->first();
                
            if ($existingTransaction) {
                Log::info('Duplicate transaction detected', [
                    'transaction_id' => $this->transactionId,
                    'idempotency_key' => $this->idempotencyKey
                ]);
                return;
            }

            // Get terminal information
            $terminal = PosTerminal::find($this->terminalId);
            if (!$terminal) {
                Log::error('Terminal not found for transaction processing', [
                    'terminal_id' => $this->terminalId
                ]);
                return;
            }
            
            // Create log for transaction processing
            $log = new IntegrationLog();
            $log->tenant_id = $terminal->tenant_id;
            $log->terminal_id = $terminal->id;
            $log->transaction_id = $this->transactionId;
            $log->request_payload = json_encode($this->payload);
            $log->source_ip = request()->ip() ?? 'Job Queue';
            $log->log_type = 'transaction';
            $log->save();

            // Process the transaction payload based on content type
            $payloadData = $this->payload;
            
            // Text format handling for non-JSON content
            if (strtolower($this->contentType) === 'text/plain' && is_string($this->payload)) {
                // Parse the text format into structured data
                try {
                    $payloadData = $validator->parseTextFormat($this->payload);
                    $log->message = 'Text format parsed successfully';
                    $log->save();
                } catch (\Exception $e) {
                    $log->status = 'FAILED';
                    $log->error_message = 'Text format parsing failed: ' . $e->getMessage();
                    $log->severity = 'error';
                    $log->save();
                    
                    throw $e;
                }
            }

            // Validate the transaction payload
            $result = $validator->validate($payloadData);
            $payloadData['validation_status'] = $result['validation_status'];
            $payloadData['error_code'] = $result['error_code'];
            $payloadData['payload_checksum'] = $result['computed_checksum'];

            // If validation failed, log the error and return
            if ($result['validation_status'] === 'ERROR') {
                $log->status = 'FAILED';
                $log->error_message = 'Validation failed: ' . json_encode($result['errors']);
                $log->severity = 'error';
                $log->http_status_code = 422;
                $log->save();
                
                return;
            }

            // Store the transaction in database
            $transaction = Transactions::create(array_merge(
                $payloadData,
                [
                    'tenant_id' => $terminal->tenant_id,
                    'terminal_id' => $terminal->id,
                    'idempotency_key' => $this->idempotencyKey,
                ]
            ));

            // Update log with success information
            $endTime = microtime(true);
            $log->status = 'SUCCESS';
            $log->response_payload = json_encode([
                'transaction_id' => $transaction->id,
                'validation_status' => $payloadData['validation_status']
            ]);
            $log->severity = 'info';
            $log->http_status_code = 200;
            $log->response_time = round(($endTime - $startTime) * 1000);
            $log->save();

            Log::info('Transaction processed successfully', [
                'transaction_id' => $this->transactionId,
                'terminal_id' => $this->terminalId,
                'processing_time_ms' => $log->response_time
            ]);
            
        } catch (\Exception $e) {
            // Log the error
            Log::error('Error processing transaction', [
                'transaction_id' => $this->transactionId,
                'terminal_id' => $this->terminalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Update the integration log with error information
            if (isset($log)) {
                $log->status = 'FAILED';
                $log->error_message = $e->getMessage();
                $log->severity = 'error';
                $log->save();
                
                // Set up for retry if applicable
                if ($this->attempts() < 3) {
                    $this->release(60 * pow(2, $this->attempts()));
                    
                    $log->retry_count = $this->attempts();
                    $log->retry_reason = 'Job processing error';
                    $log->next_retry_at = now()->addSeconds(60 * pow(2, $this->attempts()));
                    $log->save();
                }
            }
            
            throw $e;
        }
    }
}
