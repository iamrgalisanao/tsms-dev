<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Services\PayloadChecksumService;

$payloadJson = <<<'JSON'
{
  "submission_uuid": "0cb8dd21-57af-4a74-bdf6-8f566c82933d",
  "tenant_id": 40,
  "terminal_id": 55,
  "submission_timestamp": "2026-03-16T13:14:17.681Z",
  "transaction_count": 1,
  "payload_checksum": "bb04edf4be76a4c79e17102b7d7d1d7ac41fdfa00cf3ea98630a4401e477fbdc",
  "transaction": {
    "transaction_id": "e5ffbee7-2270-425d-95a2-f0f5d099fb11",
    "transaction_timestamp": "2025-11-05T08:41:23Z",
    "gross_sales": 1499.00,
    "net_sales": 1499.00,
    "promo_status": "WITH_APPROVAL",
    "customer_code": "C-F1001",
    "payload_checksum": "123206b8c5ecb63f702e670c36783257a00a0f9e1ec2f529bbf30c81350bd7d5",
    "adjustments": [
      { "adjustment_type": "promo_discount", "amount": 0.00 },
      { "adjustment_type": "senior_discount", "amount": 0.00 },
      { "adjustment_type": "pwd_discount", "amount": 0.00 },
      { "adjustment_type": "vip_card_discount", "amount": 0.00 },
      { "adjustment_type": "service_charge_distributed_to_employees", "amount": 0.00 },
      { "adjustment_type": "service_charge_retained_by_management", "amount": 0.00 },
      { "adjustment_type": "employee_discount", "amount": 0.00 }
    ],
    "taxes": [
      { "tax_type": "VAT", "amount": 160.61 },
      { "tax_type": "VATABLE_SALES", "amount": 1338.39 },
      { "tax_type": "SC_VAT_EXEMPT_SALES", "amount": 0.00 },
      { "tax_type": "OTHER_TAX", "amount": 0.00 }
    ]
  }
}
JSON;

$data = json_decode($payloadJson, true);
$svc = new PayloadChecksumService();

echo "Running validation via PayloadChecksumService...\n";
$result = $svc->validateSubmissionChecksums($data);

if ($result['valid']) {
    echo "Payload is VALID according to PayloadChecksumService.\n";
} else {
    echo "Payload is INVALID.\n";
    foreach ($result['errors'] as $error) {
        echo " - ERROR: $error\n";
    }
}

echo "\nDetailed Checksm Calculation:\n";

// Transaction level
$txn = $data['transaction'];
$txnCopy = $txn;
unset($txnCopy['payload_checksum']);
$computedTxn = $svc->computeChecksum($txnCopy);
echo "Provided Transaction Checksum: " . ($txn['payload_checksum'] ?? 'MISSING') . "\n";
echo "Computed Transaction Checksum: " . $computedTxn . "\n";

if (($txn['payload_checksum'] ?? '') === $computedTxn) {
    echo "Result: Transaction Checksum MATCHES\n";
} else {
    echo "Result: Transaction Checksum MISMATCH\n";
}

// Submission level
$submissionCopy = $data;
unset($submissionCopy['payload_checksum']);
// IMPORTANT: Submission level uses the transaction object as it is in the payload (including its checksum)
$computedSubmission = $svc->computeChecksum($submissionCopy);
echo "\nProvided Submission Checksum: " . ($data['payload_checksum'] ?? 'MISSING') . "\n";
echo "Computed Submission Checksum: " . $computedSubmission . "\n";

if (($data['payload_checksum'] ?? '') === $computedSubmission) {
    echo "Result: Submission Checksum MATCHES\n";
} else {
    echo "Result: Submission Checksum MISMATCH\n";
}

echo "\nCanonicalized Data (Submission level - first 500 chars):\n";
$canonical = $svc->getCanonicalized($submissionCopy);
$encoded = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
echo substr($encoded, 0, 500) . "...\n";
