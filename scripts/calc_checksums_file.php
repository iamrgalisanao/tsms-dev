<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Services\PayloadChecksumService;

$file = $argv[1] ?? __DIR__ . '/../corrected_payload.json';
if (!file_exists($file)) {
    fwrite(STDERR, "File not found: {$file}\n");
    exit(2);
}

$json = file_get_contents($file);
$data = json_decode($json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "Invalid JSON in {$file}: " . json_last_error_msg() . "\n");
    exit(2);
}

$svc = new PayloadChecksumService();

if (!isset($data['transaction']) || !is_array($data['transaction'])) {
    fwrite(STDERR, "No transaction found in payload\n");
    exit(2);
}

$txn = $data['transaction'];
$txnCopy = $txn;
unset($txnCopy['payload_checksum']);
$txnChecksum = $svc->computeChecksum($txnCopy);

$submissionCopy = $data;
unset($submissionCopy['payload_checksum']);
// ensure transaction payload_checksum is set in submission copy
$submissionCopy['transaction'] = $txnCopy;
$submissionChecksum = $svc->computeChecksum($submissionCopy);

echo "TXN_CHECKSUM: {$txnChecksum}\n";
echo "SUB_CHECKSUM: {$submissionChecksum}\n";

exit(0);
