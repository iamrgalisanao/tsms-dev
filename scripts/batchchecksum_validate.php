<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\PayloadChecksumService;

// Load the batch payload from file
$payloadJson = file_get_contents('corrected_batch_payload.json');
$payload = json_decode($payloadJson, true);

$service = new PayloadChecksumService();
$errors = [];

// Validate checksums for each transaction
foreach ($payload['transactions'] as $i => $txn) {
    $txnCopy = $txn;
    unset($txnCopy['payload_checksum']);
    $computedTxn = $service->computeChecksum($txnCopy);
    if (!isset($txn['payload_checksum']) || $txn['payload_checksum'] !== $computedTxn) {
        $errors[] = "Transaction {$txn['transaction_id']} has invalid payload_checksum (expected $computedTxn, got {$txn['payload_checksum']})";
    }
}

// Validate submission-level checksum
$submissionCopy = $payload;
unset($submissionCopy['payload_checksum']);
$computedSubmission = $service->computeChecksum($submissionCopy);
if (!isset($payload['payload_checksum']) || $payload['payload_checksum'] !== $computedSubmission) {
    $errors[] = "Submission has invalid payload_checksum (expected $computedSubmission, got {$payload['payload_checksum']})";
}

if (empty($errors)) {
    echo "All checksums are valid.\n";
} else {
    echo "Checksum validation errors:\n";
    foreach ($errors as $err) {
        echo "- $err\n";
    }
}
