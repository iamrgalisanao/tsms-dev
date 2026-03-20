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
  "transaction": {
    "transaction_id": "e5ffbee7-2270-425d-95a2-f0f5d099fb11",
    "hardware_id": "8600025",
    "transaction_timestamp": "2025-11-05T08:41:23Z",
    "gross_sales": 1499.00,
    "net_sales": 1499.00,
    "promo_status": "WITH_APPROVAL",
    "customer_code": "C-F1001",
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

// Compute transaction checksum
$txn = $data['transaction'];
$txnChecksum = $svc->computeChecksum($txn);

// Compute submission checksum
$submission = $data;
$submission['transaction']['payload_checksum'] = $txnChecksum;
$submissionChecksum = $svc->computeChecksum($submission);

echo "NEW_TXN_CHECKSUM: $txnChecksum\n";
echo "NEW_SUB_CHECKSUM: $submissionChecksum\n";

$data['transaction']['payload_checksum'] = $txnChecksum;
$data['payload_checksum'] = $submissionChecksum;

echo "\n--- CORRECTED PAYLOAD ---\n";
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
