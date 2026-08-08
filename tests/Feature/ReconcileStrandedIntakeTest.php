<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransactionJob;
use App\Jobs\ProcessTransactionIntakeJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TransactionIntake;
use App\Services\IngestionQueueRouter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReconcileStrandedIntakeTest extends TestCase
{
    public function test_stranded_accepted_intake_redispatches_to_computed_intake_shard(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $intake = TransactionIntake::create([
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'payload_checksum' => str_repeat('a', 64),
            'payload' => [
                'submission_uuid' => (string) Str::uuid(),
                'transaction' => ['transaction_id' => (string) Str::uuid()],
            ],
            'payload_size_bytes' => 128,
            'source_ip' => '127.0.0.1',
            'intake_status' => TransactionIntake::INTAKE_STATUS_ACCEPTED,
            'processing_status' => TransactionIntake::PROCESSING_STATUS_PROCESSING,
            'trace_id' => (string) Str::uuid(),
            'received_at' => now()->subMinutes(3),
        ]);
        $queue = app(IngestionQueueRouter::class)->intakeQueueForTenant($tenant->id);

        $this->artisan('tsms:reconcile-intake')->assertSuccessful();

        Queue::assertPushed(
            ProcessTransactionIntakeJob::class,
            fn (ProcessTransactionIntakeJob $job) => $job->intakeId === $intake->id && $job->queue === $queue
        );

        $this->assertDatabaseHas('transaction_intake', [
            'id' => $intake->id,
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => null,
        ]);
    }

    public function test_stale_queued_intake_without_terminal_processing_state_is_redispatched(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $intake = TransactionIntake::create([
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'payload_checksum' => str_repeat('a', 64),
            'payload' => [
                'submission_uuid' => (string) Str::uuid(),
                'transaction' => ['transaction_id' => (string) Str::uuid()],
            ],
            'payload_size_bytes' => 128,
            'source_ip' => '127.0.0.1',
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => null,
            'trace_id' => (string) Str::uuid(),
            'received_at' => now()->subMinutes(20),
            'queued_at' => now()->subMinutes(20),
        ]);
        $queue = app(IngestionQueueRouter::class)->intakeQueueForTenant($tenant->id);

        $this->artisan('tsms:reconcile-intake', ['--queued-stale-minutes' => 10])->assertSuccessful();

        Queue::assertPushed(
            ProcessTransactionIntakeJob::class,
            fn (ProcessTransactionIntakeJob $job) => $job->intakeId === $intake->id && $job->queue === $queue
        );
    }

    public function test_reconcile_does_not_redispatch_partial_batch_terminal_statuses(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        foreach ([TransactionIntake::PROCESSING_STATUS_PROCESSED, TransactionIntake::PROCESSING_STATUS_DUPLICATE] as $status) {
            TransactionIntake::create([
                'submission_uuid' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'terminal_id' => $terminal->id,
                'payload_checksum' => str_repeat('a', 64),
                'payload' => [
                    'submission_uuid' => (string) Str::uuid(),
                    'transaction' => ['transaction_id' => (string) Str::uuid()],
                ],
                'payload_size_bytes' => 128,
                'source_ip' => '127.0.0.1',
                'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
                'processing_status' => $status,
                'last_error_code' => $status === TransactionIntake::PROCESSING_STATUS_PROCESSED
                    ? 'PARTIAL_BATCH_FAILURE'
                    : 'duplicate_receipt_conflict',
                'trace_id' => (string) Str::uuid(),
                'received_at' => now()->subMinutes(20),
                'queued_at' => now()->subMinutes(20),
                'processed_at' => now()->subMinutes(19),
            ]);
        }

        $this->artisan('tsms:reconcile-intake', ['--queued-stale-minutes' => 10])->assertSuccessful();

        Queue::assertNotPushed(ProcessTransactionIntakeJob::class);
    }

    public function test_repair_missing_reingests_processed_intake_without_transaction_row(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $submissionUuid = (string) Str::uuid();
        $transactionId = (string) Str::uuid();

        TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'payload_checksum' => str_repeat('a', 64),
            'payload' => [
                'submission_uuid' => $submissionUuid,
                'submission_timestamp' => '2026-06-14T08:00:00Z',
                'payload_checksum' => str_repeat('a', 64),
                'transaction' => [
                    'transaction_id' => $transactionId,
                    'hardware_id' => $terminal->serial_number,
                    'receipt_no' => 'RCPT-REPAIR-001',
                    'transaction_timestamp' => '2026-06-14T07:59:00Z',
                    'gross_sales' => 112.00,
                    'net_sales' => 112.00,
                    'customer_code' => 'TEST-CUSTOMER',
                    'promo_status' => 'NONE',
                    'payload_checksum' => str_repeat('b', 64),
                    'taxes' => [
                        ['tax_type' => 'VATABLE_SALES', 'amount' => 100.00],
                        ['tax_type' => 'VAT', 'amount' => 12.00],
                    ],
                    'adjustments' => [
                        ['adjustment_type' => 'promo_discount', 'amount' => 0.00],
                    ],
                ],
            ],
            'payload_size_bytes' => 512,
            'source_ip' => '127.0.0.1',
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => TransactionIntake::PROCESSING_STATUS_PROCESSED,
            'trace_id' => (string) Str::uuid(),
            'received_at' => now()->subMinutes(10),
            'queued_at' => now()->subMinutes(9),
            'processed_at' => now()->subMinutes(8),
        ]);

        $this->artisan('tsms:reconcile-intake', [
            '--repair-missing' => true,
            '--limit' => 10,
            '--tenant' => $tenant->id,
        ])->assertSuccessful();

        $this->assertDatabaseHas('transactions', [
            'submission_uuid' => $submissionUuid,
            'transaction_id' => $transactionId,
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'gross_sales' => 112.00,
        ]);

        $transactionPk = (int) \DB::table('transactions')
            ->where('transaction_id', $transactionId)
            ->value('id');

        $this->assertDatabaseHas('transaction_taxes', [
            'transaction_pk' => $transactionPk,
            'tax_type' => 'VAT',
            'amount' => 12.00,
        ]);

        $this->assertDatabaseHas('transaction_adjustments', [
            'transaction_pk' => $transactionPk,
            'adjustment_type' => 'promo_discount',
            'amount' => 0.00,
        ]);

        $queue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        Queue::assertPushed(ProcessTransactionJob::class, fn (ProcessTransactionJob $job) => $job->queue === $queue);
    }

}
