<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficialTransactionValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_id_must_be_uuid_and_is_independent_from_submission_uuid(): void
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $submissionUuid = (string) Str::uuid();
        $transactionId = (string) Str::uuid();

        $this->assertNotSame($submissionUuid, $transactionId);

        $validPayload = $this->officialPayload($tenant->id, $terminal->id, $submissionUuid, $transactionId);
        $validPayload['transaction']['transaction_id'] = 'POS-TXN-000001';

        $response = $this
            ->withHeaders([
                'Authorization' => 'Bearer ' . $terminal->generateAccessToken(),
                'Content-Type' => 'application/json',
            ])
            ->postJson('/api/v1/transactions/official', $validPayload);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertArrayHasKey('transaction.transaction_id', $response->json('errors'));
    }

    private function officialPayload(int $tenantId, int $terminalId, string $submissionUuid, string $transactionId): array
    {
        $checksumService = new PayloadChecksumService();
        $timestamp = Carbon::now('UTC')->subMinute()->format('Y-m-d\TH:i:s\Z');
        $transaction = [
            'transaction_id' => $transactionId,
            'transaction_timestamp' => $timestamp,
            'gross_sales' => '100.00',
            'net_sales' => '100.00',
            'promo_status' => 'NONE',
            'customer_code' => 'C-TEST',
            'receipt_no' => '0000001234',
            'adjustments' => [
                ['adjustment_type' => 'promo_discount', 'amount' => '0.00'],
            ],
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => '0.00'],
                ['tax_type' => 'VATABLE_SALES', 'amount' => '100.00'],
                ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => '0.00'],
                ['tax_type' => 'OTHER_TAX', 'amount' => '0.00'],
            ],
        ];
        $transaction['payload_checksum'] = $checksumService->computeChecksum($transaction);

        $submission = [
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenantId,
            'terminal_id' => $terminalId,
            'submission_timestamp' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];
        $submission['payload_checksum'] = $checksumService->computeChecksum($submission);

        return $submission;
    }
}
