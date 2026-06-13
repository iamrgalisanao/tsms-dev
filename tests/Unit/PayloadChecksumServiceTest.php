<?php

namespace Tests\Unit;

use App\Services\PayloadChecksumService;
use Tests\TestCase;

class PayloadChecksumServiceTest extends TestCase
{
    public function test_validates_v21_submission_checksums(): void
    {
        $service = new PayloadChecksumService();
        $payload = $this->payload();
        $this->applyChecksums($payload, $service, 'v2.1');

        $result = $service->validateSubmissionChecksums($payload);

        $this->assertTrue($result['valid']);
        $this->assertSame('v2.1', $result['checksum_version']);
        $this->assertSame([], $result['errors']);
    }

    public function test_validates_v20_float_normalized_checksums_as_fallback(): void
    {
        $service = new PayloadChecksumService();
        $payload = $this->payload();
        $this->applyChecksums($payload, $service, 'v2.0');

        $result = $service->validateSubmissionChecksums($payload);

        $this->assertTrue($result['valid']);
        $this->assertSame('v2.0', $result['checksum_version']);
        $this->assertSame([], $result['errors']);
    }

    public function test_checksum_comparison_is_case_insensitive(): void
    {
        $service = new PayloadChecksumService();
        $payload = $this->payload();
        $this->applyChecksums($payload, $service, 'v2.1');
        $payload['payload_checksum'] = strtoupper($payload['payload_checksum']);
        $payload['transaction']['payload_checksum'] = strtoupper($payload['transaction']['payload_checksum']);
        $submission = $payload;
        unset($submission['payload_checksum']);
        $payload['payload_checksum'] = strtoupper($service->computeChecksum($submission, 'v2.1'));

        $result = $service->validateSubmissionChecksums($payload);

        $this->assertTrue($result['valid']);
        $this->assertSame('v2.1', $result['checksum_version']);
    }

    public function test_invalid_checksum_errors_include_computed_values(): void
    {
        $service = new PayloadChecksumService();
        $payload = $this->payload();
        $this->applyChecksums($payload, $service, 'v2.1');
        $payload['transaction']['payload_checksum'] = str_repeat('0', 64);
        $payload['payload_checksum'] = str_repeat('1', 64);

        $result = $service->validateSubmissionChecksums($payload);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Received: ' . str_repeat('0', 64), $result['errors'][0]);
        $this->assertStringContainsString('Computed:', $result['errors'][0]);
        $this->assertArrayHasKey('v2.1', $result['diagnostics']);
        $this->assertArrayHasKey('v2.0', $result['diagnostics']);
    }

    private function payload(): array
    {
        return [
            'submission_uuid' => '13f10795-d178-4720-9979-7895d1067d59',
            'tenant_id' => 3,
            'terminal_id' => 43,
            'submission_timestamp' => '2026-06-14T07:36:27Z',
            'transaction_count' => 1,
            'payload_checksum' => '',
            'transaction' => [
                'transaction_id' => '2ab6cc34-596c-4485-bda4-2d02f6e3f9ba',
                'receipt_no' => '0000017336',
                'transaction_timestamp' => '2026-06-14T07:36:27Z',
                'gross_sales' => '90.00',
                'net_sales' => '90.00',
                'promo_status' => 'WITH_APPROVAL',
                'customer_code' => 'C-C1036',
                'payload_checksum' => '',
                'adjustments' => [
                    ['adjustment_type' => 'promo_discount', 'amount' => '0.00'],
                    ['adjustment_type' => 'senior_discount', 'amount' => '0.00'],
                ],
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => '9.64'],
                    ['tax_type' => 'VATABLE_SALES', 'amount' => '80.36'],
                ],
            ],
        ];
    }

    private function applyChecksums(array &$payload, PayloadChecksumService $service, string $version): void
    {
        $transaction = $payload['transaction'];
        unset($transaction['payload_checksum']);
        $payload['transaction']['payload_checksum'] = $service->computeChecksum($transaction, $version);

        $submission = $payload;
        unset($submission['payload_checksum']);
        $payload['payload_checksum'] = $service->computeChecksum($submission, $version);
    }
}
