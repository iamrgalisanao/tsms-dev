<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\PayloadChecksumService;

// Your original payload (without the submission checksum)
$payload = [
    "submission_uuid" => "807b61f5-1a49-42c1-9e42-dd0d197b4207",
    "tenant_id" => 125,
    "terminal_id" => 1,
    "submission_timestamp" => "2025-07-31T10:59:00Z",
    "transaction_count" => 1,
    "transaction" => [
        "transaction_id" => "9861431d-afa9-4415-a7c8-f8d52b26bffd",
        "transaction_timestamp" => "2025-07-31T10:59:00Z",
        "base_amount" => 12345.67,
        "payload_checksum" => "766094ff89bbada4e091af84890a1b9cc5c419b1e64da03da13dbd8475ea2150",
        "adjustments" => [
            ["adjustment_type" => "promo_discount", "amount" => 150],
            ["adjustment_type" => "employee_discount", "amount" => 50],
            ["adjustment_type" => "senior_discount", "amount" => 75],
            ["adjustment_type" => "pwd_discount", "amount" => 30],
            ["adjustment_type" => "vip_card_discount", "amount" => 20],
            ["adjustment_type" => "service_charge_distributed_to_employees", "amount" => 120],
            ["adjustment_type" => "service_charge_retained_by_management", "amount" => 80]
        ],
        "taxes" => [
            ["tax_type" => "VAT", "amount" => 1200],
            ["tax_type" => "VATABLE_SALES", "amount" => 10000],
            ["tax_type" => "SC_VAT_EXEMPT_SALES", "amount" => 2000],
            ["tax_type" => "OTHER_TAX", "amount" => 100]
        ]
    ]
];

$checksumService = new PayloadChecksumService();

// First, let's verify the transaction checksum is correct
$transaction = $payload['transaction'];
$transactionCopy = $transaction;
unset($transactionCopy['payload_checksum']);
$computedTransactionChecksum = $checksumService->computeChecksum($transactionCopy);

echo "=== TRANSACTION CHECKSUM VERIFICATION ===\n";
echo "Provided transaction checksum: " . $transaction['payload_checksum'] . "\n";
echo "Computed transaction checksum: " . $computedTransactionChecksum . "\n";
echo "Transaction checksum match: " . ($transaction['payload_checksum'] === $computedTransactionChecksum ? "✅ YES" : "❌ NO") . "\n\n";

// Now compute the submission-level checksum
$submissionCopy = $payload;
// Note: No payload_checksum to unset since it's missing (that's the problem!)

$computedSubmissionChecksum = $checksumService->computeChecksum($submissionCopy);

echo "=== SUBMISSION CHECKSUM CALCULATION ===\n";
echo "Missing submission checksum (this is the problem!)\n";
echo "Computed submission checksum: " . $computedSubmissionChecksum . "\n\n";

// Add the submission checksum to the payload
$payload['payload_checksum'] = $computedSubmissionChecksum;

echo "=== CORRECTED PAYLOAD ===\n";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "=== SUMMARY ===\n";
echo "The issue: Your payload was missing the top-level 'payload_checksum' field.\n";
echo "The solution: Add the computed submission checksum to your payload.\n";
echo "Now your payload has both:\n";
echo "1. transaction.payload_checksum (validates individual transaction)\n";
echo "2. payload_checksum (validates entire submission)\n";
