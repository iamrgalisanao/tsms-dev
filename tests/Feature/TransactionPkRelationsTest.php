<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Transaction;
use App\Models\TransactionAdjustment;
use App\Models\TransactionTax;
use App\Models\TransactionJob;
use App\Models\TransactionValidation;

class TransactionPkRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_child_records_link_via_transaction_pk_relations()
    {
        // Seed required lookup codes for foreign key constraints
        \DB::table('job_statuses')->insertOrIgnore([
            ['code' => 'QUEUED', 'description' => 'Queued'],
            ['code' => 'PROCESSING', 'description' => 'Processing'],
            ['code' => 'COMPLETED', 'description' => 'Completed'],
            ['code' => 'FAILED', 'description' => 'Failed'],
        ]);
        \DB::table('validation_statuses')->insertOrIgnore([
            ['code' => 'PENDING', 'description' => 'Pending validation'],
            ['code' => 'VALID', 'description' => 'Validation passed'],
            ['code' => 'INVALID', 'description' => 'Validation failed'],
        ]);
        $transaction = Transaction::factory()->create([
            'validation_status' => 'PENDING',
            'job_status' => 'QUEUED',
        ]);

        $adj = TransactionAdjustment::create([
            'transaction_pk' => $transaction->id,
            'adjustment_type' => 'DISCOUNT',
            'amount' => 5.00,
        ]);
        $tax = TransactionTax::create([
            'transaction_pk' => $transaction->id,
            'tax_type' => 'VAT',
            'amount' => 1.20,
        ]);
        $job = TransactionJob::create([
            'transaction_pk' => $transaction->id,
            'job_status' => 'QUEUED',
            'attempts' => 0,
            'retry_count' => 0,
        ]);
        $validation = TransactionValidation::create([
            'transaction_pk' => $transaction->id,
            'status_code' => 'PENDING',
        ]);

        $this->assertEquals($transaction->id, $adj->transaction->id);
        $this->assertEquals($transaction->id, $tax->transaction->id);
        $this->assertEquals($transaction->id, $job->transaction->id);
        $this->assertEquals($transaction->id, $validation->transaction->id);

        $this->assertCount(1, $transaction->adjustments);
        $this->assertCount(1, $transaction->taxes);
        $this->assertCount(1, $transaction->jobs);
        $this->assertCount(1, $transaction->validations);
    }
}
