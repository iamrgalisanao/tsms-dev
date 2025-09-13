<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\PayloadChecksumService;

// Payload from 2025-08-13_vatseq_4986.json
// Generate a new UUID for submission_uuid
function generate_uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // set version to 0100
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // set bits 6-7 to 10
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// Allow optional CLI override: --transaction-id=UUID
$transactionIdOverride = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--transaction-id=')) {
        $transactionIdOverride = substr($arg, strlen('--transaction-id='));
    }
}

$generatedTransactionId = $transactionIdOverride ?: generate_uuid_v4();
echo "Using transaction_id: $generatedTransactionId\n";

// Auto-generate current UTC timestamps (ISO 8601 with trailing Z) unless overridden
$submissionTsOverride = null;
$transactionTsOverride = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--submission-timestamp=')) {
        $submissionTsOverride = substr($arg, strlen('--submission-timestamp='));
    } elseif (str_starts_with($arg, '--transaction-timestamp=')) {
        $transactionTsOverride = substr($arg, strlen('--transaction-timestamp='));
    }
}

$nowUtc = gmdate('Y-m-d\TH:i:s\Z');
$submissionTimestamp = $submissionTsOverride ?: $nowUtc;
// Default transaction timestamp same as submission unless override provided
$transactionTimestamp = $transactionTsOverride ?: $submissionTimestamp;
echo "Using submission_timestamp: $submissionTimestamp\n";
echo "Using transaction_timestamp: $transactionTimestamp\n";

$payload = [
    "submission_uuid" => generate_uuid_v4(),
    "tenant_id" => 125,
    "terminal_id" => 1,
    "submission_timestamp" => $submissionTimestamp,
    "transaction_count" => 1,
    "payload_checksum" => "d7a287d75aff93e17ff6f70b8552094bafd0301487680d5782e7e4be44eeccf6",
    "transaction" => [
        // Auto-generated per run unless --transaction-id provided
        "transaction_id" => $generatedTransactionId,
    "transaction_timestamp" => $transactionTimestamp,
    // Removed base_amount; using gross_sales + net_sales per current schema
    "gross_sales" => 465.0,
    "net_sales" => 415.18,
        "promo_status" => "WITH_APPROVAL",
        "customer_code" => "C-C1045",
        "payload_checksum" => "348ff61ab23bf37e7f4160da493b014bf5abd9b75c7e292f093630f3ac3abec6",
        "adjustments" => [
            ["adjustment_type" => "promo_discount", "amount" => 0.0],
            ["adjustment_type" => "employee_discount", "amount" => 0.0],
            ["adjustment_type" => "senior_discount", "amount" => 0.0],
            ["adjustment_type" => "pwd_discount", "amount" => 0.0],
            ["adjustment_type" => "vip_card_discount", "amount" => 0.0],
            ["adjustment_type" => "service_charge_distributed_to_employees", "amount" => 0.0],
            ["adjustment_type" => "service_charge_retained_by_management", "amount" => 0.0]
        ],
        "taxes" => [
            ["tax_type" => "VAT", "amount" => 49.82],
            ["tax_type" => "VATABLE_SALES", "amount" => 415.18],
            ["tax_type" => "SC_VAT_EXEMPT_SALES", "amount" => 0.0],
            ["tax_type" => "OTHER_TAX", "amount" => 0.0]
        ]
    ]
];

$service = new PayloadChecksumService();

echo "=== CHECKSUM VALIDATION TEST ===\n\n";

// Store original checksums for comparison
$originalTxnChecksum = $payload["transaction"]["payload_checksum"];
$originalSubmissionChecksum = $payload["payload_checksum"];

echo "ORIGINAL CHECKSUMS:\n";
echo "Transaction: $originalTxnChecksum\n";
echo "Submission:  $originalSubmissionChecksum\n\n";

// Compute transaction checksum
$txn = $payload["transaction"];
$txnCopy = $txn;
unset($txnCopy["payload_checksum"]);
$computedTxnChecksum = $service->computeChecksum($txnCopy);

// Update payload with computed transaction checksum
$payload["transaction"]["payload_checksum"] = $computedTxnChecksum;

// Compute submission checksum
$submissionCopy = $payload;
unset($submissionCopy["payload_checksum"]);
$computedSubmissionChecksum = $service->computeChecksum($submissionCopy);

echo "COMPUTED CHECKSUMS:\n";
echo "Transaction: $computedTxnChecksum\n";
echo "Submission:  $computedSubmissionChecksum\n\n";

// Compare results
$txnMatch = $originalTxnChecksum === $computedTxnChecksum;
$submissionMatch = $originalSubmissionChecksum === $computedSubmissionChecksum;

echo "VALIDATION RESULTS:\n";
echo "Transaction checksum match: " . ($txnMatch ? "✅ VALID" : "❌ INVALID") . "\n";
echo "Submission checksum match:  " . ($submissionMatch ? "✅ VALID" : "❌ INVALID") . "\n\n";

if (!$txnMatch || !$submissionMatch) {
    echo "CORRECTED PAYLOAD:\n";
    $payload["payload_checksum"] = $computedSubmissionChecksum;
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    
    // Save corrected payload
    file_put_contents('corrected_4986_payload.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "✅ Corrected payload saved to 'corrected_4986_payload.json'\n";
} else {
    echo "✅ PAYLOAD IS VALID - No corrections needed!\n";
}
