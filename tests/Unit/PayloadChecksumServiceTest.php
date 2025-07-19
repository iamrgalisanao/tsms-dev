<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\PayloadChecksumService;

class PayloadChecksumServiceTest extends TestCase
{
    /**
     * Test that a known-good payload passes checksum validation.
     */
    public function testValidSubmissionChecksums()
    {
               $json = <<<'JSON'
{
    "submission_uuid": "a3b2c1d4-e5f6-7890-abcd-1234567890ab",
    "tenant_id": 45,
    "terminal_id": 3,
    "submission_timestamp": "2025-07-19T12:00:00Z",
    "transaction_count": 1,
    "payload_checksum": "d837847cb1cc1da4455bb8ed83ac8e43305cd4d6cabbce474b6fb9fbe8260408",
    "transaction": {
        "transaction_id": "a3b2c1d4-e5f6-7890-abcd-1234567890ab",
        "transaction_timestamp": "2025-07-19T12:00:01Z",
        "base_amount": 1000.0,
        "payload_checksum": "b070b269ab57e4fe9a7d41f3c0281d9bd928959b74225e1cfba90994317a0812",
        "adjustments": [
            { "adjustment_type": "promo_discount", "amount": 50.0 },
            { "adjustment_type": "senior_discount", "amount": 20.0 }
        ],
        "taxes": [
            { "tax_type": "VAT", "amount": 120.0 },
            { "tax_type": "OTHER_TAX", "amount": 10.0 }
        ]
    }
}
JSON;

        $payload = json_decode($json, true);
        $service = new \App\Services\PayloadChecksumService();

        // compute and dump
        $txnCopy = $payload['transaction'];
        unset($txnCopy['payload_checksum']);
        $computedTxn = $service->computeChecksum($txnCopy);

        $submissionCopy = $payload;
        unset($submissionCopy['payload_checksum']);
        $computedSubmission = $service->computeChecksum($submissionCopy);

        fwrite(STDOUT, "\nComputed transaction checksum: $computedTxn\n");
        fwrite(STDOUT, "Expected transaction checksum: {$payload['transaction']['payload_checksum']}\n");
        fwrite(STDOUT, "Computed submission checksum: $computedSubmission\n");
        fwrite(STDOUT, "Expected submission checksum: {$payload['payload_checksum']}\n\n");

        $result = $service->validateSubmissionChecksums($payload);
        $this->assertEmpty($result['errors'], 'There should be no errors for a valid payload.');
    }
}