<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ProcessTransactionJob;
use App\Models\Transaction;
use App\Services\IngestionQueueRouter;

class TestTransactionPipeline extends Command
{
    protected $signature = 'test:transaction-pipeline';
    protected $description = 'Test the transaction processing pipeline with sample data';

    public function handle(IngestionQueueRouter $queueRouter)
    {
        $this->info('Setting up test data...');
        
        // Run the seeder
        $this->call('db:seed', [
            '--class' => 'Database\\Seeders\\TestTransactionPipelineSeeder',
        ]);
        
        $this->info('Test data created successfully.');
        
        // Process test transactions
        $this->info('Processing transactions...');
        
        $transactions = Transaction::where('transaction_id', 'like', 'TEST-%')
                                  ->where('job_status', 'QUEUED')
                                  ->get();
        
        $this->info("Found {$transactions->count()} test transactions to process.");
        
        foreach ($transactions as $transaction) {
            $this->info("Dispatching job for transaction: {$transaction->transaction_id}");
            ProcessTransactionJob::dispatch($transaction->id)
                ->onQueue($queueRouter->processingQueueForTenant($transaction->tenant_id ?? 0));
        }
        
        $this->info('Jobs dispatched. Please check the dashboard for results in a few moments.');
        $this->info('Dashboard URL: http://localhost/dashboard/retry-history');
    }
}
