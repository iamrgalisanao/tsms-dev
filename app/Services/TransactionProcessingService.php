<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Jobs\ProcessTransactionJob;
use Illuminate\Support\Facades\Log;

class TransactionProcessingService
{
    protected $validationService;

    public function __construct(TransactionValidationService $validationService)
    {
        $this->validationService = $validationService;
    }

    public function processTransaction(array $data)
    {
        try {
            // Log incoming data for debugging
            Log::info('Processing transaction request', ['data' => $data]);

            // Validate transaction
            $validationResult = $this->validationService->validate($data);
            if (!$validationResult['valid']) {
                return $this->errorResponse($validationResult['errors']);
            }

            // Find terminal and validate tenant
            $terminal = PosTerminal::where('terminal_uid', $data['terminal_id'])
                ->where('tenant_id', $data['tenant_id'])
                ->firstOrFail();

            // Create transaction record
            $transaction = Transaction::create([
                'tenant_id' => $terminal->tenant_id, // Use terminal's tenant_id
                'terminal_id' => $terminal->id,
                'hardware_id' => $data['hardware_id'],
                'transaction_id' => $data['transaction_id'],
                'transaction_timestamp' => $data['transaction_timestamp'],
                'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
                'gross_sales' => $data['gross_sales'],
                'net_sales' => $data['net_sales'],
                'vatable_sales' => $data['vatable_sales'],
                'vat_exempt_sales' => $data['vat_exempt_sales'],
                'vat_amount' => $data['vat_amount'],
                'transaction_count' => $data['transaction_count'],
                'payload_checksum' => $data['payload_checksum'],
                'machine_number' => $data['machine_number'],
                'validation_status' => 'PENDING',
                'status' => 'PENDING'
            ]);

            // Dispatch job after successful creation
            ProcessTransactionJob::dispatch($transaction)->onQueue('transactions');

            return [
                'success' => true,
                'data' => [
                    'validation_status' => 'PENDING'
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Transaction processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data
            ]);

            return [
                'success' => false,
                'message' => 'Transaction processing failed',
                'errors' => ['System error occurred']
            ];
        }
    }

    protected function successResponse($transaction)
    {
        return [
            'success' => true,
            'transaction_id' => $transaction->id,
            'status' => 'pending'
        ];
    }

    protected function errorResponse($errors)
    {
        return [
            'success' => false,
            'errors' => $errors
        ];
    }
}