<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionIntake;
use App\Services\PayloadChecksumService;
use App\Services\ProviderTimestampNormalizer;
use App\Services\TransactionIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class OfficialIdempotentReplayTest extends TestCase
{
    use RefreshDatabase;

    private function buildOfficialPayloadWithTx(
        string $txId,
        int $tenantId,
        int $terminalId,
        string $submissionUuid,
        string $hardwareId
    ): array {
        $service = new PayloadChecksumService;
        $now = Carbon::now('UTC');
        $txnScalars = [
            'transaction_id' => $txId,
            'hardware_id' => $hardwareId,
            'receipt_no' => 'REPLAY-'.Str::upper(Str::random(8)),
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

        $txnForChecksum = array_merge($txnScalars, [
            'adjustments' => $txnAdjustments,
            'taxes' => $txnTaxes,
        ]);
        $txnChecksum = $service->computeChecksum($txnForChecksum);
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

    public function test_same_transaction_id_across_distinct_async_submissions_is_accepted_for_processing(): void
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $token = $terminal->generateAccessToken();
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ];

        $txId = (string) Str::uuid();
        $this->mockRedisForAdmissionMiddleware();

        // First submission
        $payload1 = $this->buildOfficialPayloadWithTx($txId, $tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $payload1, $headers)
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('code', 'ACCEPTED');

        // The async intake layer accepts the second durable submission; duplicate
        // transaction detection happens during downstream processing.
        $payload2 = $this->buildOfficialPayloadWithTx($txId, $tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $this->postJson('/api/v1/transactions/official', $payload2, $headers)
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('code', 'ACCEPTED')
            ->assertJsonPath('data.transactions.0.status', 'PENDING');
    }

    public function test_replay_of_processed_intake_with_pending_row_reports_pending(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->buildOfficialPayloadWithTx((string) Str::uuid(), $tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $txId = $payload['transaction']['transaction_id'];

        $this->seedProcessedIntake($payload, $terminal, TransactionIntake::PROCESSING_STATUS_PROCESSED);
        $this->seedTransactionRow($terminal, $txId, Transaction::VALIDATION_STATUS_PENDING);
        $this->mockRedisForAdmissionMiddleware();

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('code', 'ACCEPTED')
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('data.processing_status', 'COMPLETED')
            ->assertJsonPath('data.processed_count', 0)
            ->assertJsonPath('data.pending_count', 1)
            ->assertJsonPath('data.failed_count', 0)
            ->assertJsonPath('data.transactions.0.transaction_id', $txId)
            ->assertJsonPath('data.transactions.0.status', 'PENDING')
            ->assertJsonPath('data.transactions.0.validation_status', 'PENDING')
            ->assertJsonPath('data.transactions.0.job_status', 'PENDING');
    }

    public function test_replay_of_processed_intake_with_valid_row_reports_completed(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->buildOfficialPayloadWithTx((string) Str::uuid(), $tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        $this->seedProcessedIntake($payload, $terminal, TransactionIntake::PROCESSING_STATUS_PROCESSED);
        $this->seedTransactionRow($terminal, $payload['transaction']['transaction_id'], Transaction::VALIDATION_STATUS_VALID);
        $this->mockRedisForAdmissionMiddleware();

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'COMPLETED')
            ->assertJsonPath('code', 'COMPLETED')
            ->assertJsonPath('data.processed_count', 1)
            ->assertJsonPath('data.pending_count', 0)
            ->assertJsonPath('data.failed_count', 0)
            ->assertJsonPath('data.transactions.0.status', 'COMPLETED')
            ->assertJsonPath('data.transactions.0.validation_status', 'VALID')
            ->assertJsonPath('data.transactions.0.job_status', 'COMPLETED');
    }

    public function test_replay_of_partial_batch_failure_reports_each_item_and_returns_422(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $goodTxId = (string) Str::uuid();
        $badTxId = (string) Str::uuid();
        $payload = $this->buildOfficialBatchPayload([$goodTxId, $badTxId], $tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        $this->seedProcessedIntake(
            $payload,
            $terminal,
            TransactionIntake::PROCESSING_STATUS_PROCESSED,
            'PARTIAL_BATCH_FAILURE',
            json_encode(['failed_items' => [[
                'index' => 1,
                'transaction_id' => $badTxId,
                'message' => 'VAT_MISMATCH',
                'details' => null,
            ]]], JSON_THROW_ON_ERROR)
        );
        $this->seedTransactionRow($terminal, $goodTxId, Transaction::VALIDATION_STATUS_VALID);
        $this->mockRedisForAdmissionMiddleware();

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'FAILED')
            ->assertJsonPath('code', 'PARTIAL_FAILURE')
            ->assertJsonPath('error_code', 'PARTIAL_BATCH_FAILURE')
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('data.processed_count', 1)
            ->assertJsonPath('data.pending_count', 0)
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.transactions.0.transaction_id', $goodTxId)
            ->assertJsonPath('data.transactions.0.status', 'COMPLETED')
            ->assertJsonPath('data.transactions.0.validation_status', 'VALID')
            ->assertJsonPath('data.transactions.1.transaction_id', $badTxId)
            ->assertJsonPath('data.transactions.1.status', 'FAILED')
            ->assertJsonPath('data.transactions.1.validation_status', 'INVALID')
            ->assertJsonPath('data.transactions.1.job_status', 'FAILED')
            ->assertJsonPath('data.transactions.1.message', 'VAT_MISMATCH');
    }

    public function test_replay_of_processed_intake_with_failed_row_returns_422(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->buildOfficialPayloadWithTx((string) Str::uuid(), $tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        $this->seedProcessedIntake($payload, $terminal, TransactionIntake::PROCESSING_STATUS_PROCESSED);
        // 'ERROR' is the failure value the transactions.validation_status enum accepts.
        $this->seedTransactionRow($terminal, $payload['transaction']['transaction_id'], 'ERROR');
        $this->mockRedisForAdmissionMiddleware();

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'FAILED')
            ->assertJsonPath('code', 'PARTIAL_FAILURE')
            ->assertJsonPath('error_code', 'TRANSACTION_VALIDATION_FAILED')
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.transactions.0.status', 'FAILED')
            ->assertJsonPath('data.transactions.0.validation_status', 'INVALID')
            ->assertJsonPath('data.transactions.0.job_status', 'FAILED');
    }

    public function test_replay_of_processed_intake_with_empty_validation_status_row_returns_422(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->buildOfficialPayloadWithTx((string) Str::uuid(), $tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $txId = $payload['transaction']['transaction_id'];

        $this->seedProcessedIntake($payload, $terminal, TransactionIntake::PROCESSING_STATUS_PROCESSED);
        $this->seedTransactionRow($terminal, $txId, Transaction::VALIDATION_STATUS_PENDING);
        // Reproduce what non-strict MySQL stores when ProcessTransactionJob writes
        // 'FAILED' into the PENDING/VALID/ERROR/INVALID enum.
        $originalSqlMode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        DB::statement("SET SESSION sql_mode = ''");
        try {
            DB::table('transactions')
                ->where('terminal_id', $terminal->id)
                ->where('transaction_id', $txId)
                ->update(['validation_status' => 'FAILED']);
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$originalSqlMode]);
        }
        $this->assertSame('', DB::table('transactions')->where('terminal_id', $terminal->id)->where('transaction_id', $txId)->value('validation_status'));
        $this->mockRedisForAdmissionMiddleware();

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'FAILED')
            ->assertJsonPath('code', 'PARTIAL_FAILURE')
            ->assertJsonPath('error_code', 'TRANSACTION_VALIDATION_FAILED')
            ->assertJsonPath('data.pending_count', 0)
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.transactions.0.status', 'FAILED')
            ->assertJsonPath('data.transactions.0.validation_status', 'INVALID')
            ->assertJsonPath('data.transactions.0.job_status', 'FAILED');
    }

    public function test_replay_of_processed_intake_without_row_reports_not_recorded(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->buildOfficialPayloadWithTx((string) Str::uuid(), $tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        $this->seedProcessedIntake($payload, $terminal, TransactionIntake::PROCESSING_STATUS_PROCESSED);
        $this->mockRedisForAdmissionMiddleware();

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(422)
            ->assertJsonPath('status', 'FAILED')
            ->assertJsonPath('error_code', 'TRANSACTION_NOT_RECORDED')
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.transactions.0.status', 'FAILED')
            ->assertJsonPath('data.transactions.0.validation_status', 'INVALID')
            ->assertJsonPath('data.transactions.0.error_code', 'TRANSACTION_NOT_RECORDED');
    }

    public function test_replay_ignores_same_transaction_id_recorded_under_another_tenant(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        [, $otherTerminal] = $this->seedTenantAndTerminal();
        $payload = $this->buildOfficialPayloadWithTx((string) Str::uuid(), $tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);
        $txId = $payload['transaction']['transaction_id'];

        $this->seedProcessedIntake($payload, $terminal, TransactionIntake::PROCESSING_STATUS_PROCESSED);
        $this->seedTransactionRow($otherTerminal, $txId, Transaction::VALIDATION_STATUS_VALID);
        $this->mockRedisForAdmissionMiddleware();

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'TRANSACTION_NOT_RECORDED')
            ->assertJsonPath('data.processed_count', 0)
            ->assertJsonPath('data.transactions.0.status', 'FAILED');

        $this->seedTransactionRow($terminal, $txId, Transaction::VALIDATION_STATUS_PENDING);

        $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(200)
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('data.transactions.0.status', 'PENDING');
    }

    public function test_replay_reports_receipt_duplicate_item_in_mixed_batch_as_duplicate_conflict(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $goodTxId = (string) Str::uuid();
        $dupTxId = (string) Str::uuid();
        $payload = $this->buildOfficialBatchPayload([$goodTxId, $dupTxId], $tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        // The intake job skips a receipt duplicate without adding it to
        // failed_items, so the intake ends PROCESSED with no error code.
        $this->seedProcessedIntake($payload, $terminal, TransactionIntake::PROCESSING_STATUS_PROCESSED);
        $this->seedTransactionRow($terminal, $goodTxId, Transaction::VALIDATION_STATUS_VALID);
        $earlier = $this->seedTransactionRow($terminal, (string) Str::uuid(), Transaction::VALIDATION_STATUS_VALID);
        // Stored receipts are trimmed on write (normalizePayload / model
        // mutator), so the lookup compares the raw column.
        DB::table('transactions')->where('id', $earlier->id)->update([
            'receipt_no' => $payload['transactions'][1]['receipt_no'],
            'transaction_timestamp' => Carbon::parse($payload['transactions'][1]['transaction_timestamp'])->subHours(2),
        ]);
        $this->mockRedisForAdmissionMiddleware();

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(422)
            ->assertJsonPath('status', 'FAILED')
            ->assertJsonPath('code', 'PARTIAL_FAILURE')
            ->assertJsonPath('error_code', 'duplicate_receipt_conflict')
            ->assertJsonPath('data.processed_count', 1)
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.transactions.0.status', 'COMPLETED')
            ->assertJsonPath('data.transactions.1.transaction_id', $dupTxId)
            ->assertJsonPath('data.transactions.1.status', 'FAILED')
            ->assertJsonPath('data.transactions.1.error_code', 'duplicate_receipt_conflict');

        $message = (string) $response->json('message');
        $this->assertStringContainsString('Do not resend', $message);
        $this->assertStringNotContainsString('send them with a new submission_uuid', $message);
    }

    public function test_replay_of_duplicate_intake_returns_422_and_says_do_not_resend(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $payload = $this->buildOfficialPayloadWithTx((string) Str::uuid(), $tenant->id, $terminal->id, (string) Str::uuid(), $terminal->serial_number);

        $this->seedProcessedIntake(
            $payload,
            $terminal,
            TransactionIntake::PROCESSING_STATUS_DUPLICATE,
            'duplicate_receipt_conflict',
            'Duplicate detected'
        );
        $this->mockRedisForAdmissionMiddleware();

        $response = $this->postJson('/api/v1/transactions/official', $payload, $this->headersFor($terminal))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'FAILED')
            ->assertJsonPath('code', 'FAILED')
            ->assertJsonPath('error_code', 'duplicate_receipt_conflict')
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('data.processed_count', 0)
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.transactions.0.status', 'FAILED')
            ->assertJsonPath('data.transactions.0.validation_status', 'INVALID')
            ->assertJsonPath('data.transactions.0.job_status', 'FAILED');

        $message = (string) $response->json('message');
        $this->assertStringContainsString('already used by a recorded transaction', $message);
        $this->assertStringContainsString('do not resend', $message);
        $this->assertStringNotContainsString('resend with a new submission_uuid', $message);
    }

    public function test_replay_receipt_conflict_window_boundaries(): void
    {
        [, $terminal] = $this->seedTenantAndTerminal();
        $intake = $this->replayIntake($terminal);
        $base = Carbon::now('UTC')->subHours(3)->startOfSecond();
        $receipts = [];

        foreach (['plus' => 86400, 'minus' => -86400, 'ms' => 86400.5, 'out' => 86401, 'outminus' => -86401] as $label => $offset) {
            $receipt = 'RW-'.strtoupper($label).'-'.Str::upper(Str::random(6));
            $receipts[$label] = $receipt;
            $stored = date('Y-m-d H:i:s', $this->normalizedTs($terminal, $base) + (int) floor($offset));
            if (fmod($offset, 1.0) !== 0.0) {
                $stored .= '.500';
            }
            $this->seedReceiptRow($terminal, $receipt, $stored);
        }

        $transactions = [];
        foreach ($receipts as $label => $receipt) {
            $transactions[] = [
                'transaction_id' => 'TX-'.$label,
                'receipt_no' => $receipt,
                'transaction_timestamp' => $base->format('Y-m-d\\TH:i:s\\Z'),
            ];
        }

        $conflicts = $this->replayConflicts($intake, $transactions);

        $this->assertSame(['TX-minus', 'TX-ms', 'TX-plus'], $this->sortedKeys($conflicts));
    }

    public function test_replay_receipt_conflict_matches_padded_incoming_receipt_and_respects_scope(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        [$otherTenant] = $this->seedTenantAndTerminal();
        $intake = $this->replayIntake($terminal);
        $ts = Carbon::now('UTC')->subHours(3)->startOfSecond();
        $receipt = 'R-100-'.Str::upper(Str::random(6));
        $foreignOnly = 'R-200-'.Str::upper(Str::random(6));

        $incomingTs = $this->normalizedTs($terminal, $ts);
        $this->seedReceiptRow($terminal, $receipt, date('Y-m-d H:i:s', $incomingTs - 3600));
        // Same terminal_id + receipt under another tenant_id must be ignored.
        $foreign = $this->seedReceiptRow($terminal, $foreignOnly, date('Y-m-d H:i:s', $incomingTs));
        DB::table('transactions')->where('id', $foreign->id)->update(['tenant_id' => $otherTenant->id]);

        $conflicts = $this->replayConflicts($intake, [
            ['transaction_id' => 'TX-PAD', 'receipt_no' => '  '.$receipt.' ', 'transaction_timestamp' => $ts->format('Y-m-d\\TH:i:s\\Z')],
            ['transaction_id' => 'TX-FOREIGN', 'receipt_no' => $foreignOnly, 'transaction_timestamp' => $ts->format('Y-m-d\\TH:i:s\\Z')],
            ['transaction_id' => 'TX-BADTS', 'receipt_no' => $receipt, 'transaction_timestamp' => 'not-a-date'],
        ]);

        $this->assertSame(['TX-PAD'], $this->sortedKeys($conflicts));
        $this->assertSame($tenant->id, $intake->tenant_id);
    }

    public function test_replay_receipt_conflict_returns_none_when_no_timestamp_parses(): void
    {
        [, $terminal] = $this->seedTenantAndTerminal();
        $intake = $this->replayIntake($terminal);
        $receipt = 'RN-'.Str::upper(Str::random(6));
        $this->seedReceiptRow($terminal, $receipt, Carbon::now('UTC')->format('Y-m-d H:i:s'));

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'receipt_no')) {
                $queries[] = $query->sql;
            }
        });

        $conflicts = $this->replayConflicts($intake, [
            ['transaction_id' => 'TX-1', 'receipt_no' => $receipt, 'transaction_timestamp' => 'garbage'],
            ['transaction_id' => 'TX-2', 'receipt_no' => $receipt],
        ]);

        $this->assertSame([], $conflicts);
        $this->assertSame([], $queries, 'no receipt lookup should run when no timestamp parses');
    }

    public function test_replay_receipt_conflict_query_is_sargable_with_no_trim(): void
    {
        [, $terminal] = $this->seedTenantAndTerminal();
        $intake = $this->replayIntake($terminal);
        $early = Carbon::now('UTC')->subHours(10)->startOfSecond();
        $late = Carbon::now('UTC')->subHours(2)->startOfSecond();
        $receipt = 'RS-'.Str::upper(Str::random(6));
        $this->seedReceiptRow($terminal, $receipt, date('Y-m-d H:i:s', $this->normalizedTs($terminal, $late)));

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'receipt_no')) {
                $queries[] = $query;
            }
        });

        $conflicts = $this->replayConflicts($intake, [
            ['transaction_id' => 'TX-E', 'receipt_no' => ' '.$receipt, 'transaction_timestamp' => $early->format('Y-m-d\\TH:i:s\\Z')],
            ['transaction_id' => 'TX-L', 'receipt_no' => 'OTHER-'.$receipt, 'transaction_timestamp' => $late->format('Y-m-d\\TH:i:s\\Z')],
        ]);

        $this->assertSame(['TX-E'], $this->sortedKeys($conflicts));
        $this->assertCount(1, $queries);
        $sql = $queries[0]->sql;
        $this->assertStringNotContainsStringIgnoringCase('TRIM(', $sql);
        $this->assertStringContainsString('`tenant_id` = ?', $sql);
        $this->assertStringContainsString('`terminal_id` = ?', $sql);
        $this->assertStringContainsString('`receipt_no` in (?, ?)', $sql);
        $this->assertStringContainsString('`transaction_timestamp` >= ?', $sql);
        $this->assertStringContainsString('`transaction_timestamp` < ?', $sql);
        $this->assertContains($receipt, $queries[0]->bindings);
        $this->assertContains(date('Y-m-d H:i:s', $this->normalizedTs($terminal, $early) - 86400), $queries[0]->bindings);
        $this->assertContains(date('Y-m-d H:i:s', $this->normalizedTs($terminal, $late) + 86401), $queries[0]->bindings);
    }

    /**
     * Unix time of the stored representation of an incoming item: the
     * normalizer yields a UTC wall-clock string, and both the service and the
     * seeded rows interpret it in the PHP default timezone, so derive seeded
     * and expected values from it rather than from the Carbon instant.
     */
    private function normalizedTs(PosTerminal $terminal, Carbon $incoming): int
    {
        return strtotime(app(ProviderTimestampNormalizer::class)->normalize($incoming->format('Y-m-d\\TH:i:s\\Z'), $terminal->id));
    }

    private function replayIntake(PosTerminal $terminal): TransactionIntake
    {
        return new TransactionIntake([
            'tenant_id' => $terminal->tenant_id,
            'terminal_id' => $terminal->id,
        ]);
    }

    private function replayConflicts(TransactionIntake $intake, array $transactions): array
    {
        $method = new \ReflectionMethod(TransactionIntakeService::class, 'replayReceiptConflicts');

        return $method->invoke(app(TransactionIntakeService::class), $intake, $transactions);
    }

    private function sortedKeys(array $conflicts): array
    {
        $keys = array_map('strval', array_keys($conflicts));
        sort($keys);

        return $keys;
    }

    private function seedReceiptRow(PosTerminal $terminal, string $receiptNo, string $timestamp): Transaction
    {
        $row = $this->seedTransactionRow($terminal, (string) Str::uuid(), Transaction::VALIDATION_STATUS_VALID);
        DB::table('transactions')->where('id', $row->id)->update([
            'receipt_no' => $receiptNo,
            'transaction_timestamp' => $timestamp,
        ]);

        return $row;
    }

    private function seedTenantAndTerminal(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $terminal];
    }

    private function headersFor(PosTerminal $terminal): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$terminal->generateAccessToken(),
        ];
    }

    private function seedProcessedIntake(
        array $payload,
        PosTerminal $terminal,
        string $processingStatus,
        ?string $lastErrorCode = null,
        ?string $lastErrorMessage = null
    ): TransactionIntake {
        return TransactionIntake::create([
            'submission_uuid' => $payload['submission_uuid'],
            'tenant_id' => $terminal->tenant_id,
            'terminal_id' => $terminal->id,
            'payload_checksum' => $payload['payload_checksum'],
            'payload' => $payload,
            'payload_size_bytes' => strlen(json_encode($payload, JSON_THROW_ON_ERROR)),
            'source_ip' => '127.0.0.1',
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => $processingStatus,
            'attempt_count' => 1,
            'last_error_code' => $lastErrorCode,
            'last_error_message' => $lastErrorMessage,
            'trace_id' => (string) Str::uuid(),
            'received_at' => now(),
            'queued_at' => now(),
            'processed_at' => now(),
        ]);
    }

    private function seedTransactionRow(PosTerminal $terminal, string $transactionId, string $validationStatus): Transaction
    {
        return Transaction::factory()->create([
            'tenant_id' => $terminal->tenant_id,
            'terminal_id' => $terminal->id,
            'transaction_id' => $transactionId,
            'validation_status' => $validationStatus,
        ]);
    }

    private function buildOfficialBatchPayload(
        array $txIds,
        int $tenantId,
        int $terminalId,
        string $submissionUuid,
        string $hardwareId
    ): array {
        $service = new PayloadChecksumService;
        $now = Carbon::now('UTC');
        $transactions = [];

        foreach ($txIds as $txId) {
            $single = $this->buildOfficialPayloadWithTx($txId, $tenantId, $terminalId, $submissionUuid, $hardwareId);
            $transactions[] = $single['transaction'];
        }

        $payload = [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => $now->format('Y-m-d\\TH:i:s\\Z'),
            'transaction_count' => count($transactions),
            'transactions' => $transactions,
        ];
        $payload['payload_checksum'] = $service->computeChecksum($payload);

        return $payload;
    }

    private function mockRedisForAdmissionMiddleware(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('eval')->zeroOrMoreTimes()->andReturn(1);
        $redis->shouldReceive('llen')->zeroOrMoreTimes()->andReturn(0);

        Redis::shouldReceive('connection')->with('default')->zeroOrMoreTimes()->andReturn($redis);
    }
}
