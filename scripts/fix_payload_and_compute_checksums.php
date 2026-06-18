<?php
require __DIR__ . '/../vendor/autoload.php';
use App\Services\PayloadChecksumService;

$raw = <<<'JSON'
{"submission_uuid":"d875555e-3f0c-42d9-9618-892ea9bfb2c5","tenant_id":42,"terminal_id":39,"submission_timestamp":"2025-11-05T14:19:21","transaction_count":1,"payload_checksum":"09be3e3fde223ae645699676e19b3d5afa32528f23ededfe35af47f0edff7e93","transaction":[{"transaction_id":"53b0478f-bb52-4e85-8e8c-e3ef7ce860d9","transaction_timestamp":"2025-11-05T12:00:48","receipt_no":"0000002935","gross_sales":1250.00,"net_sales":1250.00,"promo_status":"WITH APPROVAL","customer_code":"C-F1006","payload_checksum":"1d9dbfdbfe1dbbf27f29fabc7743068d0d62433137bea5df73710961b1eec8c4","adjustments":[{"adjustment_type":"promo_discount","amount":0.00},{"adjustment_type":"senior_discount","amount":0.00},{"adjustment_type":"pwd_discount","amount":0.00},{"adjustment_type":"vip_card_discount","amount":0.00},{"adjustment_type":"service_charge_distributed_to_employees","amount":0.00},{"adjustment_type":"service_charge_retained_by_management","amount":0.00},{"adjustment_type":"employee_discount","amount":0.00}],"taxes":[{"tax_type":"VAT","amount":133.93},{"tax_type":"VATABLE_SALES","amount":1116.07},{"tax_type":"SC_VAT_EXEMPT_SALES","amount":0.00},{"tax_type":"OTHER_TAX","amount":0.00}]}]}
JSON;

$data = json_decode($raw, true);
$svc = new PayloadChecksumService();

// Normalize submission timestamp to end with Z
if (isset($data['submission_timestamp']) && substr($data['submission_timestamp'], -1) !== 'Z') {
    $data['submission_timestamp'] = $data['submission_timestamp'] . 'Z';
}

// Fix transaction key shape: if transaction is array and there's 1 element, make it an object
if (isset($data['transaction']) && is_array($data['transaction']) && array_keys($data['transaction']) === range(0, count($data['transaction']) - 1)) {
    if (count($data['transaction']) === 1) {
        $txn = $data['transaction'][0];
        if (isset($txn['transaction_timestamp']) && substr($txn['transaction_timestamp'], -1) !== 'Z') {
            $txn['transaction_timestamp'] = $txn['transaction_timestamp'] . 'Z';
        }
        $data['transaction'] = $txn;
    } else {
        // multiple items -> convert to transactions array
        $data['transactions'] = $data['transaction'];
        unset($data['transaction']);
        foreach ($data['transactions'] as &$t) {
            if (isset($t['transaction_timestamp']) && substr($t['transaction_timestamp'], -1) !== 'Z') {
                $t['transaction_timestamp'] = $t['transaction_timestamp'] . 'Z';
            }
        }
        unset($t);
    }
}

// Ensure transactions timestamps end with Z
if (isset($data['transactions']) && is_array($data['transactions'])) {
    foreach ($data['transactions'] as &$t) {
        if (isset($t['transaction_timestamp']) && substr($t['transaction_timestamp'], -1) !== 'Z') {
            $t['transaction_timestamp'] = $t['transaction_timestamp'] . 'Z';
        }
    }
    unset($t);
}

// Compute per-transaction checksum(s)
if (isset($data['transaction']) && is_array($data['transaction'])) {
    $txnCopy = $data['transaction']; unset($txnCopy['payload_checksum']);
    $txnChecksum = $svc->computeChecksum($txnCopy);
    $data['transaction']['payload_checksum'] = $txnChecksum;
}
if (isset($data['transactions']) && is_array($data['transactions'])) {
    foreach ($data['transactions'] as $i => &$txn) {
        $txnCopy = $txn; unset($txnCopy['payload_checksum']);
        $txnChecksum = $svc->computeChecksum($txnCopy);
        $txn['payload_checksum'] = $txnChecksum;
    }
    unset($txn);
}

// Compute submission checksum
$submissionCopy = $data; unset($submissionCopy['payload_checksum']);
$submissionChecksum = $svc->computeChecksum($submissionCopy);
$data['payload_checksum'] = $submissionChecksum;

// Print corrected payload JSON
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

// Exit
exit(0);
