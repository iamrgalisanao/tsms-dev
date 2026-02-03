<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Services\PayloadChecksumService;

$path = __DIR__ . '/../corrected_payload.json';
if (!file_exists($path)) {
    fwrite(STDERR, "File not found: {$path}\n");
    exit(2);
}

$json = file_get_contents($path);
$data = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "Invalid JSON in {$path}: " . json_last_error_msg() . "\n");
    exit(2);
}

$svc = new PayloadChecksumService();

// Compute transaction checksum
if (!isset($data['transaction']) || !is_array($data['transaction'])) {
    fwrite(STDERR, "No transaction found in payload\n");
    exit(2);
}

$txn = $data['transaction'];
$txnCopy = $txn;
unset($txnCopy['payload_checksum']);
$txnChecksum = $svc->computeChecksum($txnCopy);

// Compute submission checksum
$submissionCopy = $data;
unset($submissionCopy['payload_checksum']);
$submissionChecksum = $svc->computeChecksum($submissionCopy);

echo "TXN_CHECKSUM: {$txnChecksum}\n";
echo "SUB_CHECKSUM: {$submissionChecksum}\n";

// Exit 0
exit(0);
