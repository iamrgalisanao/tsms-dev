<?php

/**
 * TSMS Independent JSON to Excel Extraction Tool
 * 
 * This tool processes multiple transaction JSON files from a directory 
 * and extracts key financial data into a single Excel (.xlsx) workbook.
 * 
 * Usage: php tools/extract_payload_details.php --dir=./path/to/json --out=output.xlsx
 */

// Use the existing vendor autoload for PhpSpreadsheet
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die("Error: vendor/autoload.php not found. Please run 'composer install' first.\n");
}
require $autoloadPath;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// --- CLI Argument Parsing ---
$options = getopt("", ["dir:", "out:"]);
$inputDir = $options['dir'] ?? null;
$outputFile = $options['out'] ?? 'extracted_transactions_' . date('Ymd_His') . '.xlsx';

if (!$inputDir || !is_dir($inputDir)) {
    echo "Usage: php tools/extract_payload_details.php --dir=PATH [--out=OUTPUT.xlsx]\n";
    echo "Example: php tools/extract_payload_details.php --dir=./storage/payloads\n";
    exit(1);
}

// --- Data Extraction ---
echo "Scanning directory: $inputDir...\n";
$jsonFiles = glob(rtrim($inputDir, '/') . '/*.json');

if (empty($jsonFiles)) {
    die("No .json files found in the specified directory.\n");
}

$dataRows = [];
$headers = [
    'Filename',
    'Transaction ID',
    'Receipt No',
    'Timestamp',
    'Gross Sales',
    'Vatable Sales',
    'VAT Amount',
    'Exempt Sales',
    'Senior Disc',
    'PWD Disc',
    'Promo Disc',
    'Net Sales',
    'Service Charge'
];

foreach ($jsonFiles as $file) {
    echo "Processing: " . basename($file) . "\n";
    $content = file_get_contents($file);
    $json = json_decode($content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "  [SKIP] Invalid JSON: " . basename($file) . "\n";
        continue;
    }

    // Extracting fields (supporting nested payloads or flat structures)
    $tx = $json['transaction'] ?? $json; 
    
    // Parse Taxes
    $vatableSales = 0;
    $vatAmount = 0;
    $exemptSales = 0;
    if (isset($tx['taxes']) && is_array($tx['taxes'])) {
        foreach ($tx['taxes'] as $tax) {
            $type = strtoupper($tax['tax_type'] ?? '');
            $amount = (float)($tax['amount'] ?? 0);
            if ($type === 'VATABLE_SALES') $vatableSales += $amount;
            if ($type === 'VAT') $vatAmount += $amount;
            if ($type === 'SC_VAT_EXEMPT_SALES' || $type === 'VAT_EXEMPT_SALES') $exemptSales += $amount;
        }
    }

    // Parse Adjustments (Discounts)
    $seniorDisc = 0;
    $pwdDisc = 0;
    $promoDisc = 0;
    $serviceCharge = 0;
    if (isset($tx['adjustments']) && is_array($tx['adjustments'])) {
        foreach ($tx['adjustments'] as $adj) {
            $type = strtolower($adj['adjustment_type'] ?? '');
            $amount = (float)($adj['amount'] ?? 0);
            if ($type === 'senior_discount') $seniorDisc += $amount;
            if ($type === 'pwd_discount') $pwdDisc += $amount;
            if ($type === 'promo_discount') $promoDisc += $amount;
            if (str_contains($type, 'service_charge')) $serviceCharge += $amount;
        }
    }

    $dataRows[] = [
        basename($file),
        $tx['transaction_id'] ?? $tx['id'] ?? 'N/A',
        $tx['receipt_no'] ?? 'N/A',
        $tx['transaction_timestamp'] ?? $tx['date'] ?? 'N/A',
        (float)($tx['gross_sales'] ?? 0),
        $vatableSales,
        $vatAmount,
        $exemptSales,
        $seniorDisc,
        $pwdDisc,
        $promoDisc,
        (float)($tx['net_sales'] ?? 0),
        $serviceCharge,
    ];
}

// --- Excel Generation ---
echo "Generating Excel file: $outputFile...\n";

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Extracted Transactions');

// Write Headers
foreach ($headers as $col => $header) {
    $cell = chr(65 + $col) . '1';
    $sheet->setCellValue($cell, $header);
    
    // Style Headers
    $sheet->getStyle($cell)->getFont()->setBold(true);
    $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD3D3D3');
}

// Write Data
$rowNum = 2;
foreach ($dataRows as $row) {
    foreach ($row as $col => $value) {
        $cell = chr(65 + $col) . $rowNum;
        $sheet->setCellValue($cell, $value);
        
        // Format numbers for financial columns (E onwards)
        if ($col >= 4 && is_numeric($value)) {
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00');
        }
    }
    $rowNum++;
}

// Auto-size columns
foreach (range('A', chr(64 + count($headers))) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Save File
try {
    $writer = new Xlsx($spreadsheet);
    $writer->save($outputFile);
    echo "Success! Report saved to: $outputFile\n";
} catch (Exception $e) {
    echo "Error saving file: " . $e->getMessage() . "\n";
    exit(1);
}
