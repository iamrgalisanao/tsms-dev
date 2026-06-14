<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransactionJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TransactionIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReconcileStrandedIntakeTest extends TestCase
{
    use RefreshDatabase;

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

        Queue::assertPushed(ProcessTransactionJob::class);
    }
}
