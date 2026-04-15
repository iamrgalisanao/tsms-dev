<?php
$dir = 'tools/data';
$files = glob("$dir/20260409*.json");
$results = [];

foreach ($files as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!isset($data['tenant_id']) || $data['tenant_id'] != 125) continue;
    
    $tx = $data['transaction'];
    $gross = (float)$tx['gross_sales'];
    $net = (float)$tx['net_sales'];
    $vat = 0.0;
    foreach ($tx['taxes'] ?? [] as $t) {
        if ($t['tax_type'] == 'VAT') $vat = (float)$t['amount'];
    }
    
    $senior = 0.0;
    foreach ($tx['adjustments'] ?? [] as $a) {
        if ($a['adjustment_type'] == 'senior_discount') $senior = (float)$a['amount'];
    }
    
    $expected_gross = round($net + $senior + $vat, 2);
    $diff = round($gross - $expected_gross, 2);
    
    if (abs($diff) > 0.01) {
        $results[] = [
            'file' => basename($file),
            'gross' => $gross,
            'net' => $net,
            'senior' => $senior,
            'vat' => $vat,
            'expected' => $expected_gross,
            'diff' => $diff
        ];
    }
}

echo json_encode($results, JSON_PRETTY_PRINT);
