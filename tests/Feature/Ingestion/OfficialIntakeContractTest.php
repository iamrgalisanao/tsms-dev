<?php

namespace Tests\Feature\Ingestion;

use App\Jobs\ProcessTransactionIntakeJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionIntake;
use App\Services\PayloadChecksumService;
use App\Services\TransactionIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Characterization of POST /api/v1/transactions/official as it behaves before
 * 003-ingestion-hardening changes anything (tasks T003/T004). Every later
 * phase must keep this file green.
 *
 * Exception: test_batch_in_official_is_accepted_with_202_and_job_records_first_item_only_on_test_schema
 * documents behavior of the migration-built test schema (UNIQUE
 * submission_uuid index, absent on staging) rather than a production
 * contract, and may change deliberately if that schema changes.
 */
class OfficialIntakeContractTest extends TestCase
{
    use RefreshDatabase;

    private const ACCEPTED_KEYS = [
        'correlation_id',
        'intake_id',
        'message',
        'retryable',
        'status',
        'submission_uuid',
        'success',
    ];

    private const REJECTED_KEYS = [
        'correlation_id',
        'error_code',
        'errors',
        'message',
        'success',
    ];

    public function test_single_transaction_is_accepted_with_202_and_queued_intake(): void
    {
        Queue::fake();
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, $terminal->serial_number);

        $response = $this->postOfficial($terminal, $payload);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('message', 'Submission accepted')
            ->assertJsonPath('submission_uuid', $payload['submission_uuid'])
            ->assertJsonPath('retryable', false);
        $this->assertSameKeys(self::ACCEPTED_KEYS, $response->json());

        $intake = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();
        $response->assertJsonPath('intake_id', $intake->id);
        $this->assertSame(TransactionIntake::INTAKE_STATUS_QUEUED, $intake->intake_status);
        $this->assertNull($intake->processing_status);
        $this->assertSame((int) $tenant->id, (int) $intake->tenant_id);
        $this->assertSame((int) $terminal->id, (int) $intake->terminal_id);
        $this->assertSame($payload['payload_checksum'], $intake->payload_checksum);
        $this->assertSame($response->json('correlation_id'), $intake->trace_id);
        $this->assertNotNull($intake->queued_at);

