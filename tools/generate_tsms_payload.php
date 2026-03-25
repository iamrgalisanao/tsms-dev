<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/**
 * TSMS Transaction Payload Generator (Interactive)
 * 
 * This tool allows developers to quickly generate a valid, checksum-compliant
 * transaction payload for the TSMS V2.1 API.
 * 
 * Usage: php tools/generate_tsms_payload.php
 */

// Bootstrap Laravel to access App\Services\PayloadChecksumService and other utilities
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\PayloadChecksumService;
use Illuminate\Support\Str;
use Carbon\Carbon;

function prompt(string $message, string $default = '', bool $required = false): string {
    $displayText = $default ? "\033[1;33m{$message}\033[0m [\033[0;32m{$default}\033[0m]: " : "\033[1;33m{$message}\033[0m: ";
    
    while (true) {
        echo $displayText;
        $input = trim(fgets(STDIN) ?: '');
        
        if ($input === '' && $default !== '') {
            return $default;
        }
        
        if ($input === '' && $required) {
            echo "\033[0;31mError: This field is required.\033[0m\n";
            continue;
        }
        
        return $input;
    }
}

echo "\n\033[1;36m====================================================\033[0m\n";
echo "\033[1;36m           TSMS PAYLOAD GENERATOR V2.1              \033[0m\n";
echo "\033[1;36m====================================================\033[0m\n\n";

// 1. Interactive Inputs
$transaction_id = prompt("1. Enter transaction_id (UUID)", (string) Str::uuid(), true);
$terminal_id = (int) prompt("2. Enter terminal_id", "55");
$hardware_id = prompt("3. Enter hardware_id", "POS-TERM-01", true);
$tenant_id = (int) prompt("4. Enter tenant_id", "40");

// 2. Auto-generate Metadata
$submission_uuid = (string) Str::uuid();
$now = Carbon::now()->toIso8601ZuluString();
$receipt_no = 'R-' . date('Ymd') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);

// 3. Build Preliminary Payload Structure
$payload = [
    'submission_uuid' => $submission_uuid,
    'tenant_id' => $tenant_id,
    'terminal_id' => $terminal_id,
    'submission_timestamp' => $now,
    'transaction_count' => 1,
    'transaction' => [
        'transaction_id' => $transaction_id,
        'hardware_id' => $hardware_id,
        'receipt_no' => $receipt_no,
        'transaction_timestamp' => $now,
        'gross_sales' => "1499.00",
        'net_sales' => "1499.00",
        'promo_status' => "WITH_APPROVAL",
        'customer_code' => "C-F1001",
        'adjustments' => [
            ['adjustment_type' => 'promo_discount', 'amount' => '0.00'],
            ['adjustment_type' => 'senior_discount', 'amount' => '0.00'],
            ['adjustment_type' => 'pwd_discount', 'amount' => '0.00'],
            ['adjustment_type' => 'vip_card_discount', 'amount' => '0.00'],
            ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => '0.00'],
            ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => '0.00'],
            ['adjustment_type' => 'employee_discount', 'amount' => '0.00']
        ],
        'taxes' => [
            ['tax_type' => 'VAT', 'amount' => '160.61'],
            ['tax_type' => 'VATABLE_SALES', 'amount' => '1338.39'],
            ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => '0.00'],
            ['tax_type' => 'OTHER_TAX', 'amount' => '0.00']
        ]
    ]
];

// 4. Compute Checksums (Industry Standard Double-Layer)
$checksumService = new PayloadChecksumService();

try {
    // Step A: Transaction-level Hash
    $txnCopy = $payload['transaction'];
    $payload['transaction']['payload_checksum'] = $checksumService->computeChecksum($txnCopy);

    // Step B: Submission-level Hash (must include the transaction hash computed above)
    $subCopy = $payload;
    $payload['payload_checksum'] = $checksumService->computeChecksum($subCopy);

    echo "\n\033[1;32mSUCCESS: Payload generated successfully!\033[0m\n";
    
    // 5. Output Results
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    echo "\n\033[1;35m--- FINAL PAYLOAD ---\033[0m\n";
    echo $json . "\n";
    
    // 6. Save to file
    $outputPath = __DIR__ . '/generated_payload.json';
    file_put_contents($outputPath, $json);
    
    echo "\n\033[1;33mSaved to:\033[0m {$outputPath}\n";
    echo "\033[1;33mUsage:\033[0m curl -X POST -H \"Content-Type: application/json\" -H \"Authorization: Bearer <TOKEN>\" -d @tools/generated_payload.json https://stagingtsms.pitx.com.test/api/v1/transactions/official\n\n";

} catch (\Throwable $e) {
    echo "\n\033[1;31mERROR: Failed to generate checksums.\033[0m\n";
    echo $e->getMessage() . "\n";
    exit(1);
}
