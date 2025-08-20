<?php
require_once __DIR__ . '/../vendor/autoload.php';
use App\Services\PayloadChecksumService;

$payload = json_decode(file_get_contents(__DIR__ . '/payload_to_check.json'), true);
$service = new PayloadChecksumService();

// Check transaction checksum
$txn = $payload['transaction'];
$txnCopy = $txn;
unset($txnCopy['payload_checksum']);
$txnChecksum = $service->computeChecksum($txnCopy);

// Check submission checksum (if present)
$submissionCopy = $payload;
unset($submissionCopy['payload_checksum']);
$submissionChecksum = $service->computeChecksum($submissionCopy);

// Output
printf("Transaction payload_checksum (expected): %s\n", $txn['payload_checksum'] ?? '');
printf("Transaction payload_checksum (computed): %s\n", $txnChecksum);
printf("Transaction checksum match: %s\n", ($txn['payload_checksum'] ?? '') === $txnChecksum ? 'YES' : 'NO');

if (isset($payload['payload_checksum'])) {
    printf("Submission payload_checksum (expected): %s\n", $payload['payload_checksum']);
    printf("Submission payload_checksum (computed): %s\n", $submissionChecksum);
    printf("Submission checksum match: %s\n", $payload['payload_checksum'] === $submissionChecksum ? 'YES' : 'NO');
}
