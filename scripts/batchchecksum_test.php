<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\PayloadChecksumService;

// Example batch payload with multiple transactions
$payload = [
    "submission_uuid" => "e3b0c442-98fc-1c14-9afb-4c8996fb9242",
    "tenant_id" => 125,
    "terminal_id" => 1,
    "submission_timestamp" => "2025-07-19T12:00:00Z",
    "transaction_count" => 2,
    // "payload_checksum" => "...", // will be computed
    "transactions" => [
        [
            "transaction_id" => "f47ac10b-58cc-4372-a567-0e02b2c3d529",
            "transaction_timestamp" => "2025-07-19T12:00:01Z",
            "base_amount" => 1000.0,
            // "payload_checksum" => "...", // will be computed
            "adjustments" => [
                [ "adjustment_type" => "promo_discount", "amount" => 50.0 ],
                [ "adjustment_type" => "senior_discount", "amount" => 20.0 ]
            ],
            "taxes" => [
                [ "tax_type" => "VAT", "amount" => 120.0 ],
                [ "tax_type" => "OTHER_TAX", "amount" => 10.0 ]
            ]
        ],
        [
            "transaction_id" => "f47ac10b-58cc-4372-a567-0e02b2c3d530",
            "transaction_timestamp" => "2025-07-19T12:05:01Z",
            "base_amount" => 500.0,
            // "payload_checksum" => "...", // will be computed
            "adjustments" => [
                [ "adjustment_type" => "promo_discount", "amount" => 50.0 ],
                [ "adjustment_type" => "senior_discount", "amount" => 20.0 ]
            ],
            "taxes" => [
                [ "tax_type" => "VAT", "amount" => 120.0 ],
                [ "tax_type" => "OTHER_TAX", "amount" => 10.0 ]
            ]
        ]
    ]
];

$service = new PayloadChecksumService();

// Compute checksums for each transaction
foreach ($payload["transactions"] as $i => $txn) {
    $txnCopy = $txn;
    unset($txnCopy["payload_checksum"]);
    $txnChecksum = $service->computeChecksum($txnCopy);
    $payload["transactions"][$i]["payload_checksum"] = $txnChecksum;
}

// Compute submission checksum
$submissionCopy = $payload;
unset($submissionCopy["payload_checksum"]);
$submissionChecksum = $service->computeChecksum($submissionCopy);
$payload["payload_checksum"] = $submissionChecksum;

// Output the corrected batch payload
file_put_contents('corrected_batch_payload.json', json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "Batch submission payload_checksum: $submissionChecksum\n";
foreach ($payload["transactions"] as $txn) {
    echo "Transaction {$txn["transaction_id"]} payload_checksum: {$txn["payload_checksum"]}\n";
}
