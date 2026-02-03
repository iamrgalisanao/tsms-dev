<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Services\PayloadChecksumService;

$raw = <<<'JSON'
{"submission_uuid":"655390c7-d1d5-4f81-8473-5d8f013bdcfe","tenant_id":34,"terminal_id":12,"submission_timestamp":"2025-12-22T10:17:59Z","transaction_count":1,"payload_checksum":"ccecabb91f3483f5185cbec3dabe983972c5d98834308b811ff4e5a3395965a6","transaction":{"transaction_id":"655390c7-d1d5-4f81-8473-5d8f013bdcfe","transaction_timestamp":"2025-11-27T18:33:47Z","gross_sales":200.00,"net_sales":200.00,"promo_status":"WITH_APPROVAL","customer_code":"C-E1001","payload_checksum":"6f9314e6e6e96ed5e6378e734316f67a9443f4681173afdd217dbf9669d2d477","adjustments":[{"adjustment_type":"promo_discount","amount":0.00},{"adjustment_type":"senior_discount","amount":0.00},{"adjustment_type":"pwd_discount","amount":0.00},{"adjustment_type":"vip_card_discount","amount":0.00},{"adjustment_type":"service_charge_distributed_to_employees","amount":0.00},{"adjustment_type":"service_charge_retained_by_management","amount":0.00},{"adjustment_type":"employee_discount","amount":0.00}],"taxes":[{"tax_type":"VAT","amount":21.43},{"tax_type":"VATABLE_SALES","amount":200.00},{"tax_type":"SC_VAT_EXEMPT_SALES","amount":0.00},{"tax_type":"OTHER_TAX","amount":0.00}]}}
JSON;

$svc = new PayloadChecksumService();

$submission = json_decode($raw, true);

// Compute transaction checksum as service would (remove payload_checksum)
$txn = $submission['transaction'];
if (isset($txn['payload_checksum'])) unset($txn['payload_checksum']);
$txnComputed = $svc->computeChecksum($txn);

// Compute submission checksum as service would (remove payload_checksum)
$submissionCopy = $submission;
if (isset($submissionCopy['payload_checksum'])) unset($submissionCopy['payload_checksum']);
$submissionComputed = $svc->computeChecksum($submissionCopy);

echo "Computed transaction payload_checksum: $txnComputed\n";
echo "Incoming transaction payload_checksum: " . ($submission['transaction']['payload_checksum'] ?? 'missing') . "\n\n";
echo "Computed submission payload_checksum: $submissionComputed\n";
echo "Incoming submission payload_checksum: " . ($submission['payload_checksum'] ?? 'missing') . "\n";

// Also show canonicalized JSON for inspection
echo "\nCanonicalized transaction JSON:\n";
echo json_encode($svc->getCanonicalized($txn), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

echo "\nCanonicalized submission JSON:\n";
echo json_encode($svc->getCanonicalized($submissionCopy), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

exit(0);
