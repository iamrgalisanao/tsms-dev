<?php

namespace Tests\Feature\API\V1;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionIntake;
use App\Jobs\ProcessTransactionIntakeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntakeRefactorTest extends TestCase
{
    use RefreshDatabase;

    protected PosTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

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

        $submissionUuid = (string) \Illuminate\Support\Str::uuid();
        $payload = [
            'submission_uuid' => $submissionUuid,
            'submission_timestamp' => now()->toISOString(),
            'payload_checksum' => str_repeat('a', 64),
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'transaction_count' => 1,
            'transaction' => [
                'transaction_id' => 'TX-' . uniqid(),
                'gross_sales' => 100.00,
                'customer_code' => 'CUST01',
                'transaction_timestamp' => now()->toISOString(),
                'receipt_no' => 'REC-001',
                'hardware_id' => 'HW-01',
            ],
        ];

        Sanctum::actingAs($this->terminal, ['transaction:create']);
        
        $response = $this->postJson('/api/v1/transactions/official', $payload);

        $response->assertStatus(202);
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
        $submissionUuid = (string) \Illuminate\Support\Str::uuid();
        
        // Create an existing intake record
        TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'payload_checksum' => 'existing',
            'payload' => [],
            'payload_size_bytes' => 100,
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'trace_id' => 'trace-1',
            'received_at' => now(),
        ]);

        $payload = [
            'submission_uuid' => $submissionUuid,
            'submission_timestamp' => now()->toISOString(),
            'payload_checksum' => str_repeat('d', 64),
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'transaction_count' => 1,
            'transaction' => [
                'transaction_id' => 'TX-DUP',
            ],
        ];

        Sanctum::actingAs($this->terminal, ['transaction:create']);

        $response = $this->postJson('/api/v1/transactions/official', $payload);

        $response->assertStatus(202);
        $response->assertJsonFragment(['message' => 'Submission already accepted']);
    }

    /** @test */
    public function test_processes_intake_through_the_job()
    {
        $submissionUuid = (string) \Illuminate\Support\Str::uuid();
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
}
