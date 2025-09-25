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
require_once __DIR__ . '/_timestamp.php';

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
$submissionUuidOverride = null;
$useLocalDate = false;
$useLocalZ = false;
$tzOverride = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--transaction-id=')) {
        $transactionIdOverride = substr($arg, strlen('--transaction-id='));
    }
    if (str_starts_with($arg, '--submission-timestamp=')) {
        $submissionTsOverride = substr($arg, strlen('--submission-timestamp='));
    }
    if (str_starts_with($arg, '--submission-uuid=')) {
        $submissionUuidOverride = substr($arg, strlen('--submission-uuid='));
    }
    if (str_starts_with($arg, '--transaction-timestamp=')) {
        $transactionTsOverride = substr($arg, strlen('--transaction-timestamp='));
    }
    // New flag: --local-date causes the generator to emit local ISO-8601 timestamps
    // instead of canonical UTC timestamps. Presence of the flag (no value needed)
    // toggles local mode. Example: php scripts/sale_non_vat_payload.php --local-date
    if ($arg === '--local-date' || str_starts_with($arg, '--local-date=')) {
        $useLocalDate = true;
    }
    // New flag: --local-z emits the local clock time but appends a literal 'Z'.
    // Example: TSMS_TIMEZONE=Asia/Manila php scripts/sale_non_vat_payload.php --local-z
    if ($arg === '--local-z' || str_starts_with($arg, '--local-z=')) {
        $useLocalZ = true;
    }
    if (str_starts_with($arg, '--tz=')) {
        $tzOverride = substr($arg, strlen('--tz='));
    }
}

// Generate or honour provided identifiers
$generatedTransactionId = $transactionIdOverride ?: 'd4768fc3-2177-c96e-db5f-50cf098d1f5b';
$submissionUuid = $submissionUuidOverride ?: '30b5251d-899c-456c-b0b3-18720e815973';
echo "Using transaction_id: $generatedTransactionId\n";

// Timezone handling: prefer CLI --tz flag, then environment TSMS_TIMEZONE/TZ,
// then PHP default. This ensures explicit CLI choice overrides env vars.
$preferredTz = $tzOverride ?: (getenv('TSMS_TIMEZONE') ?: getenv('TZ'));
if (!empty($preferredTz)) {
    // Attempt to set the PHP default timezone to the provided value.
    date_default_timezone_set($preferredTz);
    if (!empty($tzOverride)) {
        echo "Using timezone from --tz flag: $preferredTz\n";
    } else {
        echo "Using timezone from environment: $preferredTz\n";
    }
} else {
    echo "Using PHP default timezone: " . date_default_timezone_get() . "\n";
}

// Determine timestamp generation mode: default is UTC for canonical payloads.
// If --local-date is passed, emit local ISO-8601 timestamps and honour
// TSMS_TIMEZONE or TZ environment variables if present.
$submissionTimestamp = null;
if ($useLocalDate) {
    $preferredTz = $tzOverride ?: (getenv('TSMS_TIMEZONE') ?: getenv('TZ'));
    if (!empty($preferredTz)) {
        // Try to set the provided timezone (e.g., 'Asia/Manila').
        date_default_timezone_set($preferredTz);
        if (!empty($tzOverride)) {
            echo "Using timezone from --tz flag: $preferredTz\n";
        } else {
            echo "Using timezone from environment: $preferredTz\n";
        }
    } else {
        echo "Using PHP default timezone: " . date_default_timezone_get() . "\n";
    }

    if ($submissionTsOverride) {
        $submissionTimestamp = $submissionTsOverride;
    } else {
        $submissionTimestamp = date('c');
    }

    $transactionTimestamp = $transactionTsOverride ?: date('c');
    echo "Using local-date mode (--local-date). Timestamps are local ISO-8601.\n";
} elseif ($useLocalZ) {
    // Local clock time but with a literal 'Z' suffix. WARNING: this labels
    // local time as 'Z' (UTC) which is technically incorrect but requested.
    $preferredTz = $tzOverride ?: (getenv('TSMS_TIMEZONE') ?: getenv('TZ'));
    if (!empty($preferredTz)) {
        date_default_timezone_set($preferredTz);
        if (!empty($tzOverride)) {
            echo "Using timezone from --tz flag: $preferredTz\n";
        } else {
            echo "Using timezone from environment: $preferredTz\n";
        }
    } else {
        echo "Using PHP default timezone: " . date_default_timezone_get() . "\n";
    }

    if ($submissionTsOverride) {
        $submissionTimestamp = $submissionTsOverride;
    } else {
        // Format local time then append literal Z
        $submissionTimestamp = date('Y-m-d\\TH:i:s') . 'Z';
    }

    $transactionTimestamp = $transactionTsOverride ?: (date('Y-m-d\\TH:i:s') . 'Z');
    echo "Using local-z mode (--local-z). Local clock used but timestamps end with 'Z'.\n";
} else {
    // Canonical behavior: UTC timestamps in strict Z format for saved payloads.
    if ($submissionTsOverride) {
        $submissionTimestamp = $submissionTsOverride;
    } else {
        $submissionTimestamp = gmdate('Y-m-d\\TH:i:s\\Z', time());
    }

    $transactionTimestamp = $transactionTsOverride ?: gmdate('Y-m-d\\TH:i:s\\Z', time());
    echo "Using UTC timestamps for canonical payloads.\n";
}
echo "Using submission_timestamp: $submissionTimestamp\n";
echo "Using transaction_timestamp: $transactionTimestamp\n";

