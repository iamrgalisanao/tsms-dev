<?php

namespace Tests\Feature;

use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\TransactionIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionIngestServiceTimestampNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('tsms.circuit_breaker.enabled', false);
    }

    public function test_local_time_with_z_provider_timestamps_are_normalized_without_mutating_original_payload(): void
    {
        [$tenant, $terminal] = $this->tenantAndTerminal([
            'timezone' => 'Asia/Manila',
            'timestamp_mode' => 'local_time_with_z',
        ]);

        $payload = $this->payloadFor(
            $tenant->id,
            $terminal,
            '2026-08-10T16:02:40Z',
            '2026-08-10T16:03:11Z'
        );

        $result = app(TransactionIngestService::class)->ingest($payload);

        $this->assertSame('accepted', $result['status']);

        $transaction = Transaction::where('transaction_id', $payload['transaction_id'])->firstOrFail();
        $originalPayload = json_decode((string) $transaction->getRawOriginal('original_payload'), true);

        $this->assertSame('2026-08-10T16:02:40Z', $originalPayload['transaction_timestamp']);
        $this->assertSame('2026-08-10T16:03:11Z', $originalPayload['submission_timestamp']);
        $this->assertSame('2026-08-10T08:02:40Z', Carbon::parse($transaction->transaction_timestamp)->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-08-10T08:03:11Z', Carbon::parse($transaction->submission_timestamp)->utc()->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_true_utc_provider_timestamps_remain_absolute_utc(): void
    {
        [$tenant, $terminal] = $this->tenantAndTerminal([
            'timezone' => 'Asia/Manila',
            'timestamp_mode' => 'true_utc',
        ]);

        $payload = $this->payloadFor(
            $tenant->id,
            $terminal,
            '2026-08-10T16:02:40Z',
            '2026-08-10T16:03:11Z'
        );

        $result = app(TransactionIngestService::class)->ingest($payload);

        $this->assertSame('accepted', $result['status']);

        $transaction = Transaction::where('transaction_id', $payload['transaction_id'])->firstOrFail();

        $this->assertSame('2026-08-10T16:02:40Z', Carbon::parse($transaction->transaction_timestamp)->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-08-10T16:03:11Z', Carbon::parse($transaction->submission_timestamp)->utc()->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_local_time_with_z_variants_are_treated_as_provider_local_wall_clock(): void
    {
        [$tenant, $terminal] = $this->tenantAndTerminal([
            'timezone' => 'Asia/Manila',
            'timestamp_mode' => 'local_time_with_z',
        ]);

        $payload = $this->payloadFor(
            $tenant->id,
            $terminal,
            '2026-08-10T16:02:40.123Z',
            '2026-08-10 16:03:11+00:00'
        );

        $result = app(TransactionIngestService::class)->ingest($payload);

        $this->assertSame('accepted', $result['status']);

        $transaction = Transaction::where('transaction_id', $payload['transaction_id'])->firstOrFail();

        $this->assertSame('2026-08-10T08:02:40Z', Carbon::parse($transaction->transaction_timestamp)->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-08-10T08:03:11Z', Carbon::parse($transaction->submission_timestamp)->utc()->format('Y-m-d\TH:i:s\Z'));
    }

    /**
     * @param array<string, string> $providerAttributes
     * @return array{0: Tenant, 1: PosTerminal}
     */
    private function tenantAndTerminal(array $providerAttributes): array
    {
        $tenant = Tenant::factory()->create();
        $provider = PosProvider::factory()->create($providerAttributes);
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'provider_id' => $provider->id,
        ]);

        return [$tenant, $terminal];
    }

    private function payloadFor(
        int $tenantId,
        PosTerminal $terminal,
        string $transactionTimestamp,
        string $submissionTimestamp
    ): array {
        return [
            'tenant_id' => $tenantId,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $terminal->serial_number,
            'receipt_no' => 'TS-' . Str::upper(Str::random(8)),
            'transaction_timestamp' => $transactionTimestamp,
            'gross_sales' => '100.00',
            'net_sales' => '100.00',
            'customer_code' => 'C-TEST',
            'promo_status' => 'NONE',
            'payload_checksum' => hash('sha256', Str::uuid()->toString()),
            'submission_uuid' => (string) Str::uuid(),
            'submission_timestamp' => $submissionTimestamp,
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => '0.00'],
                ['tax_type' => 'VATABLE_SALES', 'amount' => '100.00'],
                ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => '0.00'],
                ['tax_type' => 'OTHER_TAX', 'amount' => '0.00'],
            ],
            'adjustments' => [],
        ];
    }
}
