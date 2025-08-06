<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\PayloadChecksumService;

$payload = [
    
    "submission_uuid" => "e3b0c442-98fc-1c14-9afb-4c8996fb9242",
    "tenant_id" => 45,
    "terminal_id" => 5,
    "submission_timestamp" => "2025-07-19T12:00:00Z",
    "transaction_count" => 1,
    "payload_checksum" => "7639f380298e007962afe6e7d606b80a883bd1c7c1b03168743034b60fce7fd0",
    "transaction" => [
        "transaction_id" => "f47ac10b-58cc-4372-a567-0e02b2c3d529",
        "transaction_timestamp" => "2025-07-19T12:00:01Z",
        "base_amount" => 1000.0,
        "payload_checksum" => "7497a3efefd5d1b5a574fc7bee2ef9c427479b785df8146295fed3c331fba05b",
        "adjustments" => [
        [ "adjustment_type" => "promo_discount", "amount" => 50.0 ],
        [ "adjustment_type" => "senior_discount", "amount" => 20.0 ]
        ],
        "taxes" => [
        [ "tax_type" => "VAT", "amount" => 120.0 ],
        [ "tax_type" => "OTHER_TAX", "amount" => 10.0 ]
        ]
    ]
    

];

$service = new PayloadChecksumService();

// Compute transaction checksum
$txn = $payload["transaction"];
$txnCopy = $txn;
unset($txnCopy["payload_checksum"]);
$txnChecksum = $service->computeChecksum($txnCopy);
$payload["transaction"]["payload_checksum"] = $txnChecksum;

// Compute submission checksum
$submissionCopy = $payload;
unset($submissionCopy["payload_checksum"]);
$submissionChecksum = $service->computeChecksum($submissionCopy);
$payload["payload_checksum"] = $submissionChecksum;

// Output the corrected payload
file_put_contents('corrected_payload.json', json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "Transaction payload_checksum: $txnChecksum\n";
echo "Submission payload_checksum: $submissionChecksum\n";
