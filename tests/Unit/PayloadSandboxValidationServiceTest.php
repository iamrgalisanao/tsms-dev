<?php

namespace Tests\Unit;

use App\Services\PayloadChecksumService;
use App\Services\PayloadSandboxValidationService;
use Tests\TestCase;

class PayloadSandboxValidationServiceTest extends TestCase
{
    public function test_rejects_submission_timestamp_with_milliseconds_even_when_checksums_match(): void
    {
        $payload = $this->validPayload();
        $payload['submission_timestamp'] = '2026-06-15T02:21:52.110Z';
        $this->applyChecksums($payload);

        $result = $this->validator()->validate($payload);

        $this->assertFalse($result['valid']);
        $this->assertSame('failed', $result['checks']['schema']);
        $this->assertSame('passed', $result['checks']['checksum']);
        $this->assertContains('INVALID_TIMESTAMP_FORMAT', array_column($result['errors'], 'code'));
        $this->assertContains('/submission_timestamp', array_column($result['errors'], 'pointer'));
    }

    public function test_rejects_transaction_timestamp_with_milliseconds_even_when_checksums_match(): void
    {
        $payload = $this->validPayload();
        $payload['transaction']['transaction_timestamp'] = '2026-06-12T21:07:28.110Z';
        $this->applyChecksums($payload);

        $result = $this->validator()->validate($payload);

        $this->assertFalse($result['valid']);
        $this->assertSame('failed', $result['checks']['schema']);
        $this->assertSame('passed', $result['checks']['checksum']);
        $this->assertContains('INVALID_TIMESTAMP_FORMAT', array_column($result['errors'], 'code'));
        $this->assertContains('/transaction/transaction_timestamp', array_column($result['errors'], 'pointer'));
    }

    public function test_accepts_production_timestamp_format(): void
    {
        $payload = $this->validPayload();
        $this->applyChecksums($payload);

        $result = $this->validator()->validate($payload);

        $this->assertTrue($result['valid']);
        $this->assertSame('passed', $result['checks']['schema']);
        $this->assertSame('passed', $result['checks']['checksum']);
    }

    private function validator(): PayloadSandboxValidationService
    {
        return new PayloadSandboxValidationService(new PayloadChecksumService());
    }

    private function validPayload(): array
    {
        return [
            'submission_uuid' => '65fde3f3-6718-418c-a106-79bfe5e9089d',
            'tenant_id' => 30,
            'terminal_id' => 109,
            'submission_timestamp' => '2026-06-15T02:21:52Z',
            'transaction_count' => 1,
            'payload_checksum' => '',
            'transaction' => [
                'transaction_id' => '7eda6226-b893-458d-a129-cdebe61b78fa',
                'hardware_id' => 'MUU 00027',
                'receipt_no' => '22672',
                'transaction_timestamp' => '2026-06-12T21:07:28Z',
                'gross_sales' => '50.00',
                'net_sales' => '50.00',
                'promo_status' => 'WITH_APPROVAL',
                'customer_code' => 'C-C1042',
                'payload_checksum' => '',
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
                    ['tax_type' => 'VAT', 'amount' => '5.36'],
                    ['tax_type' => 'VATABLE_SALES', 'amount' => '44.64'],
                    ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => '0.00'],
                    ['tax_type' => 'OTHER_TAX', 'amount' => '0.00'],
                ],
            ],
        ];
    }

    private function applyChecksums(array &$payload): void
    {
        $checksum = new PayloadChecksumService();

        $transaction = $payload['transaction'];
        unset($transaction['payload_checksum']);
        $payload['transaction']['payload_checksum'] = $checksum->computeChecksum($transaction);

        $submission = $payload;
        unset($submission['payload_checksum']);
        $payload['payload_checksum'] = $checksum->computeChecksum($submission);
    }
}
