<?php

namespace Tests\Feature\API\V1;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubmissionStatusTest extends TestCase
{
    use RefreshDatabase;

    private PosTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::find(1) ?? Tenant::factory()->create(['id' => 1]);
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'is_active' => true,
            'status_id' => 1,
        ]);
    }

    public function test_terminal_can_lookup_its_processed_submission(): void
    {
        $submissionUuid = (string) Str::uuid();
        $transactionId = (string) Str::uuid();

        TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'payload_checksum' => str_repeat('a', 64),
            'payload' => [
                'transaction' => [
                    'transaction_id' => $transactionId,
                    'receipt_no' => '0000002410',
                    'transaction_timestamp' => '2026-05-14T01:46:56Z',
                ],
            ],
            'payload_size_bytes' => 512,
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => TransactionIntake::PROCESSING_STATUS_PROCESSED,
            'trace_id' => 'trace-test',
            'received_at' => now()->subMinutes(2),
            'queued_at' => now()->subMinutes(2),
            'processed_at' => now()->subMinute(),
        ]);

        Transaction::factory()->create([
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'submission_uuid' => $submissionUuid,
            'transaction_id' => $transactionId,
            'receipt_no' => '0000002410',
            'transaction_timestamp' => '2026-05-14T01:46:56Z',
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
        ]);

        Sanctum::actingAs($this->terminal, ['transaction:read', 'provider:testing']);

        $response = $this->getJson("/api/v1/submissions/{$submissionUuid}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.submission_uuid', $submissionUuid)
            ->assertJsonPath('data.provider_status', 'processed')
            ->assertJsonPath('data.intake_status', TransactionIntake::INTAKE_STATUS_QUEUED)
            ->assertJsonPath('data.processing_status', TransactionIntake::PROCESSING_STATUS_PROCESSED)
            ->assertJsonPath('data.last_error_code', null)
            ->assertJsonPath('data.tenant_id', $this->terminal->tenant_id)
            ->assertJsonPath('data.terminal_id', $this->terminal->id)
            ->assertJsonPath('data.transaction.transaction_id', $transactionId)
            ->assertJsonPath('data.transaction.receipt_no', '0000002410')
            ->assertJsonMissingPath('data.payload');
    }

    public function test_terminal_can_lookup_its_rejected_submission_without_raw_payload(): void
    {
        $submissionUuid = (string) Str::uuid();

        TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'payload_checksum' => str_repeat('b', 64),
            'payload' => ['transaction' => ['transaction_id' => 'TX-REJECTED']],
            'payload_size_bytes' => 256,
            'intake_status' => TransactionIntake::INTAKE_STATUS_REJECTED,
            'processing_status' => null,
            'last_error_code' => 'STRUCTURAL_VALIDATION_FAILURE',
            'last_error_message' => json_encode([
                'transaction.receipt_no' => ['The transaction.receipt no field is required.'],
            ]),
            'trace_id' => 'trace-rejected',
            'received_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($this->terminal, ['transaction:read', 'provider:testing']);

        $response = $this->getJson("/api/v1/submissions/{$submissionUuid}");

        $response->assertOk()
            ->assertJsonPath('data.provider_status', 'rejected')
            ->assertJsonPath('data.last_error_code', 'STRUCTURAL_VALIDATION_FAILURE')
            ->assertJsonMissingPath('data.payload');

        $this->assertSame(
            'The transaction.receipt no field is required.',
            $response->json('data.last_error_message')['transaction.receipt_no'][0] ?? null
        );
    }

    public function test_terminal_cannot_lookup_another_terminal_submission(): void
    {
        $otherTerminal = PosTerminal::factory()->create([
            'tenant_id' => $this->terminal->tenant_id,
            'status_id' => 1,
        ]);
        $submissionUuid = (string) Str::uuid();

        TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $otherTerminal->tenant_id,
            'terminal_id' => $otherTerminal->id,
            'payload_checksum' => str_repeat('c', 64),
            'payload' => [],
            'payload_size_bytes' => 64,
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => TransactionIntake::PROCESSING_STATUS_PROCESSED,
            'trace_id' => 'trace-other',
            'received_at' => now(),
        ]);

        Sanctum::actingAs($this->terminal, ['transaction:read', 'provider:testing']);

        $this->getJson("/api/v1/submissions/{$submissionUuid}")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'SUBMISSION_NOT_FOUND');
    }

    public function test_invalid_submission_uuid_is_rejected(): void
    {
        Sanctum::actingAs($this->terminal, ['transaction:read', 'provider:testing']);

        $this->getJson('/api/v1/submissions/not-a-uuid')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_SUBMISSION_UUID');
    }

    public function test_lookup_requires_provider_testing_ability(): void
    {
        $submissionUuid = (string) Str::uuid();

        TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'payload_checksum' => str_repeat('d', 64),
            'payload' => [],
            'payload_size_bytes' => 64,
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => TransactionIntake::PROCESSING_STATUS_PROCESSED,
            'trace_id' => 'trace-missing-ability',
            'received_at' => now(),
        ]);

        Sanctum::actingAs($this->terminal, ['transaction:read']);

        $this->getJson("/api/v1/submissions/{$submissionUuid}")
            ->assertForbidden();
    }
}
