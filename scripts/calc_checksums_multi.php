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

if (isset($data['transaction']) && is_array($data['transaction'])) {
    // Single transaction path
    $txn = $data['transaction'];
    $txnCopy = $txn;
    unset($txnCopy['payload_checksum']);
    $txnChecksum = $svc->computeChecksum($txnCopy);
    echo "TXN_CHECKSUM: {$txnChecksum}\n";

    $submissionCopy = $data;
    unset($submissionCopy['payload_checksum']);
    $submissionChecksum = $svc->computeChecksum($submissionCopy);
    echo "SUB_CHECKSUM: {$submissionChecksum}\n";
    exit(0);
}

if (!isset($data['transactions']) || !is_array($data['transactions'])) {
    fwrite(STDERR, "No transactions found in payload\n");
    exit(2);
}

$computedTxns = [];
foreach ($data['transactions'] as $idx => $txn) {
    $txnCopy = $txn;
    unset($txnCopy['payload_checksum']);
    $txnChecksum = $svc->computeChecksum($txnCopy);
    $computedTxns[$idx] = $txnChecksum;
    echo "TXN[{$idx}]_CHECKSUM: {$txnChecksum}\n";
}

// Prepare submission copy with transaction payload_checksum set
$submissionCopy = $data;
foreach ($submissionCopy['transactions'] as $i => &$t) {
    $t['payload_checksum'] = $computedTxns[$i] ?? '';
}
unset($submissionCopy['payload_checksum']);
$submissionChecksum = $svc->computeChecksum($submissionCopy);
echo "SUB_CHECKSUM: {$submissionChecksum}\n";

exit(0);
