<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransactionJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionAdjustment;
use App\Models\TransactionSubmission;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreOfficialResilienceTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenantAndTerminal(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $terminal];
    }

    private function makePayload($tenantId, $terminalId, $submissionUuid, ?string $hardwareId = null): array
    {
        $txId = (string) Str::uuid();
        $now = Carbon::now('UTC');

        $txnScalars = [
            'transaction_id' => $txId,
            'hardware_id' => $hardwareId ?? 'HW-TEST',
            'receipt_no' => 'RESIL-' . Str::upper(Str::random(8)),
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

        $service = new PayloadChecksumService();
        $txnChecksum = $service->computeChecksum(array_merge($txnScalars, [
            'adjustments' => $txnAdjustments,
            'taxes' => $txnTaxes,
        ]));

        $transaction = array_merge($txnScalars, [
            'payload_checksum' => $txnChecksum,
        ], [
            'adjustments' => $txnAdjustments,
            'taxes' => $txnTaxes,
        ]);

        $submissionForChecksum = [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => $now->format('Y-m-d\\TH:i:s\\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];
        $submissionChecksum = $service->computeChecksum($submissionForChecksum);

        return [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => $now->format('Y-m-d\\TH:i:s\\Z'),
            'transaction_count' => 1,
            'payload_checksum' => $submissionChecksum,
            'transaction' => $transaction,
        ];
    }

    public function test_mid_persist_failure_does_not_leave_an_orphaned_transaction_row(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->makePayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $txId = $payload['transaction']['transaction_id'];

        // Simulate an unexpected persistence failure partway through the
        // per-transaction savepoint (after the Transaction row itself has
        // already been created).
        TransactionAdjustment::creating(function ($model) {
            if ($model->adjustment_type === 'senior_discount') {
                throw new \RuntimeException('Simulated mid-persist failure');
            }
        });

        Queue::fake();

        $response = $this->postJson('/api/v1/transactions/official', $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $terminal->generateAccessToken(),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.failed_count', 1);

        // The critical assertion: no orphaned `transactions` row for the failed item.
        $this->assertDatabaseMissing('transactions', ['transaction_id' => $txId]);
        $this->assertDatabaseMissing('transaction_adjustments', []);

        // No job should have been queued for a transaction that was rolled back.
        Queue::assertNotPushed(ProcessTransactionJob::class);

        $submission = TransactionSubmission::where('submission_uuid', $payload['submission_uuid'])->first();
        $this->assertNotNull($submission);
        $this->assertSame(TransactionSubmission::STATUS_PROCESSING, $submission->status);
    }

    public function test_resubmitting_an_incomplete_submission_completes_the_missing_transaction(): void
    {
        Queue::fake();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $submissionUuid = (string) Str::uuid();
        $payload = $this->makePayload($tenant->id, $terminal->id, $submissionUuid, $terminal->serial_number);
        $txId = $payload['transaction']['transaction_id'];
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $terminal->generateAccessToken(),
        ];

        TransactionAdjustment::creating(function ($model) {
            if ($model->adjustment_type === 'senior_discount') {
                throw new \RuntimeException('Simulated mid-persist failure');
            }
        });

        $first = $this->postJson('/api/v1/transactions/official', $payload, $headers);
        $first->assertStatus(200);
        $first->assertJsonPath('data.failed_count', 1);
        $this->assertDatabaseMissing('transactions', ['transaction_id' => $txId]);

        // Remove the fault and retry with the identical payload/submission_uuid.
        TransactionAdjustment::flushEventListeners();

        $second = $this->postJson('/api/v1/transactions/official', $payload, $headers);
        $second->assertStatus(200);
        $second->assertJsonPath('data.failed_count', 0);
        $second->assertJsonPath('data.processed_count', 1);

        $this->assertDatabaseHas('transactions', [
            'transaction_id' => $txId,
            'terminal_id' => $terminal->id,
            'tenant_id' => $tenant->id,
        ]);

        $submission = TransactionSubmission::where('submission_uuid', $submissionUuid)->first();
        $this->assertNotNull($submission);
        $this->assertSame(TransactionSubmission::STATUS_COMPLETED, $submission->status);

        // Only one TransactionSubmission row should exist for this submission_uuid — the
        // retry must reuse the original envelope rather than trying to insert a duplicate.
        $this->assertSame(1, TransactionSubmission::where('submission_uuid', $submissionUuid)->count());
    }

    public function test_completed_submission_is_still_a_pure_idempotent_replay(): void
    {
        Queue::fake();

        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->makePayload($tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $terminal->generateAccessToken(),
        ];

        $first = $this->postJson('/api/v1/transactions/official', $payload, $headers);
        $first->assertStatus(200);
        $first->assertJsonPath('data.failed_count', 0);

        $countBefore = Transaction::count();

        $second = $this->postJson('/api/v1/transactions/official', $payload, $headers);
        $second->assertStatus(200);
        $second->assertJson([
            'success' => true,
            'message' => 'Submission already processed (idempotent)',
        ]);

        // Idempotent replay must not create any new rows.
        $this->assertSame($countBefore, Transaction::count());
    }
}
