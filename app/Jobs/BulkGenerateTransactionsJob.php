<?php

namespace App\Jobs;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class BulkGenerateTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $count;
    protected $params;
    protected $successCount = 0;
    protected $failedCount = 0;
    protected $maxAttempts = 3;

    public function __construct(int $count, array $params)
    {
        $this->count = $count;
        $this->params = $params;
    $this->onQueue('low'); // housekeeping / non-critical
    }

    public function handle()
    {
        Log::info('Starting bulk transaction generation', [
            'count' => $this->count,
            'params' => $this->params
        ]);

        try {
            for ($i = 0; $i < $this->count; $i++) {
                try {
                    $transaction = Transaction::create(array_merge($this->params, [
                        'transaction_id' => 'TEST-' . date('Ymd-His') . '-' . rand(1000, 9999),
                        'job_status' => 'QUEUED',
                        'validation_status' => 'PENDING',
                        'retry_count' => 0
                    ]));

                    ProcessTransactionJob::dispatch($transaction->id)->afterCommit();
                    $this->successCount++;

                } catch (\Throwable $e) {
                    $this->failedCount++;
                    Log::error('Failed to generate transaction', [
                        'error' => $e->getMessage(),
                        'index' => $i
                    ]);
                }
            }

            Log::info('Bulk generation completed', [
                'successful' => $this->successCount,
                'failed' => $this->failedCount
            ]);

        } catch (\Throwable $e) {
            Log::error('Bulk generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('Bulk generation job failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }

    public function tags(): array
    {
        return ['domain:bulk-generation'];
    }
}