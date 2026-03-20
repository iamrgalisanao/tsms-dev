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

echo "Testing timestamp normalization...\n";

$targetSub = $data['payload_checksum'];
$submission = $data;
unset($submission['payload_checksum']);

$times = [
    "Original" => "2026-03-16T13:14:17.681Z",
    "No milliseconds" => "2026-03-16T13:14:17Z",
    "No Z" => "2026-03-16T13:14:17.681",
    "No ms No Z" => "2026-03-16T13:14:17",
];

foreach ($times as $label => $time) {
    $s = $submission;
    $s['submission_timestamp'] = $time;
    $checksum = $svc->computeChecksum($s);
    echo "[$label] ($time): $checksum " . ($checksum === $targetSub ? "[MATCH!]" : "") . "\n";
}

echo "\nTarget: $targetSub\n";

echo "\nTesting TXN timestamp normalization...\n";
$targetTxn = $data['transaction']['payload_checksum'];
$txn = $data['transaction'];
unset($txn['payload_checksum']);

$txnTimes = [
    "Original" => "2025-11-05T08:41:23Z",
    "No Z" => "2025-11-05T08:41:23",
];

foreach ($txnTimes as $label => $time) {
    $t = $txn;
    $t['transaction_timestamp'] = $time;
    $checksum = $svc->computeChecksum($t);
    echo "[$label] ($time): $checksum " . ($checksum === $targetTxn ? "[MATCH!]" : "") . "\n";
}

echo "\nTarget: $targetTxn\n";
