<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\PayloadChecksumService;

function generate_uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

$outDir = __DIR__ . '/../docs/canonical_vectors';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$service = new PayloadChecksumService();

echo "Generating 5 canonical envelope payloads into: $outDir\n";

for ($i = 1; $i <= 5; $i++) {
    $submissionUuid = generate_uuid_v4();
    $transactionId = generate_uuid_v4();
    $tenantId = 100 + $i; // vary tenant
    $terminalId = $i; // vary terminal

    // spaced timestamps so each file differs slightly
    $submissionTimestamp = gmdate('Y-m-d\TH:i:s\Z', time() - (60 * ($i - 1)));
    $transactionTimestamp = $submissionTimestamp;

    // small variety of amounts
    $gross = round(50 + ($i * 12.345), 2);
    $seniorDiscount = ($i % 2 === 0) ? 5.00 : 0.00;
    $net = round($gross - $seniorDiscount, 2);

    $payload = [
        'submission_uuid' => $submissionUuid,
        'tenant_id' => $tenantId,
        'terminal_id' => $terminalId,
        'submission_timestamp' => $submissionTimestamp,
        'transaction_count' => 1,
        // placeholder; will be replaced by computed value
        'payload_checksum' => 'PLACEHOLDER',
        'transaction' => [
            'transaction_id' => $transactionId,
            'transaction_timestamp' => $transactionTimestamp,
            'gross_sales' => $gross,
            'net_sales' => $net,
            'promo_status' => ($i % 3 === 0) ? 'WITHOUT_APPROVAL' : 'WITH_APPROVAL',
            'customer_code' => sprintf('C-%04d', $i),
            'payload_checksum' => 'PLACEHOLDER',
            'adjustments' => [
                ['adjustment_type' => 'promo_discount', 'amount' => 0.0],
                ['adjustment_type' => 'employee_discount', 'amount' => 0.0],
                ['adjustment_type' => 'senior_discount', 'amount' => $seniorDiscount],
                ['adjustment_type' => 'pwd_discount', 'amount' => 0.0],
                ['adjustment_type' => 'vip_card_discount', 'amount' => 0.0],
                ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0.0],
                ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0.0]
            ],
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => 0.0],
                ['tax_type' => 'VATABLE_SALES', 'amount' => 0.0],
                ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => round($net, 2)],
                ['tax_type' => 'OTHER_TAX', 'amount' => 0.0]
            ]
        ]
    ];

    // compute transaction checksum
    $txnCopy = $payload['transaction'];
    unset($txnCopy['payload_checksum']);
    $computedTxnChecksum = $service->computeChecksum($txnCopy);
    $payload['transaction']['payload_checksum'] = $computedTxnChecksum;

    // compute submission checksum
    $submissionCopy = $payload;
    unset($submissionCopy['payload_checksum']);
    $computedSubmissionChecksum = $service->computeChecksum($submissionCopy);
    $payload['payload_checksum'] = $computedSubmissionChecksum;

    $filename = sprintf('%s/envelope_pos_%d.json', $outDir, $i);
    file_put_contents($filename, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Wrote: $filename\n";
}

echo "Done.\n";