// Build a non-VAT sale payload using the provided canonical example as defaults.
// Values can still be overridden via CLI flags (e.g., --transaction-id, --submission-timestamp).
$payload = [
    // Use provided submission_uuid or canonical default
    'submission_uuid' => $submissionUuid,
    'tenant_id' => 113,
    'terminal_id' => 1,
    // default to the canonical submission timestamp unless overridden
    'submission_timestamp' => $submissionTimestamp,
    'transaction_count' => 1,
    // placeholder, will be computed below
    'payload_checksum' => '',
    'transaction' => [
    'transaction_id' => $generatedTransactionId,
    'transaction_timestamp' => $transactionTimestamp,
    'processed_at' => $transactionTimestamp ?? '2025-09-22T13:52:23Z',
    'gross_sales' => 100,
    'net_sales' => 82.14,
        'promo_status' => 'WITH_APPROVAL',
        'customer_code' => 'C-TEST',
        // transaction-level placeholder checksum; will be computed
        'payload_checksum' => '',
        'adjustments' => [
            ['adjustment_type' => 'promo_discount', 'amount' => 0.0],
            ['adjustment_type' => 'employee_discount', 'amount' => 0.0],
            ['adjustment_type' => 'senior_discount', 'amount' => 17.86],
            ['adjustment_type' => 'pwd_discount', 'amount' => 0.0],
            ['adjustment_type' => 'vip_card_discount', 'amount' => 0.0],
            ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0.0],
            ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0.0]
        ],
        'taxes' => [
            ['tax_type' => 'VAT', 'amount' => 0],
            ['tax_type' => 'VATABLE_SALES', 'amount' => 0],
            ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 89.29],
            ['tax_type' => 'OTHER_TAX', 'amount' => 0],
        ],
    ],
];

$service = new PayloadChecksumService();

echo "=== NON-VAT PAYLOAD CHECKSUM TEST ===\n\n";

// Using canonical payload values: do not alter net/taxes here; keep user's tax structure.
// Compute transaction checksum excluding SC_VAT_EXEMPT_SALES (preserve checksum contract).
// We will compute the transaction checksum from a copy with SC_VAT_EXEMPT_SALES removed.
// (submission checksum is computed on the full payload below)

// Compute transaction checksum
$txn = $payload['transaction'];
$txnCopy = $txn;
unset($txnCopy['payload_checksum']);
// Remove SC_VAT_EXEMPT_SALES tax entries from copy before per-transaction checksum
if (!empty($txnCopy['taxes']) && is_array($txnCopy['taxes'])) {
    $txnCopy['taxes'] = array_values(array_filter($txnCopy['taxes'], function($t) {
        return strtoupper(trim($t['tax_type'] ?? '')) !== 'SC_VAT_EXEMPT_SALES';
    }));
}
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
