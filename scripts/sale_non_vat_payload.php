#!/usr/bin/env php
<?php
// CLI helper: sale_non_vat_payload.php
// Usage:
//   php scripts/sale_non_vat_payload.php
//   ./scripts/sale_non_vat_payload.php  (after `chmod +x scripts/sale_non_vat_payload.php`)

// Ensure composer autoload is available
if (! file_exists(__DIR__ . '/../vendor/autoload.php')) {
    fwrite(STDERR, "Error: vendor/autoload.php not found. Run 'composer install'.\n");
    exit(2);
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\PayloadChecksumService;

// Generate a new UUID v4 for submission/transaction
function generate_uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// CLI overrides
$transactionIdOverride = null;
$submissionTsOverride = null;
$transactionTsOverride = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--transaction-id=')) {
        $transactionIdOverride = substr($arg, strlen('--transaction-id='));
    }
    if (str_starts_with($arg, '--submission-timestamp=')) {
        $submissionTsOverride = substr($arg, strlen('--submission-timestamp='));
    }
    if (str_starts_with($arg, '--transaction-timestamp=')) {
        $transactionTsOverride = substr($arg, strlen('--transaction-timestamp='));
    }
}

$generatedTransactionId = $transactionIdOverride ?: generate_uuid_v4();
echo "Using transaction_id: $generatedTransactionId\n";

$nowUtc = gmdate('Y-m-d\TH:i:s\Z');
$submissionTimestamp = $submissionTsOverride ?: $nowUtc;
$transactionTimestamp = $transactionTsOverride ?: $submissionTimestamp;
echo "Using submission_timestamp: $submissionTimestamp\n";
echo "Using transaction_timestamp: $transactionTimestamp\n";

// Build a non-VAT sale payload: VAT = 0, exempt sales recorded under SC_VAT_EXEMPT_SALES
$payload = [
    'submission_uuid' => generate_uuid_v4(),
    'tenant_id' => 127,
    'terminal_id' => 25,
    'submission_timestamp' => $submissionTimestamp,
    'transaction_count' => 1,
    // placeholder, will be computed
    'payload_checksum' => '',
    'transaction' => [
        'transaction_id' => $generatedTransactionId,
        'transaction_timestamp' => $transactionTimestamp,
    'gross_sales' => 100.00,
    // For SC_VAT_EXEMPT_SALES equal to gross, the net_sales should be
    // gross - adjustments - exempt_tax = 0 to satisfy validation rules.
    'net_sales' => 0.00,
        'promo_status' => 'NONE',
    // customer_code is required by validation
    'customer_code' => 'C-TEST',
        // transaction-level placeholder checksum
        'payload_checksum' => '',
        // Ensure we include the full set of adjustments expected by validation
        'adjustments' => [
            ['adjustment_type' => 'promo_discount', 'amount' => 0.0],
            ['adjustment_type' => 'employee_discount', 'amount' => 0.0],
            ['adjustment_type' => 'senior_discount', 'amount' => 0.0],
            ['adjustment_type' => 'pwd_discount', 'amount' => 0.0],
            ['adjustment_type' => 'vip_card_discount', 'amount' => 0.0],
            ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0.0],
            ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0.0]
        ],
        'taxes' => [
            // VAT explicitly zero for non-VAT sale
            ['tax_type' => 'VAT', 'amount' => 0.0],
            // VATABLE_SALES zero
            ['tax_type' => 'VATABLE_SALES', 'amount' => 0.0],
            // Record the full gross under exempt sales so VAT is zero
            ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 100.00],
            ['tax_type' => 'OTHER_TAX', 'amount' => 0.0],
        ],
    ],
];

$service = new PayloadChecksumService();

echo "=== NON-VAT PAYLOAD CHECKSUM TEST ===\n\n";

// Compute transaction checksum
$txn = $payload['transaction'];
$txnCopy = $txn;
unset($txnCopy['payload_checksum']);
$computedTxnChecksum = $service->computeChecksum($txnCopy);
$payload['transaction']['payload_checksum'] = $computedTxnChecksum;

// Compute submission checksum
$submissionCopy = $payload;
unset($submissionCopy['payload_checksum']);
$computedSubmissionChecksum = $service->computeChecksum($submissionCopy);
$payload['payload_checksum'] = $computedSubmissionChecksum;

echo "COMPUTED CHECKSUMS:\n";
echo "Transaction: $computedTxnChecksum\n";
echo "Submission:  $computedSubmissionChecksum\n\n";

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

// Save to file for convenience
file_put_contents(__DIR__ . '/sale_non_vat_payload.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "✅ Saved payload to scripts/sale_non_vat_payload.json\n";

?>
