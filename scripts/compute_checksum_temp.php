<?php
// Temporary script to compute the correct checksum for a given transaction (batch mode)
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\PayloadChecksumService;

$txn = [
    "submission_uuid" => "e3b0c442-98fc-1c14-9afb-4c8996fb9242",
    "transaction_id" => "f47ac10b-58cc-4372-a567-0e02b2c3d529",
    "amount" => 1000.0,
    "validation_status" => "VALID",
    // checksum will be omitted for calculation
    "terminal_id" => 1,
    "tenant_code" => "C-F1005",
    "tenant_name" => "Binalot",
    "transaction_timestamp" => "2025-07-30T12:59:59.000Z",
    "processed_at" => "2025-07-30T13:00:00.000Z"
];

$service = new PayloadChecksumService();
$txnCopy = $txn;
unset($txnCopy["checksum"]); // In case it exists
unset($txnCopy["payload_checksum"]); // Accept both field names
$checksum = $service->computeChecksum($txnCopy);
echo $checksum . "\n";