        Queue::assertPushed(ProcessTransactionIntakeJob::class, fn ($job) => $job->intakeId === $intake->id);
        $this->assertSame(0, Transaction::where('tenant_id', $tenant->id)->count());
    }

    public function test_job_records_transaction_values_and_marks_intake_processed(): void
    {
        Queue::fake();
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, $terminal->serial_number);

        $this->postOfficial($terminal, $payload)->assertStatus(202);
        $intake = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();

        $this->runIntakeJob($intake);

        $intake->refresh();
        $this->assertSame(TransactionIntake::INTAKE_STATUS_QUEUED, $intake->intake_status);
        $this->assertSame(TransactionIntake::PROCESSING_STATUS_PROCESSED, $intake->processing_status);
        $this->assertNull($intake->last_error_code);
        $this->assertNotNull($intake->processed_at);

        $tx = $payload['transaction'];
        $transaction = Transaction::where('transaction_id', $tx['transaction_id'])->firstOrFail();
        $this->assertSame((int) $tenant->id, (int) $transaction->tenant_id);
        $this->assertSame((int) $terminal->id, (int) $transaction->terminal_id);
        $this->assertSame($tx['hardware_id'], $transaction->hardware_id);
        $this->assertSame($tx['receipt_no'], $transaction->receipt_no);
        $this->assertSame($tx['customer_code'], $transaction->customer_code);
        $this->assertSame('NONE', $transaction->promo_status);
        $this->assertSame($tx['payload_checksum'], $transaction->payload_checksum);
        $this->assertSame($payload['submission_uuid'], $transaction->submission_uuid);
        $this->assertSame('PENDING', $transaction->validation_status);
        $this->assertSame('100.00', number_format((float) $transaction->gross_sales, 2, '.', ''));
        $this->assertSame('89.29', number_format((float) $transaction->net_sales, 2, '.', ''));
        $this->assertSame(
            $tx['transaction_timestamp'],
            Carbon::parse($transaction->transaction_timestamp)->utc()->format('Y-m-d\TH:i:s\Z')
        );
        $this->assertSame(1, Transaction::where('tenant_id', $tenant->id)->count());

        $this->assertChildRows($transaction, $tx);
    }

    public function test_batch_in_official_is_accepted_with_202_and_job_records_first_item_only_on_test_schema(): void
    {
        // This test documents test-schema behavior; it only holds when the
        // unique submission_uuid index exists on the test DB.
        $hasUniqueSubmissionUuid = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'transactions')
            ->where('COLUMN_NAME', 'submission_uuid')
            ->where('NON_UNIQUE', 0)
            ->exists();
        if (! $hasUniqueSubmissionUuid) {
            $this->markTestSkipped('transactions has no UNIQUE index on submission_uuid in this test DB; this test documents test-schema behavior only.');
        }

        Queue::fake();
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialBatchPayload($tenant->id, $terminal->id, $terminal->serial_number, 2);

        $response = $this->postOfficial($terminal, $payload);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('message', 'Submission accepted')
            ->assertJsonPath('submission_uuid', $payload['submission_uuid'])
            ->assertJsonPath('retryable', false);
        $this->assertSameKeys(self::ACCEPTED_KEYS, $response->json());

        $intakes = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->get();
        $this->assertCount(1, $intakes);
        $this->assertSame(TransactionIntake::INTAKE_STATUS_QUEUED, $intakes[0]->intake_status);
        $this->assertCount(2, $intakes[0]->payload['transactions']);
        Queue::assertPushed(ProcessTransactionIntakeJob::class, 1);
        $this->assertSame(0, Transaction::where('tenant_id', $tenant->id)->count());

        $this->runIntakeJob($intakes[0]);

        // Characterized as-is on the migration-built schema: transactions has
        // a UNIQUE index on submission_uuid (2025_11_11_000000 migration), and
        // every item of one submission shares that uuid, so only the first
        // item is recorded; the second is reported as a failed item and the
        // intake ends PROCESSED/PARTIAL_BATCH_FAILURE. Staging lacks that
        // index (research.md A1), so this outcome is schema-dependent. Since
        // US2 the failed item is classified as unique_constraint_violation.
        [$first, $second] = $payload['transactions'];
        $intake = $intakes[0]->fresh();
        $this->assertSame(TransactionIntake::PROCESSING_STATUS_PROCESSED, $intake->processing_status);
        $this->assertSame('PARTIAL_BATCH_FAILURE', $intake->last_error_code);
        $failedItems = json_decode($intake->last_error_message, true)['failed_items'];
        $this->assertCount(1, $failedItems);
        $this->assertSame(1, $failedItems[0]['index']);
        $this->assertSame($second['transaction_id'], $failedItems[0]['transaction_id']);
        $this->assertSame('unique_constraint_violation', $failedItems[0]['message']);

        $this->assertSame(1, Transaction::where('tenant_id', $tenant->id)->count());
        $this->assertNull(Transaction::where('transaction_id', $second['transaction_id'])->first());
        $transaction = Transaction::where('transaction_id', $first['transaction_id'])->firstOrFail();
        $this->assertSame((int) $terminal->id, (int) $transaction->terminal_id);
        $this->assertSame($first['receipt_no'], $transaction->receipt_no);
        $this->assertSame($payload['submission_uuid'], $transaction->submission_uuid);
        $this->assertChildRows($transaction, $first);
    }

    public function test_structural_failure_returns_422_and_persists_rejected_intake(): void
    {
        Queue::fake();
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, $terminal->serial_number);
        unset($payload['transaction']['gross_sales']);
        $payload = $this->refreshChecksums($payload);

        $response = $this->postOfficial($terminal, $payload);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Structural validation failed')
            ->assertJsonPath('error_code', 'STRUCTURAL_VALIDATION_FAILURE')
            ->assertJsonValidationErrors(['transaction.gross_sales']);
        $this->assertSameKeys(self::REJECTED_KEYS, $response->json());

        $intake = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();
        $this->assertSame(TransactionIntake::INTAKE_STATUS_REJECTED, $intake->intake_status);
        $this->assertSame('STRUCTURAL_VALIDATION_FAILURE', $intake->last_error_code);
        $this->assertSame($response->json('correlation_id'), $intake->trace_id);
        Queue::assertNothingPushed();
        $this->assertSame(0, Transaction::where('tenant_id', $tenant->id)->count());
    }

    public function test_hardware_id_mismatch_returns_403_and_persists_rejected_intake(): void
    {
        Queue::fake();
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, 'FOREIGN-HARDWARE');

        $response = $this->postOfficial($terminal, $payload);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Forbidden - hardware ID does not match terminal')
            ->assertJsonPath('error_code', 'HARDWARE_ID_MISMATCH')
            ->assertJsonValidationErrors(['hardware_id']);
        $this->assertSameKeys(self::REJECTED_KEYS, $response->json());

        $intake = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();
        $this->assertSame(TransactionIntake::INTAKE_STATUS_REJECTED, $intake->intake_status);
        $this->assertSame('HARDWARE_ID_MISMATCH', $intake->last_error_code);
        Queue::assertNothingPushed();
        $this->assertSame(0, Transaction::where('tenant_id', $tenant->id)->count());
    }

    public function test_resubmitting_a_rejected_submission_uuid_returns_422_with_stored_outcome(): void
    {
        Queue::fake();
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, 'FOREIGN-HARDWARE');

        $this->postOfficial($terminal, $payload)->assertStatus(403);

        // Re-register the terminal's hardware so the identical payload now
        // passes structural validation and reaches the idempotency lookup;
        // the stored REJECTED outcome for this submission_uuid wins.
        $terminal->forceFill(['serial_number' => 'FOREIGN-HARDWARE'])->save();
        $this->app['auth']->forgetGuards();
        $response = $this->postOfficial($terminal, $payload);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Submission was already rejected. Correct the payload and resend with a new submission_uuid.')
            ->assertJsonPath('error_code', 'HARDWARE_ID_MISMATCH')
            ->assertJsonPath('data.submission_uuid', $payload['submission_uuid'])
            ->assertJsonPath('data.intake_status', TransactionIntake::INTAKE_STATUS_REJECTED)
            ->assertJsonPath('data.last_error_code', 'HARDWARE_ID_MISMATCH')
            ->assertJsonPath('data.payload_checksum', $payload['payload_checksum']);
        $this->assertSameKeys(array_merge(self::REJECTED_KEYS, ['data']), $response->json());
        $this->assertArrayHasKey('hardware_id', $response->json('errors'));

        $this->assertSame(1, TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->count());
        Queue::assertNothingPushed();
        $this->assertSame(0, Transaction::where('tenant_id', $tenant->id)->count());
    }

    public function test_resubmitting_an_accepted_submission_uuid_returns_202_already_accepted_without_new_work(): void
    {
        Queue::fake();
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, $terminal->serial_number);

        $first = $this->postOfficial($terminal, $payload)->assertStatus(202);
        $intake = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();

        $response = $this->postOfficial($terminal, $payload);

        $response->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('message', 'Submission already accepted')
            ->assertJsonPath('submission_uuid', $payload['submission_uuid'])
            ->assertJsonPath('intake_id', $intake->id)
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('data.intake_status', TransactionIntake::INTAKE_STATUS_QUEUED)
            ->assertJsonPath('data.processing_status', null)
            ->assertJsonPath('data.payload_checksum', $payload['payload_checksum']);
        $this->assertSameKeys(array_merge(self::ACCEPTED_KEYS, ['data']), $response->json());
        $this->assertNotSame($first->json('correlation_id'), $response->json('correlation_id'));

        $this->assertSame(1, TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->count());
        Queue::assertPushed(ProcessTransactionIntakeJob::class, 1);
        $this->assertSame(0, Transaction::where('tenant_id', $tenant->id)->count());
    }

    public function test_checksum_mismatch_returns_422_and_persists_rejected_intake(): void
    {
        Queue::fake();
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, $terminal->serial_number);
        // Change a value after the checksums were computed.
        $payload['transaction']['gross_sales'] = '101.00';

        $response = $this->postOfficial($terminal, $payload);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'CRYPTOGRAPHIC_INTEGRITY_FAILURE')
            ->assertJsonPath('message', 'Cryptographic integrity check failed. Payload may have been tampered with or canonicalization logic is incorrect.')
            ->assertJsonPath('hint', 'Ensure you are using the V2.1/V2.2 canonicalization strategy (ksort + 2-decimal strings).');
        $this->assertSameKeys(array_merge(self::REJECTED_KEYS, ['hint']), $response->json());
        $this->assertNotEmpty($response->json('errors'));

        $intake = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();
        $this->assertSame(TransactionIntake::INTAKE_STATUS_REJECTED, $intake->intake_status);
        $this->assertSame('CRYPTOGRAPHIC_INTEGRITY_FAILURE', $intake->last_error_code);
        Queue::assertNothingPushed();
        $this->assertSame(0, Transaction::where('tenant_id', $tenant->id)->count());
    }

    public function test_x_request_id_header_is_propagated_to_response_and_intake_trace_id(): void
    {
        Queue::fake();
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->officialPayload($tenant->id, $terminal->id, $terminal->serial_number);

        $response = $this->postOfficial($terminal, $payload, ['X-Request-Id' => 'contract-trace-001']);

        $response->assertStatus(202)
            ->assertJsonPath('correlation_id', 'contract-trace-001');
        $intake = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();
        $this->assertSame('contract-trace-001', $intake->trace_id);
        Queue::assertPushed(
            ProcessTransactionIntakeJob::class,
            fn ($job) => $job->intakeId === $intake->id && $job->traceId === 'contract-trace-001'
        );
    }

    private function assertChildRows(Transaction $transaction, array $tx): void
    {
        $adjustments = DB::table('transaction_adjustments')
            ->where('transaction_pk', $transaction->id)
            ->orderBy('id')
            ->get(['adjustment_type', 'amount'])
            ->map(fn ($row) => [$row->adjustment_type, number_format((float) $row->amount, 2, '.', '')])
            ->all();
        $this->assertSame(
            array_map(fn ($a) => [$a['adjustment_type'], $a['amount']], $tx['adjustments']),
            $adjustments
        );

        $taxes = DB::table('transaction_taxes')
            ->where('transaction_pk', $transaction->id)
            ->orderBy('id')
            ->get(['tax_type', 'amount'])
            ->map(fn ($row) => [$row->tax_type, number_format((float) $row->amount, 2, '.', '')])
            ->all();
        $this->assertSame(
            array_map(fn ($t) => [$t['tax_type'], $t['amount']], $tx['taxes']),
            $taxes
        );
    }

    private function postOfficial(PosTerminal $terminal, array $payload, array $headers = []): TestResponse
    {
        return $this->withHeaders(array_merge([
            'Authorization' => 'Bearer '.$terminal->generateAccessToken(),
            'Content-Type' => 'application/json',
        ], $headers))->postJson('/api/v1/transactions/official', $payload);
    }

    private function runIntakeJob(TransactionIntake $intake): void
    {
        (new ProcessTransactionIntakeJob($intake->id, $intake->trace_id))
            ->handle(app(TransactionIngestService::class));
    }

    private function assertSameKeys(array $expected, array $json): void
    {
        $actual = array_keys($json);
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    private function seedTenantAndTerminal(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $terminal];
    }

    private function transactionPayload(string $hardwareId): array
    {
        $transaction = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $hardwareId,
            'receipt_no' => 'CT-'.Str::upper(Str::random(8)),
            'transaction_timestamp' => Carbon::now('UTC')->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'gross_sales' => '100.00',
            'net_sales' => '89.29',
            'promo_status' => 'NONE',
            'customer_code' => 'C-CONTRACT',
            'adjustments' => [
                ['adjustment_type' => 'promo_discount', 'amount' => '0.00'],
                ['adjustment_type' => 'senior_discount', 'amount' => '0.00'],
                ['adjustment_type' => 'pwd_discount', 'amount' => '0.00'],
                ['adjustment_type' => 'vip_card_discount', 'amount' => '0.00'],
                ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => '0.00'],
                ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => '0.00'],
                ['adjustment_type' => 'employee_discount', 'amount' => '0.00'],
            ],
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => '10.71'],
                ['tax_type' => 'VATABLE_SALES', 'amount' => '89.29'],
                ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => '0.00'],
                ['tax_type' => 'OTHER_TAX', 'amount' => '0.00'],
            ],
        ];
        $transaction['payload_checksum'] = (new PayloadChecksumService)->computeChecksum($transaction);

        return $transaction;
    }

    private function officialPayload(int $tenantId, int $terminalId, string $hardwareId): array
    {
        $payload = [
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => 1,
            'transaction' => $this->transactionPayload($hardwareId),
        ];
        $payload['payload_checksum'] = (new PayloadChecksumService)->computeChecksum($payload);

        return $payload;
    }

    private function officialBatchPayload(int $tenantId, int $terminalId, string $hardwareId, int $count): array
    {
        $payload = [
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => $count,
            'transactions' => array_map(fn () => $this->transactionPayload($hardwareId), range(1, $count)),
        ];
        $payload['payload_checksum'] = (new PayloadChecksumService)->computeChecksum($payload);

        return $payload;
    }

    private function refreshChecksums(array $payload): array
    {
        $service = new PayloadChecksumService;
        $transaction = $payload['transaction'];
        unset($transaction['payload_checksum']);
        $payload['transaction']['payload_checksum'] = $service->computeChecksum($transaction);

        $submission = $payload;
        unset($submission['payload_checksum']);
        $payload['payload_checksum'] = $service->computeChecksum($submission);

        return $payload;
    }
}
