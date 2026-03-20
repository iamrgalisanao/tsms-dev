<?php
require_once __DIR__ . '/../vendor/autoload.php';
use App\Services\PayloadChecksumService;

// Accept payload from file or stdin
$input = $argc > 1 ? file_get_contents($argv[1]) : stream_get_contents(STDIN);
$payload = json_decode($input, true);

if (!$payload) {
    echo "Invalid JSON input.\n";
    exit(1);
}

$service = new PayloadChecksumService();

// Compute transaction checksum
$txn = $payload['transaction'];
$txnCopy = $txn;
unset($txnCopy['payload_checksum']);
$txnChecksum = $service->computeChecksum($txnCopy);

// Compute submission checksum
$payloadCopy = $payload;
unset($payloadCopy['payload_checksum']);
$payloadCopy['transaction']['payload_checksum'] = $txnChecksum;
$submissionChecksum = $service->computeChecksum($payloadCopy);

// Check validity
$isTxnValid = isset($txn['payload_checksum']) && $txn['payload_checksum'] === $txnChecksum;
$isSubmissionValid = isset($payload['payload_checksum']) && $payload['payload_checksum'] === $submissionChecksum;

if ($isTxnValid && $isSubmissionValid) {
    echo "Payload is valid.\n";
    exit(0);
}

// Output corrected payload
$payload['transaction']['payload_checksum'] = $txnChecksum;
$payload['payload_checksum'] = $submissionChecksum;
echo "Payload is invalid. Here is the corrected payload:\n";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(0);