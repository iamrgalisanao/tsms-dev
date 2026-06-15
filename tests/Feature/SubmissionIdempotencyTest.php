<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use App\Models\Tenant;
use App\Models\PosTerminal;
use App\Models\Transaction;
use App\Models\TransactionSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\PayloadChecksumService;
use Illuminate\Support\Facades\Queue;

class SubmissionIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenantAndTerminal(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        return [$tenant, $terminal];
    }

    private function makePayload($tenantId, $terminalId, $submissionUuid, ?string $hardwareId = null): array
    {
        // minimal valid one-transaction payload matching controller expectations
        $txId = (string) Str::uuid();
        $now = Carbon::now('UTC');

        // Build transaction scalars first
        $txnScalars = [
            'transaction_id' => (string) $txId,
            'hardware_id' => $hardwareId ?? 'HW-TEST',
            'receipt_no' => 'IDEMP-'.Str::upper(Str::random(8)),
            'transaction_timestamp' => $now->copy()->subMinute()->format('Y-m-d\\TH:i:s\\Z'),
            'gross_sales' => 100.0,
            'net_sales' => 100.0,
            'promo_status' => 'NONE',
            'customer_code' => 'C-TEST',
        ];
        $txnAdjustments = [
            ['adjustment_type' => 'promo_discount', 'amount' => 0],
            ['adjustment_type' => 'senior_discount', 'amount' => 0],
            ['adjustment_type' => 'pwd_discount', 'amount' => 0],
            ['adjustment_type' => 'vip_card_discount', 'amount' => 0],
            ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0],
            ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0],
            ['adjustment_type' => 'employee_discount', 'amount' => 0],
        ];
        $txnTaxes = [
            ['tax_type' => 'VAT', 'amount' => 0],
            ['tax_type' => 'VATABLE_SALES', 'amount' => 100],
            ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 0],
            ['tax_type' => 'OTHER_TAX', 'amount' => 0],
        ];

        // Compute transaction checksum (excluding its payload_checksum)
        $service = new PayloadChecksumService();
        $txnCopyForChecksum = array_merge($txnScalars, [
            // arrays follow later; but checksum canonicalizer is order-insensitive
            'adjustments' => $txnAdjustments,
            'taxes' => $txnTaxes,
        ]);
        $txnChecksum = $service->computeChecksum($txnCopyForChecksum);

        // Construct transaction in required order: scalars → payload_checksum → arrays
        $transaction = array_merge($txnScalars, [
            'payload_checksum' => $txnChecksum,
        ], [
            'adjustments' => $txnAdjustments,
            'taxes' => $txnTaxes,
        ]);

        // Build submission for checksum (without submission payload_checksum)
        $submissionForChecksum = [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => $now->format('Y-m-d\\TH:i:s\\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];

        // Compute submission checksum
        $submissionChecksum = $service->computeChecksum($submissionForChecksum);

        // Final payload in required order: scalars → payload_checksum → transaction
        $payload = [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => $now->format('Y-m-d\\TH:i:s\\Z'),
            'transaction_count' => 1,
            'payload_checksum' => $submissionChecksum,
            'transaction' => $transaction,
        ];

        return $payload;
    }

    public function test_duplicate_submission_is_idempotent(): void
    {
        Queue::fake();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $uuid = (string) Str::uuid();
        $payload = $this->makePayload($tenant->id, $terminal->id, $uuid, $terminal->serial_number);

        // Issue a Sanctum token for the terminal with proper abilities
        $token = $terminal->generateAccessToken();
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ];

        // First request should create the submission
        $res1 = $this->postJson('/api/v1/transactions/official', $payload, $headers);
        $res1->assertStatus(200);
        $this->assertDatabaseHas('transaction_submissions', [
            'submission_uuid' => $uuid,
            'terminal_id' => $terminal->id,
        ]);

        // Second request (same UUID + terminal) should return idempotent success instead of 500
        $res2 = $this->postJson('/api/v1/transactions/official', $payload, $headers);
        $res2->assertStatus(200);
        $res2->assertJson([
            'success' => true,
            'message' => 'Submission already processed (idempotent)'
        ]);
    }

    public function test_duplicate_receipt_with_different_payload_is_rejected_as_conflict(): void
    {
        Queue::fake();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $firstPayload = $this->makePayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $secondSubmissionUuid = (string) Str::uuid();
        $secondPayload = $this->makePayload($tenant->id, $terminal->id, $secondSubmissionUuid, $terminal->serial_number);

        $secondPayload['transaction']['receipt_no'] = $firstPayload['transaction']['receipt_no'];
        $secondPayload['transaction']['transaction_timestamp'] = $firstPayload['transaction']['transaction_timestamp'];
        $secondPayload['transaction']['gross_sales'] = 250.0;
        $secondPayload['transaction']['net_sales'] = 250.0;
        $secondPayload = $this->refreshChecksums($secondPayload);

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $terminal->generateAccessToken(),
        ];

        $this->postJson('/api/v1/transactions/official', $firstPayload, $headers)
            ->assertStatus(200);

        $this->postJson('/api/v1/transactions/official', $secondPayload, $headers)
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'error_code' => 'DUPLICATE_RECEIPT_CONFLICT',
            ]);

        $this->assertSame(1, \App\Models\Transaction::where('terminal_id', $terminal->id)
            ->where('receipt_no', $firstPayload['transaction']['receipt_no'])
            ->count());
        $this->assertDatabaseMissing('transaction_submissions', [
            'submission_uuid' => $secondSubmissionUuid,
            'terminal_id' => $terminal->id,
        ]);
    }

    public function test_hardware_id_must_match_authenticated_terminal(): void
    {
        Queue::fake();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->makePayload($tenant->id, $terminal->id, (string) Str::uuid(), 'FOREIGN-HARDWARE');
        $payload = $this->refreshChecksums($payload);

        $this->postJson('/api/v1/transactions/official', $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $terminal->generateAccessToken(),
        ])
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'error_code' => 'HARDWARE_ID_MISMATCH',
            ]);

        $this->assertDatabaseMissing('transaction_submissions', [
            'submission_uuid' => $payload['submission_uuid'],
            'terminal_id' => $terminal->id,
        ]);
        $this->assertDatabaseMissing('transactions', [
            'transaction_id' => $payload['transaction']['transaction_id'],
            'terminal_id' => $terminal->id,
        ]);
    }

    public function test_tenant_id_must_match_authenticated_terminal_tenant(): void
    {
        Queue::fake();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $otherTenant = Tenant::factory()->create();
        $payload = $this->makePayload($otherTenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $payload = $this->refreshChecksums($payload);

        $this->postJson('/api/v1/transactions/official', $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $terminal->generateAccessToken(),
        ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => [
                    'tenant_id' => ['Terminal does not belong to the specified tenant'],
                ],
            ]);

        $this->assertDatabaseMissing('transaction_submissions', [
            'submission_uuid' => $payload['submission_uuid'],
            'terminal_id' => $terminal->id,
        ]);
        $this->assertDatabaseMissing('transactions', [
            'transaction_id' => $payload['transaction']['transaction_id'],
            'terminal_id' => $terminal->id,
        ]);

        $this->assertNotSame($tenant->id, $otherTenant->id);
    }

    public function test_same_submission_uuid_is_rejected_for_different_terminal(): void
    {
        Queue::fake();

        [$firstTenant, $firstTerminal] = $this->seedTenantAndTerminal();
        [$secondTenant, $secondTerminal] = $this->seedTenantAndTerminal();
        $submissionUuid = (string) Str::uuid();

        $firstPayload = $this->makePayload($firstTenant->id, $firstTerminal->id, $submissionUuid, $firstTerminal->serial_number);
        $secondPayload = $this->makePayload($secondTenant->id, $secondTerminal->id, $submissionUuid, $secondTerminal->serial_number);

        $this->postJson('/api/v1/transactions/official', $firstPayload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $firstTerminal->generateAccessToken(),
        ])->assertStatus(200);

        $this->postJson('/api/v1/transactions/official', $secondPayload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $secondTerminal->generateAccessToken(),
        ])
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Submission UUID conflict',
            ]);

        $this->assertDatabaseHas('transaction_submissions', [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $firstTenant->id,
            'terminal_id' => $firstTerminal->id,
        ]);
        $this->assertDatabaseMissing('transaction_submissions', [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $secondTenant->id,
            'terminal_id' => $secondTerminal->id,
        ]);
        $this->assertDatabaseHas('transactions', [
            'transaction_id' => $firstPayload['transaction']['transaction_id'],
            'tenant_id' => $firstTenant->id,
            'terminal_id' => $firstTerminal->id,
        ]);
        $this->assertDatabaseMissing('transactions', [
            'transaction_id' => $secondPayload['transaction']['transaction_id'],
            'tenant_id' => $secondTenant->id,
            'terminal_id' => $secondTerminal->id,
        ]);
    }

    public function test_transaction_submission_transactions_are_scoped_by_terminal(): void
    {
        [$firstTenant, $firstTerminal] = $this->seedTenantAndTerminal();
        [$secondTenant, $secondTerminal] = $this->seedTenantAndTerminal();
        $submissionUuid = (string) Str::uuid();

        $firstSubmission = TransactionSubmission::create([
            'tenant_id' => $firstTenant->id,
            'terminal_id' => $firstTerminal->id,
            'submission_uuid' => $submissionUuid,
            'submission_timestamp' => now(),
            'transaction_count' => 1,
            'payload_checksum' => hash('sha256', 'first-submission'),
            'status' => TransactionSubmission::STATUS_COMPLETED,
        ]);
        TransactionSubmission::create([
            'tenant_id' => $secondTenant->id,
            'terminal_id' => $secondTerminal->id,
            'submission_uuid' => $submissionUuid,
            'submission_timestamp' => now(),
            'transaction_count' => 1,
            'payload_checksum' => hash('sha256', 'second-submission'),
            'status' => TransactionSubmission::STATUS_COMPLETED,
        ]);

        $firstTransaction = Transaction::factory()->create([
            'tenant_id' => $firstTenant->id,
            'terminal_id' => $firstTerminal->id,
            'submission_uuid' => $submissionUuid,
        ]);
        $secondTransaction = Transaction::factory()->create([
            'tenant_id' => $secondTenant->id,
            'terminal_id' => $secondTerminal->id,
            'submission_uuid' => $submissionUuid,
        ]);

        $relatedIds = $firstSubmission->transactions()->pluck('transaction_id')->all();

        $this->assertContains($firstTransaction->transaction_id, $relatedIds);
        $this->assertNotContains($secondTransaction->transaction_id, $relatedIds);
    }

    private function refreshChecksums(array $payload): array
    {
        $service = new PayloadChecksumService();

        $transactionForChecksum = $payload['transaction'];
        unset($transactionForChecksum['payload_checksum']);
        $payload['transaction']['payload_checksum'] = $service->computeChecksum($transactionForChecksum);

        $submissionForChecksum = $payload;
        unset($submissionForChecksum['payload_checksum']);
        $payload['payload_checksum'] = $service->computeChecksum($submissionForChecksum);

        return $payload;
    }
}
