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

echo "Testing permutations to find matching checksums...\n\n";

$txn = $data['transaction'];
unset($txn['payload_checksum']);

$permutations = [
    "Original" => $txn,
    "No promo_status" => (function($t) { unset($t['promo_status']); return $t; })($txn),
    "No customer_code" => (function($t) { unset($t['customer_code']); return $t; })($txn),
    "No promo_status & customer_code" => (function($t) { unset($t['promo_status'], $t['customer_code']); return $t; })($txn),
    "No adjustments" => (function($t) { unset($t['adjustments']); return $t; })($txn),
    "No taxes" => (function($t) { unset($t['taxes']); return $t; })($txn),
];

$targetTxn = $data['transaction']['payload_checksum'];

foreach ($permutations as $label => $p) {
    $checksum = $svc->computeChecksum($p);
    echo "[$label]: $checksum " . ($checksum === $targetTxn ? "[MATCH!]" : "") . "\n";
}

echo "\nTarget TXN Checksum: $targetTxn\n";

echo "\n--- Submssion Level Permutations ---\n";
// In submission, we use the transaction object with PROVIDED checksum
$submission = $data;
unset($submission['payload_checksum']);
$targetSub = $data['payload_checksum'];

$subPermutations = [
    "Original (with provided txn checksum)" => $submission,
    "No transaction_count" => (function($s) { unset($s['transaction_count']); return $s; })($submission),
    "No submission_timestamp" => (function($s) { unset($s['submission_timestamp']); return $s; })($submission),
];

foreach ($subPermutations as $label => $s) {
    $checksum = $svc->computeChecksum($s);
    echo "[$label]: $checksum " . ($checksum === $targetSub ? "[MATCH!]" : "") . "\n";
}

echo "\nTarget SUB Checksum: $targetSub\n";
