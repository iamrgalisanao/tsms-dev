<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Transaction;
use App\Models\TransactionAdjustment;
use App\Models\TransactionTax;
use App\Models\TransactionJob;
use App\Models\TransactionValidation;
use App\Models\ValidationStatus;
use App\Models\JobStatus;

class TransactionSchemaTestSeeder extends Seeder
{
    public function run()
    {
        // Seed lookup tables with correct codes and descriptions
        $validationStatuses = [
            ['code' => 'PENDING', 'description' => 'Validation has not yet been performed'],
            ['code' => 'VALID', 'description' => 'Transaction passed all validation rules'],
            ['code' => 'INVALID', 'description' => 'Transaction failed one or more validation rules'],
            ['code' => 'REVIEW_REQUIRED', 'description' => 'Validation inconclusive—needs human/manual review'],
        ];
        foreach ($validationStatuses as $status) {
            ValidationStatus::updateOrCreate(['code' => $status['code']], $status);
        }

        $jobStatuses = [
            ['code' => 'QUEUED', 'description' => 'Job has been created and is awaiting execution'],
            ['code' => 'RUNNING', 'description' => 'Job is currently in progress'],
            ['code' => 'RETRYING', 'description' => 'Job failed but is scheduled for another attempt'],
            ['code' => 'COMPLETED', 'description' => 'Job finished successfully'],
            ['code' => 'PERMANENTLY_FAILED', 'description' => 'Job has failed after maximum retries and will not be retried'],
        ];
        foreach ($jobStatuses as $status) {
            JobStatus::updateOrCreate(['code' => $status['code']], $status);
        }

        // Create sample transactions with related data
        Transaction::factory(10)->create()->each(function ($transaction) {
            TransactionAdjustment::factory(2)->create(['transaction_id' => $transaction->transaction_id]);
            TransactionTax::factory(2)->create(['transaction_id' => $transaction->transaction_id]);
            TransactionJob::factory(1)->create([
                'transaction_id' => $transaction->transaction_id,
                'terminal_id' => $transaction->terminal_id,
                // Optionally assign a random job status code
                'job_status_code' => 'QUEUED',
            ]);
            TransactionValidation::factory(1)->create([
                'transaction_id' => $transaction->transaction_id,
                // Optionally assign a random validation status code
                'validation_status_code' => 'PENDING',
            ]);
        });
    }
}