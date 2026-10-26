<?php

namespace Tests\Feature\Ingestion;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\ProviderTimestampNormalizer;
use App\Services\TransactionIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TransactionIngestService::findReceiptConflict() uses a sargable lookup on
 * (tenant_id, terminal_id, receipt_no, transaction_timestamp) while keeping
 * the existing ±86400 s PHP window as the authoritative check.
 *
 * Every assertion is scoped to a freshly seeded tenant/terminal because of
 * the known RefreshDatabase isolation leak.
 */
class ReceiptConflictLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_conflict_exactly_86400_seconds_later_is_detected(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        [$payload, $incomingTs] = $this->incoming($tenant, $terminal, 'RC-EDGE-'.Str::upper(Str::random(6)));
        $this->existingRow($tenant, $terminal, $payload['receipt_no'], date('Y-m-d H:i:s', $incomingTs + 86400));

        $result = $this->service()->ingest($payload);

        $this->assertSame('duplicate_receipt_conflict', $result['message']);
        $this->assertSame(0, Transaction::where('transaction_id', $payload['transaction_id'])->count());
    }

    public function test_conflict_exactly_86400_seconds_earlier_is_detected(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        [$payload, $incomingTs] = $this->incoming($tenant, $terminal, 'RC-EDGE-'.Str::upper(Str::random(6)));
        $this->existingRow($tenant, $terminal, $payload['receipt_no'], date('Y-m-d H:i:s', $incomingTs - 86400));

        $result = $this->service()->ingest($payload);

        $this->assertSame('duplicate_receipt_conflict', $result['message']);
    }

    public function test_row_86401_seconds_away_is_not_a_conflict(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        [$payload, $incomingTs] = $this->incoming($tenant, $terminal, 'RC-OUT-'.Str::upper(Str::random(6)));
        $this->existingRow($tenant, $terminal, $payload['receipt_no'], date('Y-m-d H:i:s', $incomingTs + 86401));
        $this->existingRow($tenant, $terminal, $payload['receipt_no'], date('Y-m-d H:i:s', $incomingTs - 86401));

        $result = $this->service()->ingest($payload);

        $this->assertSame('accepted', $result['status']);
        $this->assertSame(1, Transaction::where('transaction_id', $payload['transaction_id'])->count());
    }

    public function test_row_86400_point_5_seconds_later_is_detected_because_milliseconds_are_dropped(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        [$payload, $incomingTs] = $this->incoming($tenant, $terminal, 'RC-MS-'.Str::upper(Str::random(6)));
        $this->existingRow($tenant, $terminal, $payload['receipt_no'], date('Y-m-d H:i:s', $incomingTs + 86400).'.500');

        $result = $this->service()->ingest($payload);

        $this->assertSame('duplicate_receipt_conflict', $result['message']);
    }

    public function test_existing_row_with_null_transaction_timestamp_is_ignored(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        [$payload] = $this->incoming($tenant, $terminal, 'RC-NULL-'.Str::upper(Str::random(6)));
        $existing = $this->existingRow($tenant, $terminal, $payload['receipt_no'], now()->format('Y-m-d H:i:s'));

        try {
            DB::table('transactions')->where('id', $existing->id)->update(['transaction_timestamp' => null]);
        } catch (\Illuminate\Database\QueryException $e) {
            $this->markTestSkipped('transactions.transaction_timestamp is NOT NULL in this schema.');
        }

        // Call the lookup directly: the full insert path may still be refused
        // by an unrelated receipt/date unique index, which is not under test.
        $parent = [
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'receipt_no' => $payload['receipt_no'],
            'transaction_timestamp' => app(ProviderTimestampNormalizer::class)->normalize($payload['transaction_timestamp'], $terminal->id),
        ];
        $lookup = new \ReflectionMethod(TransactionIngestService::class, 'findReceiptConflict');

        $this->assertNull($lookup->invoke($this->service(), $parent));
    }

    public function test_padded_incoming_receipt_is_stored_trimmed_and_conflicts_with_unpadded_row(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $receipt = 'R-100-'.Str::upper(Str::random(6));

        [$fresh] = $this->incoming($tenant, $terminal, '  '.$receipt.' ');
        $first = $this->service()->ingest($fresh);
        $this->assertSame('accepted', $first['status']);
        $this->assertSame($receipt, DB::table('transactions')->where('id', $first['id'])->value('receipt_no'));
        $this->assertStringContainsString('  '.$receipt.' ', (string) DB::table('transactions')->where('id', $first['id'])->value('original_payload'));
        $this->assertSame($fresh['payload_checksum'], DB::table('transactions')->where('id', $first['id'])->value('payload_checksum'));

        [$again] = $this->incoming($tenant, $terminal, " {$receipt}\t", ['payload_checksum' => str_repeat('d', 64)]);
        $second = $this->service()->ingest($again);

        $this->assertSame('duplicate_receipt_conflict', $second['message']);
        $this->assertSame(0, Transaction::where('transaction_id', $again['transaction_id'])->count());
    }

    public function test_padded_incoming_receipt_conflicts_with_existing_unpadded_row(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        $receipt = 'R-100-'.Str::upper(Str::random(6));
        [$payload, $incomingTs] = $this->incoming($tenant, $terminal, '  '.$receipt.' ');
        $this->existingRow($tenant, $terminal, $receipt, date('Y-m-d H:i:s', $incomingTs - 3600));

        $result = $this->service()->ingest($payload);

        $this->assertSame('duplicate_receipt_conflict', $result['message']);
    }

    public function test_same_terminal_id_and_receipt_under_another_tenant_is_ignored(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        [$otherTenant] = $this->seedTenantAndTerminal();
        [$payload, $incomingTs] = $this->incoming($tenant, $terminal, 'RC-TEN-'.Str::upper(Str::random(6)));
        // Same terminal_id + receipt_no, different tenant_id.
        $this->existingRow($otherTenant, $terminal, $payload['receipt_no'], date('Y-m-d H:i:s', $incomingTs - 60));

        $result = $this->service()->ingest($payload);

        $this->assertSame('accepted', $result['status']);
    }

    public function test_conflict_query_is_sargable_with_no_trim(): void
    {
        [$tenant, $terminal] = $this->seedTenantAndTerminal();
        [$payload, $incomingTs] = $this->incoming($tenant, $terminal, ' RC-SQL-'.Str::upper(Str::random(6)).' ');
        $this->existingRow($tenant, $terminal, trim($payload['receipt_no']), date('Y-m-d H:i:s', $incomingTs - 60));

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'receipt_no') && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $queries[] = $query;
            }
        });

        $result = $this->service()->ingest($payload);

        $this->assertSame('duplicate_receipt_conflict', $result['message']);
        $this->assertNotEmpty($queries, 'expected a receipt conflict lookup');

        foreach ($queries as $query) {
            $this->assertStringNotContainsStringIgnoringCase('TRIM(', $query->sql);
            $this->assertStringContainsString('`receipt_no` = ?', $query->sql);
            $this->assertStringContainsString('`tenant_id` = ?', $query->sql);
            $this->assertStringContainsString('`transaction_timestamp` >= ?', $query->sql);
            $this->assertStringContainsString('`transaction_timestamp` < ?', $query->sql);
            $this->assertContains(trim($payload['receipt_no']), $query->bindings);
            $this->assertContains(date('Y-m-d H:i:s', $incomingTs - 86400), $query->bindings);
            $this->assertContains(date('Y-m-d H:i:s', $incomingTs + 86401), $query->bindings);
        }
    }

    private function service(): TransactionIngestService
    {
        return app(TransactionIngestService::class);
    }

    private function seedTenantAndTerminal(): array
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $terminal];
    }

    /**
     * @return array{0: array, 1: int} payload and the incoming normalized unix timestamp
     */
    private function incoming(Tenant $tenant, PosTerminal $terminal, string $receiptNo, array $overrides = []): array
    {
        $raw = now()->subHours(2)->startOfSecond()->format('Y-m-d\TH:i:s\Z');
        $normalized = app(ProviderTimestampNormalizer::class)->normalize($raw, $terminal->id);

        $payload = array_merge([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => 'RCL-'.Str::upper(Str::random(10)),
            'hardware_id' => $terminal->serial_number,
            'receipt_no' => $receiptNo,
            'transaction_timestamp' => $raw,
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

        return [$payload, strtotime($normalized)];
    }

    private function existingRow(Tenant $tenant, PosTerminal $terminal, string $receiptNo, string $timestamp): Transaction
    {
        $row = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => 'RCX-'.Str::upper(Str::random(10)),
            'hardware_id' => $terminal->serial_number,
            'payload_checksum' => str_repeat('c', 64),
            'submission_uuid' => (string) Str::uuid(),
        ]);

        // Raw write so the stored receipt/timestamp are exactly as given.
        DB::table('transactions')->where('id', $row->id)->update([
            'receipt_no' => $receiptNo,
            'transaction_timestamp' => $timestamp,
        ]);

        return $row;
    }
}
