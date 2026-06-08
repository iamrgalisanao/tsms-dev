<?php

namespace Tests\Feature\API\V1;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionIntake;
use App\Jobs\ProcessTransactionJob;
use App\Jobs\ProcessTransactionIntakeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntakeRefactorTest extends TestCase
{
    use RefreshDatabase;

    protected PosTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tsms.intake.backpressure.enabled' => false]);

        // Use the seeded tenant (id=1) instead of creating one, to avoid duplicate key errors
        $tenant = Tenant::find(1) ?? Tenant::factory()->create(['id' => 1]);
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'is_active' => true,
            'status_id' => 1,
        ]);
    }

    /** @test */
    public function test_successfully_accepts_a_transaction_intake()
    {
        Bus::fake([ProcessTransactionIntakeJob::class]);

        $submissionUuid = (string) Str::uuid();
        $payload = [
            'submission_uuid' => $submissionUuid,
            'submission_timestamp' => now()->toISOString(),
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'transaction_count' => 1,
            'transaction' => [
                'transaction_id' => (string) Str::uuid(),
                'gross_sales' => 100.00,
                'customer_code' => 'CUST01',
                'transaction_timestamp' => now()->toISOString(),
                'receipt_no' => 'REC-001',
                'hardware_id' => 'HW-01',
            ],
        ];
        $checksumService = app(\App\Services\PayloadChecksumService::class);
        $payload['transaction']['payload_checksum'] = $checksumService->computeChecksum($payload['transaction']);
        $payload['payload_checksum'] = $checksumService->computeChecksum($payload);

        Sanctum::actingAs($this->terminal, ['transaction:create']);
        
        $response = $this->postJson('/api/v1/transactions/official', $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Submission accepted',
            'data' => [
                'submission_uuid' => $submissionUuid,
            ]
        ]);

        $this->assertDatabaseHas('transaction_intake', [
            'submission_uuid' => $submissionUuid,
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
        ]);

        Bus::assertDispatched(ProcessTransactionIntakeJob::class);
    }

    /** @test */
    public function test_rejects_malformed_intake_via_form_request()
    {
        $payload = [
            'submission_uuid' => 'not-a-uuid', // Invalid
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'submission_timestamp' => now()->toISOString(),
            'transaction_count' => 1,
            'payload_checksum' => str_repeat('c', 64),
        ];

        Sanctum::actingAs($this->terminal, ['transaction:create']);

        $response = $this->postJson('/api/v1/transactions/official', $payload);

        $response->assertStatus(422);
        
        // Assert NOT persisted per plan (Layer A structure failures are not persisted to avoid abuse)
        $this->assertDatabaseMissing('transaction_intake', [
            'submission_uuid' => 'not-a-uuid',
        ]);
    }

    /** @test */
    public function test_handles_duplicate_submission_uuid_gracefully()
    {
        $submissionUuid = (string) Str::uuid();
        $payloadChecksum = str_repeat('d', 64);
        
        // Create an existing intake record
        TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'payload_checksum' => $payloadChecksum,
            'payload' => [],
            'payload_size_bytes' => 100,
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'trace_id' => 'trace-1',
            'received_at' => now(),
        ]);

        $payload = [
            'submission_uuid' => $submissionUuid,
            'submission_timestamp' => now()->toISOString(),
            'payload_checksum' => $payloadChecksum,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'transaction_count' => 1,
            'transaction' => [
                'transaction_id' => (string) Str::uuid(),
                'receipt_no' => 'REC-DUP',
            ],
        ];

        Sanctum::actingAs($this->terminal, ['transaction:create']);

        $response = $this->postJson('/api/v1/transactions/official', $payload);

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Submission already accepted']);
    }

    /** @test */
    public function test_duplicate_rejected_submission_returns_rejection_status()
    {
        $submissionUuid = (string) Str::uuid();

        TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'payload_checksum' => str_repeat('e', 64),
            'payload' => [],
            'payload_size_bytes' => 100,
            'intake_status' => TransactionIntake::INTAKE_STATUS_REJECTED,
            'last_error_code' => 'CRYPTOGRAPHIC_INTEGRITY_FAILURE',
            'last_error_message' => json_encode(['Invalid payload_checksum']),
            'trace_id' => 'trace-rejected',
            'received_at' => now(),
        ]);

        $payload = [
            'submission_uuid' => $submissionUuid,
            'submission_timestamp' => now()->toISOString(),
            'payload_checksum' => str_repeat('e', 64),
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'transaction_count' => 1,
            'transaction' => [
                'transaction_id' => (string) Str::uuid(),
                'receipt_no' => 'REC-REJECTED-DUP',
            ],
        ];

        Sanctum::actingAs($this->terminal, ['transaction:create']);

        $response = $this->postJson('/api/v1/transactions/official', $payload);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Submission was already rejected. Correct the payload and resend with a new submission_uuid.',
            'error_code' => 'CRYPTOGRAPHIC_INTEGRITY_FAILURE',
            'errors' => ['Invalid payload_checksum'],
            'data' => [
                'submission_uuid' => $submissionUuid,
                'intake_status' => TransactionIntake::INTAKE_STATUS_REJECTED,
            ],
        ]);
    }

    /** @test */
    public function test_processes_intake_through_the_job()
    {
        $submissionUuid = (string) Str::uuid();
        $txId = 'TX-PROCESS-' . uniqid();
        
        $intake = TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'payload_checksum' => str_repeat('b', 64),
            'payload' => [
                'submission_uuid' => $submissionUuid,
                'submission_timestamp' => now()->toISOString(),
                'transaction' => [
                    'transaction_id' => $txId,
                    'gross_sales' => 200.00,
                    'customer_code' => 'CUST02',
                    'transaction_timestamp' => now()->toISOString(),
                    'receipt_no' => 'REC-002',
                    'hardware_id' => 'HW-01',
                    'payload_checksum' => str_repeat('b', 64),
                ],
            ],
            'payload_size_bytes' => 500,
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'trace_id' => 'trace-2',
            'received_at' => now(),
        ]);

        // Manually run the job
        $job = new ProcessTransactionIntakeJob($intake->id);
        app()->call([$job, 'handle']);

        $intake->refresh();
        $this->assertEquals(TransactionIntake::PROCESSING_STATUS_PROCESSED, $intake->processing_status);
        $this->assertNotNull($intake->processed_at);

        $this->assertDatabaseHas('transactions', [
            'transaction_id' => $txId,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'submission_uuid' => $submissionUuid,
        ]);
    }

    /** @test */
    public function test_reconciliation_command_dispatches_stranded_records()
    {
        Bus::fake([ProcessTransactionIntakeJob::class]);

        $submissionUuid = (string) \Illuminate\Support\Str::uuid();
        
        // Create a stranded record (ACCEPTED but not QUEUED for > 2 mins)
        $intake = TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'payload_checksum' => 'checksum',
            'payload' => [],
            'payload_size_bytes' => 100,
            'intake_status' => TransactionIntake::INTAKE_STATUS_ACCEPTED, // Still accepted
            'trace_id' => 'trace-recon',
            'received_at' => now()->subMinutes(5),
        ]);

        $this->artisan('tsms:reconcile-intake')
            ->assertExitCode(0);

        $intake->refresh();
        $this->assertEquals(TransactionIntake::INTAKE_STATUS_QUEUED, $intake->intake_status);
        $this->assertNotNull($intake->queued_at);

        Bus::assertDispatched(ProcessTransactionIntakeJob::class, function ($job) use ($intake) {
            return $job->intakeId === $intake->id;
        });
    }

    /** @test */
    public function test_reconciliation_command_dry_run_reports_processed_intake_missing_transaction()
    {
        $submissionUuid = (string) Str::uuid();
        $transactionId = (string) Str::uuid();

        TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'payload_checksum' => 'checksum',
            'payload' => $this->processedIntakePayload($transactionId, 'REC-MISSING-001'),
            'payload_size_bytes' => 500,
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => TransactionIntake::PROCESSING_STATUS_PROCESSED,
            'trace_id' => (string) Str::uuid(),
            'received_at' => now()->subMinutes(10),
            'queued_at' => now()->subMinutes(9),
            'processed_at' => now()->subMinutes(8),
        ]);

        $this->artisan('tsms:reconcile-intake', [
            '--dry-run' => true,
            '--tenant' => $this->terminal->tenant_id,
            '--terminal' => $this->terminal->id,
        ])
            ->expectsOutputToContain('Missing processed intake records found: 1')
            ->expectsOutputToContain('Dry run only')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('transactions', [
            'transaction_id' => $transactionId,
        ]);
    }

    /** @test */
    public function test_reconciliation_command_repairs_processed_intake_missing_transaction()
    {
        Bus::fake([ProcessTransactionJob::class]);

        $submissionUuid = (string) Str::uuid();
        $transactionId = (string) Str::uuid();

        TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'payload_checksum' => 'checksum',
            'payload' => $this->processedIntakePayload($transactionId, 'REC-MISSING-002'),
            'payload_size_bytes' => 500,
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => TransactionIntake::PROCESSING_STATUS_PROCESSED,
            'trace_id' => (string) Str::uuid(),
            'received_at' => now()->subMinutes(10),
            'queued_at' => now()->subMinutes(9),
            'processed_at' => now()->subMinutes(8),
        ]);

        $this->artisan('tsms:reconcile-intake', [
            '--repair-missing' => true,
            '--tenant' => $this->terminal->tenant_id,
            '--terminal' => $this->terminal->id,
        ])
            ->expectsOutputToContain('Repair complete. Repaired: 1. Skipped: 0. Failed: 0.')
            ->assertExitCode(0);

        $transaction = Transaction::where('transaction_id', $transactionId)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals($submissionUuid, $transaction->submission_uuid);
        $this->assertEquals('REC-MISSING-002', $transaction->receipt_no);

        Bus::assertDispatched(ProcessTransactionJob::class, function ($job) use ($transaction) {
            return $job->getTransactionId() === $transaction->id;
        });
    }

    private function processedIntakePayload(string $transactionId, string $receiptNo): array
    {
        return [
            'submission_timestamp' => now()->toISOString(),
            'transaction' => [
                'transaction_id' => $transactionId,
                'hardware_id' => 'HW-RECON',
                'receipt_no' => $receiptNo,
                'transaction_timestamp' => now()->toISOString(),
                'gross_sales' => '602.00',
                'net_sales' => '602.00',
                'promo_status' => 'WITH_APPROVAL',
                'customer_code' => 'C-RECON',
                'adjustments' => [
                    ['adjustment_type' => 'promo_discount', 'amount' => '0.00'],
                    ['adjustment_type' => 'senior_discount', 'amount' => '0.00'],
                    ['adjustment_type' => 'pwd_discount', 'amount' => '0.00'],
                ],
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => '64.50'],
                    ['tax_type' => 'VATABLE_SALES', 'amount' => '537.50'],
                    ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => '0.00'],
                ],
            ],
        ];
    }
}
