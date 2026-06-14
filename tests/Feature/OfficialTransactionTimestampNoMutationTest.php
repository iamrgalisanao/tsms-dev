<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficialTransactionTimestampNoMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_ingestion_preserves_original_transaction_timestamp_payload(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $timestamp = Carbon::now('UTC')->subMinute()->format('Y-m-d\TH:i:s\Z');
        $payload = $this->makeOfficialPayload($tenant->id, $terminal->id, $timestamp);

        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer '.$terminal->generateAccessToken(),
                'Content-Type' => 'application/json',
            ])
            ->postJson('/api/v1/transactions/official', $payload);

        $response->assertOk();

        $transaction = Transaction::where('transaction_id', $payload['transaction']['transaction_id'])->firstOrFail();
        $originalPayload = json_decode((string) $transaction->getRawOriginal('original_payload'), true);

        $this->assertSame($timestamp, $originalPayload['transaction_timestamp']);
        $this->assertSame($payload['transaction']['receipt_no'], $transaction->receipt_no);
    }

    public function test_official_ingestion_accepts_checksum_valid_financial_values_without_formula_enforcement(): void
    {
        Queue::fake();

        $checksum = new PayloadChecksumService();
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $timestamp = Carbon::now('UTC')->subMinute()->format('Y-m-d\TH:i:s\Z');
        $payload = $this->makeOfficialPayload($tenant->id, $terminal->id, $timestamp);

        $payload['transaction']['gross_sales'] = '100.00';
        $payload['transaction']['net_sales'] = '84.74';
        $payload['transaction']['taxes'] = [
            ['tax_type' => 'VAT', 'amount' => '15.24'],
            ['tax_type' => 'VATABLE_SALES', 'amount' => '84.74'],
            ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => '0.00'],
            ['tax_type' => 'OTHER_TAX', 'amount' => '0.00'],
        ];

        $transactionForChecksum = $payload['transaction'];
        unset($transactionForChecksum['payload_checksum']);
        $payload['transaction']['payload_checksum'] = $checksum->computeChecksum($transactionForChecksum);

        $submissionForChecksum = $payload;
        unset($submissionForChecksum['payload_checksum']);
        $payload['payload_checksum'] = $checksum->computeChecksum($submissionForChecksum);

        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer '.$terminal->generateAccessToken(),
                'Content-Type' => 'application/json',
            ])
            ->postJson('/api/v1/transactions/official', $payload);

        $response->assertOk();

        $transaction = Transaction::where('transaction_id', $payload['transaction']['transaction_id'])->firstOrFail();

        $this->assertSame('100.00', number_format((float) $transaction->gross_sales, 2, '.', ''));
        $this->assertSame('84.74', number_format((float) $transaction->net_sales, 2, '.', ''));
    }

    private function makeOfficialPayload(int $tenantId, int $terminalId, string $timestamp): array
    {
        $checksum = new PayloadChecksumService();

        $adjustments = [
            ['adjustment_type' => 'promo_discount', 'amount' => '0.00'],
            ['adjustment_type' => 'senior_discount', 'amount' => '0.00'],
            ['adjustment_type' => 'pwd_discount', 'amount' => '0.00'],
            ['adjustment_type' => 'vip_card_discount', 'amount' => '0.00'],
            ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => '0.00'],
            ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => '0.00'],
            ['adjustment_type' => 'employee_discount', 'amount' => '0.00'],
        ];
        $taxes = [
            ['tax_type' => 'VAT', 'amount' => '0.00'],
            ['tax_type' => 'VATABLE_SALES', 'amount' => '100.00'],
            ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => '0.00'],
            ['tax_type' => 'OTHER_TAX', 'amount' => '0.00'],
        ];

        $transactionScalars = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => 'NO-MUTATION-001',
            'receipt_no' => 'NM-'.Str::upper(Str::random(8)),
            'transaction_timestamp' => $timestamp,
            'gross_sales' => '100.00',
            'net_sales' => '100.00',
            'promo_status' => 'NONE',
            'customer_code' => 'C-TEST',
        ];
        $transactionForChecksum = array_merge($transactionScalars, [
            'adjustments' => $adjustments,
            'taxes' => $taxes,
        ]);
        $transaction = array_merge($transactionScalars, [
            'payload_checksum' => $checksum->computeChecksum($transactionForChecksum),
            'adjustments' => $adjustments,
            'taxes' => $taxes,
        ]);

        $submission = [
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];
        $submission['payload_checksum'] = $checksum->computeChecksum($submission);

        return [
            'submission_uuid' => $submission['submission_uuid'],
            'tenant_id' => $submission['tenant_id'],
            'terminal_id' => $submission['terminal_id'],
            'submission_timestamp' => $submission['submission_timestamp'],
            'transaction_count' => $submission['transaction_count'],
            'payload_checksum' => $submission['payload_checksum'],
            'transaction' => $transaction,
        ];
    }
}
