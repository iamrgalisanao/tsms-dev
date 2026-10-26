<?php

namespace Tests\Feature\Ingestion;

use App\Jobs\ProcessTransactionIntakeJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionIntake;
use App\Services\DeadlockRetryService;
use App\Services\ProviderTimestampNormalizer;
use App\Services\TransactionIngestService;
use App\Support\Metrics;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * US2 (tasks T011/T012, contracts/ingest-outcomes.md): the ingest outcome
 * recorded for a duplicate-key insert matches the ledger.
 *
 * Duplicate-key races are simulated through the insertTransactionParent()
 * seam with a realistic MySQL errorInfo (research A9), so these tests do
 * not depend on which unique indexes the test schema happens to have.
 */
class IngestOutcomeClassificationTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY_MESSAGE = 'insert ignored but no existing transaction found';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_new_sale_is_accepted_and_intake_processed(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $txId = 'NEW-'.Str::upper(Str::random(8));

        $result = $this->realService()->ingest($this->payload($tenant, $terminal, $txId));

        $this->assertSame('accepted', $result['status']);
        $this->assertSame('created', $result['message']);
        $this->assertSame(1, Transaction::where('transaction_id', $txId)->count());

        $intake = $this->runJob($tenant, $terminal, [$this->item($terminal, 'NEW-'.Str::upper(Str::random(8)))]);
        $this->assertSame(TransactionIntake::PROCESSING_STATUS_PROCESSED, $intake->processing_status);
        $this->assertNull($intake->last_error_code);
    }

    public function test_same_tenant_duplicate_transaction_id_is_already_processed(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $existing = $this->existingTransaction($tenant, $terminal, 'DUP-'.Str::upper(Str::random(8)));

        $result = $this->seamService(duplicateKey: 'transactions.ux_transactions_transaction_id')
            ->ingest($this->payload($tenant, $terminal, $existing->transaction_id));

        $this->assertSame('already_processed', $result['status']);
        $this->assertSame('already_processed', $result['message']);
        $this->assertSame($existing->id, $result['id']);
        $this->assertSame(1, Transaction::where('transaction_id', $existing->transaction_id)->count());

        $intake = $this->runJob(
            $tenant,
            $terminal,
            [$this->item($terminal, $existing->transaction_id)],
            $this->seamService(duplicateKey: 'transactions.ux_transactions_transaction_id')
        );
        $this->assertSame(TransactionIntake::PROCESSING_STATUS_PROCESSED, $intake->processing_status);
        $this->assertNull($intake->last_error_code);
    }

    public function test_same_tenant_resubmission_is_already_processed_when_another_tenant_also_holds_the_id(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        [$otherTenant, $otherTerminal] = $this->seedTenantAndTerminal();
        $txId = 'SHARED-'.Str::upper(Str::random(8));
        $foreign = $this->existingTransaction($otherTenant, $otherTerminal, $txId);
        $own = $this->existingTransaction($tenant, $terminal, $txId);

        $result = $this->seamService(duplicateKey: 'transactions.trx_terminal_transaction_unique')
            ->ingest($this->payload($tenant, $terminal, $txId));

        $this->assertSame('already_processed', $result['status']);
        $this->assertSame($own->id, $result['id']);
        $this->assertNotSame($foreign->id, $result['id']);

        $intake = $this->runJob(
            $tenant,
            $terminal,
            [$this->item($terminal, $txId)],
            $this->seamService(duplicateKey: 'transactions.trx_terminal_transaction_unique')
        );
        $this->assertSame(TransactionIntake::PROCESSING_STATUS_PROCESSED, $intake->processing_status);
        $this->assertNull($intake->last_error_code);
    }

    public function test_cross_tenant_collision_fails_with_warning_and_no_foreign_id(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        [$otherTenant, $otherTerminal] = $this->seedTenantAndTerminal();
        $txId = 'XTEN-'.Str::upper(Str::random(8));
        $foreign = $this->existingTransaction($otherTenant, $otherTerminal, $txId);
        $metricBefore = (int) Metrics::get('ingest.cross_tenant_collision_count', 0);
        Log::spy();

        $result = $this->seamService(duplicateKey: 'transactions.ux_transactions_transaction_id')
            ->ingest($this->payload($tenant, $terminal, $txId));

        $this->assertSame('failed', $result['status']);
        $this->assertSame('cross_tenant_transaction_id_collision', $result['message']);
        $this->assertNull($result['id']);
        $this->assertNotContains($foreign->id, array_values($result), 'foreign row id must never be returned');
        $this->assertSame($txId, $result['transaction_id']);
        $this->assertSame(1, Transaction::where('transaction_id', $txId)->count());
        $this->assertSame($metricBefore + 1, (int) Metrics::get('ingest.cross_tenant_collision_count', 0));

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) use ($tenant, $otherTenant, $txId) {
            return str_contains($message, 'cross-tenant transaction_id collision')
                && (int) $context['incoming_tenant_id'] === (int) $tenant->id
                && (int) $context['existing_tenant_id'] === (int) $otherTenant->id
                && $context['transaction_id'] === $txId
                && ! array_key_exists('id', $context);
        })->once();

        $intake = $this->runJob(
            $tenant,
            $terminal,
            [$this->item($terminal, $txId)],
            $this->seamService(duplicateKey: 'transactions.ux_transactions_transaction_id')
        );
        $this->assertSame(TransactionIntake::PROCESSING_STATUS_FAILED_PERMANENT, $intake->processing_status);
        $this->assertSame('cross_tenant_transaction_id_collision', $intake->last_error_code);
        $this->assertStringNotContainsString('"id":'.$foreign->id, (string) $intake->last_error_message);
    }

    public function test_receipt_date_duplicate_with_new_transaction_id_is_duplicate_receipt_conflict(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $existing = $this->existingTransaction($tenant, $terminal, 'RCPT-'.Str::upper(Str::random(8)));
        $index = 'transactions.ux_tx_tenant_terminal_receipt_date_unique';

        // Pre-checks are hidden to simulate the row committing between the
        // receipt checks and the insert.
        $result = $this->seamService(duplicateKey: $index, hiddenReceiptChecks: 2)
            ->ingest($this->payload($tenant, $terminal, 'NEWTX-'.Str::upper(Str::random(8)), [
                'receipt_no' => $existing->receipt_no,
                'payload_checksum' => str_repeat('d', 64),
            ]));

        $this->assertSame('duplicate', $result['status']);
        $this->assertSame('duplicate_receipt_conflict', $result['message']);
        $this->assertSame($existing->id, $result['id']);

        $intake = $this->runJob(
            $tenant,
            $terminal,
            [$this->item($terminal, 'NEWTX-'.Str::upper(Str::random(8)), [
                'receipt_no' => $existing->receipt_no,
                'payload_checksum' => str_repeat('d', 64),
            ])],
            $this->seamService(duplicateKey: $index, hiddenReceiptChecks: 2)
        );
        $this->assertSame(TransactionIntake::PROCESSING_STATUS_DUPLICATE, $intake->processing_status);
        $this->assertSame('duplicate_receipt_conflict', $intake->last_error_code);
    }

    public function test_unknown_unique_index_fails_with_index_name_in_details(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        $result = $this->seamService(duplicateKey: 'transactions.ux_transactions_refund_reference')
            ->ingest($this->payload($tenant, $terminal, 'UNK-'.Str::upper(Str::random(8))));

        $this->assertSame('failed', $result['status']);
        $this->assertSame('unique_constraint_violation', $result['message']);
        $this->assertSame('ux_transactions_refund_reference', $result['details']);
        $this->assertNull($result['id']);

        $intake = $this->runJob(
            $tenant,
            $terminal,
            [$this->item($terminal, 'UNK-'.Str::upper(Str::random(8)))],
            $this->seamService(duplicateKey: 'transactions.ux_transactions_refund_reference')
        );
        $this->assertSame(TransactionIntake::PROCESSING_STATUS_FAILED_PERMANENT, $intake->processing_status);
        $this->assertSame('unique_constraint_violation', $intake->last_error_code);
    }

    public function test_zero_return_from_seam_with_no_match_is_unique_constraint_violation_unknown(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();

        $result = $this->seamService(returnZero: true)
            ->ingest($this->payload($tenant, $terminal, 'ZERO-'.Str::upper(Str::random(8))));

        $this->assertSame('failed', $result['status']);
        $this->assertSame('unique_constraint_violation', $result['message']);
        $this->assertSame('unknown', $result['details']);
        $this->assertNotSame(self::LEGACY_MESSAGE, $result['message']);
    }

    public function test_non_duplicate_query_exception_still_propagates_to_ingest_failed_without_logging_message(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        Log::spy();

        $result = $this->seamService(errorInfo: ['22001', 1406, "Data too long for column 'customer_code' at row 1"])
            ->ingest($this->payload($tenant, $terminal, 'LONG-'.Str::upper(Str::random(8)), [
                'submission_uuid' => 'SUB-REF-1',
            ]));

        $this->assertSame('failed', $result['status']);
        $this->assertSame('ingest_failed', $result['message']);
        $this->assertStringContainsString('Reference:', $result['details']);

        Log::shouldHaveReceived('error')->withArgs(function ($message, $context = []) {
            return $message === 'TransactionIngestService: ingest failed'
                && $context['exception_class'] === QueryException::class
                && $context['sqlstate'] === '22001'
                && $context['driver_code'] === 1406
                && $context['submission_uuid'] === 'SUB-REF-1'
                && ! array_key_exists('error', $context)
                && ! array_key_exists('payload', $context);
        })->once();
    }

    public function test_forced_strict_mode_error_not_masked_when_strict_mode_on(): void
    {
        // Production and staging run non-strict (research A13): over-length
        // values are still truncated there. This only proves INSERT IGNORE
        // no longer masks errors when strict mode is on.
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $txId = 'STRICT-'.Str::upper(Str::random(8));
        $originalMode = DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;

        // The mysql connection runs non-strict (config/database.php); force
        // strict mode for this session to prove the error is no longer
        // swallowed by INSERT IGNORE.
        DB::statement("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        try {
            $result = $this->realService()->ingest($this->payload($tenant, $terminal, $txId, [
                'customer_code' => str_repeat('C', 300),
            ]));
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$originalMode]);
        }

        $this->assertSame('failed', $result['status']);
        $this->assertSame('ingest_failed', $result['message']);
        $this->assertSame(0, Transaction::where('transaction_id', $txId)->count());
    }

    public function test_legacy_insert_ignored_message_is_never_produced(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $results = [
            $this->seamService(returnZero: true)->ingest($this->payload($tenant, $terminal, 'L1-'.Str::random(6))),
            $this->seamService(duplicateKey: 'transactions.whatever')->ingest($this->payload($tenant, $terminal, 'L2-'.Str::random(6))),
            $this->seamService(duplicateKey: null)->ingest($this->payload($tenant, $terminal, 'L3-'.Str::random(6))),
        ];

        foreach ($results as $result) {
            $this->assertNotSame(self::LEGACY_MESSAGE, $result['message']);
            $this->assertSame('unique_constraint_violation', $result['message']);
        }
        $this->assertSame('whatever', $results[1]['details']);
        $this->assertSame('unknown', $results[2]['details']);
    }

    public function test_concurrent_identical_new_sale_yields_one_row_and_already_processed(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $txId = 'RACE-'.Str::upper(Str::random(8));
        $payload = $this->payload($tenant, $terminal, $txId);

        $first = $this->realService()->ingest($payload);
        $this->assertSame('accepted', $first['status']);

        // Second worker: its receipt checks ran before the first committed
        // (hidden), then its insert hits the unique index.
        $second = $this->seamService(duplicateKey: 'transactions.ux_transactions_transaction_id', hiddenReceiptChecks: 2)
            ->ingest($payload);

        $this->assertSame('already_processed', $second['status']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, Transaction::where('transaction_id', $txId)->count());
    }

    public function test_staging_shaped_multi_item_submission_records_both_items_without_unique_conflict(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $first = $this->item($terminal, 'MULTI-A-'.Str::upper(Str::random(6)));
        $second = $this->item($terminal, 'MULTI-B-'.Str::upper(Str::random(6)));

        // Staging has no UNIQUE index on submission_uuid (research A1); the
        // test schema does. The seam writes submission_uuid as NULL so both
        // items of one submission can insert, as they would on staging.
        $intake = $this->runJob($tenant, $terminal, [$first, $second], $this->seamService(nullSubmissionUuid: true));

        $this->assertSame(TransactionIntake::PROCESSING_STATUS_PROCESSED, $intake->processing_status);
        $this->assertNull($intake->last_error_code);
        $this->assertSame(1, Transaction::where('transaction_id', $first['transaction_id'])->count());
        $this->assertSame(1, Transaction::where('transaction_id', $second['transaction_id'])->count());
    }

    public function test_missing_net_sales_is_invalid_payload_and_writes_no_row(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $txId = 'NONET-'.Str::upper(Str::random(8));
        $payload = $this->payload($tenant, $terminal, $txId);
        unset($payload['net_sales']);
        $metricBefore = (int) Metrics::get('ingest.data_failure_count', 0);

        $result = $this->realService()->ingest($payload);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('invalid_payload', $result['message']);
        $this->assertSame($metricBefore + 1, (int) Metrics::get('ingest.data_failure_count', 0));
        $this->assertSame('net_sales is required in transaction payload', $result['details']);
        $this->assertSame(0, Transaction::where('transaction_id', $txId)->count());
    }

    public function test_zero_net_sales_is_accepted_and_stored_as_zero(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $txId = 'ZERONET-'.Str::upper(Str::random(8));

        $result = $this->realService()->ingest($this->payload($tenant, $terminal, $txId, ['net_sales' => 0]));

        $this->assertSame('accepted', $result['status']);
        $this->assertSame('0.00', (string) DB::table('transactions')->where('id', $result['id'])->value('net_sales'));
    }

    public function test_invalid_payload_log_has_references_only(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $txId = 'LOGINV-'.Str::upper(Str::random(8));
        $payload = $this->payload($tenant, $terminal, $txId);
        unset($payload['net_sales']);
        Log::spy();

        $this->realService()->ingest($payload, 'corr-invalid');

        $this->assertReferenceOnlyLog('warning', 'TransactionIngestService: invalid payload', $tenant, $terminal, $txId, 'corr-invalid');
    }

    public function test_malformed_child_row_logs_carry_index_and_correlation_without_row_content(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $txId = 'LOGCHILD-'.Str::upper(Str::random(8));
        Log::spy();

        $result = $this->realService()->ingest($this->payload($tenant, $terminal, $txId, [
            'adjustments' => [
                ['adjustment_type' => 'promo_discount', 'amount' => 0],
                ['adjustment_type' => 'SECRET-ADJ-MARKER'],
            ],
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => 0],
                ['amount' => 'SECRET-TAX-MARKER'],
            ],
        ]), 'corr-child');

        $this->assertSame('accepted', $result['status']);

        foreach (['adjustment', 'tax'] as $kind) {
            Log::shouldHaveReceived('warning')
                ->withArgs(function ($message, $context = []) use ($kind, $result) {
                    return $message === "TransactionIngestService: Skipping malformed {$kind} row"
                        && $context === [
                            'transaction_pk' => $result['id'],
                            'row_index' => 1,
                            'correlation_id' => 'corr-child',
                        ];
                })
                ->once();
        }
    }

    public function test_receipt_duplicate_log_has_references_only(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $existing = $this->existingTransaction($tenant, $terminal, 'LOGRC-'.Str::upper(Str::random(8)));
        $txId = 'LOGRC2-'.Str::upper(Str::random(8));
        Log::spy();

        $result = $this->realService()->ingest($this->payload($tenant, $terminal, $txId, [
            'receipt_no' => $existing->receipt_no,
        ]), 'corr-receipt');

        $this->assertSame('duplicate_receipt_conflict', $result['message']);
        $this->assertReferenceOnlyLog('warning', 'TransactionIngestService: duplicate receipt conflict identified', $tenant, $terminal, $txId, 'corr-receipt');
    }

    public function test_cross_tenant_log_has_references_only(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        [$otherTenant, $otherTerminal] = $this->seedTenantAndTerminal();
        $txId = 'LOGXT-'.Str::upper(Str::random(8));
        $this->existingTransaction($otherTenant, $otherTerminal, $txId);
        Log::spy();

        $this->seamService(duplicateKey: 'transactions.ux_transactions_transaction_id')
            ->ingest($this->payload($tenant, $terminal, $txId), 'corr-xtenant');

        $this->assertReferenceOnlyLog('warning', 'TransactionIngestService: cross-tenant transaction_id collision', $tenant, $terminal, $txId, 'corr-xtenant');
    }

    public function test_ingest_failed_log_has_references_only_and_job_passes_trace_id(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $txId = 'LOGFAIL-'.Str::upper(Str::random(8));
        Log::spy();

        $intake = $this->runJob(
            $tenant,
            $terminal,
            [$this->item($terminal, $txId)],
            $this->seamService(errorInfo: ['23000', 1048, "Column 'customer_code' cannot be null"])
        );

        $this->assertNotEmpty($intake->trace_id);
        $this->assertReferenceOnlyLog('error', 'TransactionIngestService: ingest failed', $tenant, $terminal, $txId, $intake->trace_id);
    }

    private function assertReferenceOnlyLog(string $level, string $message, Tenant $tenant, PosTerminal $terminal, string $txId, string $correlationId): void
    {
        $forbidden = ['payload', 'original_payload', 'row', 'gross_sales', 'net_sales', 'customer_code', 'hardware_id', 'payload_checksum', 'transaction_timestamp'];

        Log::shouldHaveReceived($level)->withArgs(function ($logged, $context = []) use ($message, $tenant, $terminal, $txId, $correlationId, $forbidden) {
            if ($logged !== $message) {
                return false;
            }

            foreach ($forbidden as $key) {
                if (array_key_exists($key, $context)) {
                    return false;
                }
            }

            return (int) ($context['tenant_id'] ?? 0) === (int) $tenant->id
                && (int) ($context['terminal_id'] ?? 0) === (int) $terminal->id
                && ($context['transaction_id'] ?? null) === $txId
                && ($context['correlation_id'] ?? null) === $correlationId;
        })->atLeast()->once();
    }

    private function realService(): TransactionIngestService
    {
        return app(TransactionIngestService::class);
    }

    /**
     * Test seam over insertTransactionParent()/findReceiptConflict().
     * duplicateKey: throw a MySQL 1062 for that key (null = no "for key" part).
     */
    private function seamService(
        string|null|false $duplicateKey = false,
        ?array $errorInfo = null,
        bool $returnZero = false,
        int $hiddenReceiptChecks = 0,
        bool $nullSubmissionUuid = false,
    ): TransactionIngestService {
        if ($duplicateKey !== false) {
            $errorInfo = ['23000', 1062, $duplicateKey === null
                ? "Duplicate entry 'x'"
                : "Duplicate entry 'x' for key '{$duplicateKey}'"];
        }

        $service = new class(app(DeadlockRetryService::class), app(ProviderTimestampNormalizer::class)) extends TransactionIngestService
        {
            public ?array $errorInfo = null;

            public bool $returnZero = false;

            public int $hiddenReceiptChecks = 0;

            public bool $nullSubmissionUuid = false;

            protected function findReceiptConflict(array $parent): ?object
            {
                if ($this->hiddenReceiptChecks > 0) {
                    $this->hiddenReceiptChecks--;

                    return null;
                }

                return parent::findReceiptConflict($parent);
            }

            protected function insertTransactionParent(array $parent): int
            {
                if ($this->errorInfo !== null) {
                    $previous = new \PDOException('SQLSTATE['.$this->errorInfo[0].']: '.$this->errorInfo[2]);
                    $previous->errorInfo = $this->errorInfo;

                    throw new QueryException('mysql', 'insert into `transactions` ...', [], $previous);
                }

                if ($this->returnZero) {
                    return 0;
                }

                if ($this->nullSubmissionUuid) {
                    $parent['submission_uuid'] = null;
                }

                return parent::insertTransactionParent($parent);
            }
        };

        $service->errorInfo = $errorInfo;
        $service->returnZero = $returnZero;
        $service->hiddenReceiptChecks = $hiddenReceiptChecks;
        $service->nullSubmissionUuid = $nullSubmissionUuid;

        return $service;
    }

    private function runJob(Tenant $tenant, PosTerminal $terminal, array $items, ?TransactionIngestService $service = null): TransactionIntake
    {
        $submissionUuid = (string) Str::uuid();
        $intake = TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'payload_checksum' => str_repeat('a', 64),
            'payload' => [
                'submission_uuid' => $submissionUuid,
                'submission_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
                'payload_checksum' => str_repeat('a', 64),
                'transactions' => $items,
            ],
            'payload_size_bytes' => 1024,
            'source_ip' => '127.0.0.1',
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => null,
            'trace_id' => (string) Str::uuid(),
            'received_at' => now(),
            'queued_at' => now(),
        ]);

        (new ProcessTransactionIntakeJob($intake->id, $intake->trace_id))->handle($service ?? $this->realService());

        return $intake->fresh();
    }

    private function seedTenantAndTerminal(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $terminal];
    }

    private function existingTransaction(Tenant $tenant, PosTerminal $terminal, string $txId): Transaction
    {
        return Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => $txId,
            'hardware_id' => $terminal->serial_number,
            'receipt_no' => 'EX-'.Str::upper(Str::random(8)),
            'transaction_timestamp' => now()->subMinutes(2),
            'payload_checksum' => str_repeat('c', 64),
            'submission_uuid' => (string) Str::uuid(),
        ]);
    }

    private function payload(Tenant $tenant, PosTerminal $terminal, string $txId, array $overrides = []): array
    {
        return array_merge($this->item($terminal, $txId), [
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
        ], $overrides);
    }

    private function item(PosTerminal $terminal, string $txId, array $overrides = []): array
    {
        return array_merge([
            'transaction_id' => $txId,
            'hardware_id' => $terminal->serial_number,
            'receipt_no' => 'IO-'.Str::upper(Str::random(8)),
            'transaction_timestamp' => now()->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'gross_sales' => 100.0,
            'net_sales' => 100.0,
            'promo_status' => 'NONE',
            'customer_code' => 'C-TEST',
            'payload_checksum' => str_repeat('b', 64),
            'adjustments' => [
                ['adjustment_type' => 'promo_discount', 'amount' => 0],
            ],
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => 0],
                ['tax_type' => 'VATABLE_SALES', 'amount' => 100],
            ],
        ], $overrides);
    }
}
