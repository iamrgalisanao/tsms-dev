<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\PayloadChecksumService;

$payloadJson = file_get_contents('corrected_batch_payload.json');
$payload = json_decode($payloadJson, true);

$service = new PayloadChecksumService();

// Print canonicalized payload for debug
function print_canonical($label, $data, $service) {
    echo "\n--- $label ---\n";
    echo json_encode($service->getCanonicalized($data), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n";
}

// Show canonicalized transactions
foreach ($payload['transactions'] as $i => $txn) {
    print_canonical("Transaction {$txn['transaction_id']}", $txn, $service);
    $txnCopy = $txn;
    unset($txnCopy['payload_checksum']);
    $computedTxn = $service->computeChecksum($txnCopy);
    echo "Expected checksum: $computedTxn\n";
    echo "Actual checksum:   {$txn['payload_checksum']}\n";
}

// Show canonicalized submission
$submissionCopy = $payload;
unset($submissionCopy['payload_checksum']);
print_canonical('Submission', $submissionCopy, $service);
$computedSubmission = $service->computeChecksum($submissionCopy);
echo "Expected submission checksum: $computedSubmission\n";
echo "Actual submission checksum:   {$payload['payload_checksum']}\n";
