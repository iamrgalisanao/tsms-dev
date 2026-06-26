<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tenantSearch = $argv[1] ?? 'Greenwich';
$date = $argv[2] ?? '2026-06-23';
$xlsx = $argv[3] ?? __DIR__ . '/../data/GW PITX JUNE 23.xlsx';
$timezone = config('tsms.transaction_logs.timezone', 'Asia/Manila') ?: 'Asia/Manila';

$start = (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone($timezone)))
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d H:i:s');
$end = (new DateTimeImmutable($date . ' 23:59:59', new DateTimeZone($timezone)))
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d H:i:s');

function money($value): string
{
    return number_format((float) $value, 2, '.', ',');
}

function printRows(string $title, iterable $rows): void
{
    echo "\n== {$title} ==\n";
    foreach ($rows as $row) {
        echo json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    }
}

echo "Tenant search: {$tenantSearch}\n";
echo "Business date: {$date} ({$timezone})\n";
echo "UTC window: {$start} to {$end}\n";
echo "Database: " . DB::connection()->getDatabaseName() . "\n";

$tenants = DB::table('tenants')
    ->where('trade_name', 'like', "%{$tenantSearch}%")
    ->get(['id', 'trade_name']);
printRows('Matching tenants', $tenants);

$tenantIds = $tenants->pluck('id')->all();
if (empty($tenantIds)) {
    echo "\nNo matching tenant found. Stopping.\n";
    exit(0);
}

$terminals = DB::table('pos_terminals as term')
    ->join('tenants as tn', 'term.tenant_id', '=', 'tn.id')
    ->whereIn('tn.id', $tenantIds)
    ->orderBy('term.serial_number')
    ->get([
        'term.id',
        'term.serial_number',
        'term.machine_number',
        'term.tenant_id',
        'tn.trade_name',
    ]);
printRows('Registered terminals for tenant', $terminals);

$screenRows = DB::table('transactions as t')
    ->join('pos_terminals as term', 't.terminal_id', '=', 'term.id')
    ->leftJoin('tenants as tn', 't.tenant_id', '=', 'tn.id')
    ->whereIn('t.tenant_id', $tenantIds)
    ->whereBetween('t.transaction_timestamp', [$start, $end])
    ->where(function ($query) {
        $query->where('t.transaction_type', '!=', 'VOID')
            ->orWhereNull('t.transaction_type');
    })
    ->whereNull('t.voided_at')
    ->groupBy('term.serial_number')
    ->orderBy('term.serial_number')
    ->selectRaw('term.serial_number')
    ->selectRaw('COUNT(*) as tx_count')
    ->selectRaw("COUNT(DISTINCT NULLIF(t.receipt_no, '')) as unique_receipts")
    ->selectRaw('ROUND(COALESCE(SUM(t.gross_sales),0),2) as gross')
    ->selectRaw('ROUND(COALESCE(SUM(t.net_sales),0),2) as raw_net')
    ->selectRaw('ROUND(COALESCE(SUM(t.vat_amount),0),2) as vat')
    ->selectRaw('ROUND(COALESCE(SUM(t.vatable_sales),0),2) as vatable')
    ->selectRaw('ROUND(COALESCE(SUM(t.sc_vat_exempt_sales),0),2) as sc_vat')
    ->get();
printRows('DB grouped like screenshot: transaction date window, by registered terminal serial_number', $screenRows);

$payloadRows = DB::table('transactions as t')
    ->join('pos_terminals as term', 't.terminal_id', '=', 'term.id')
    ->whereIn('t.tenant_id', $tenantIds)
    ->whereBetween('t.transaction_timestamp', [$start, $end])
    ->whereNotNull('t.original_payload')
    ->get([
        't.id',
        't.receipt_no',
        't.transaction_timestamp',
        't.gross_sales',
        't.net_sales',
        't.original_payload',
        'term.serial_number as registered_terminal',
    ]);

$byPayloadHardware = [];
$hardwareVsRegistered = [];
$badPayload = 0;
foreach ($payloadRows as $row) {
    $payload = json_decode($row->original_payload, true);
    if (! is_array($payload)) {
        $badPayload++;
        continue;
    }

    $tx = $payload['transaction'] ?? $payload;
    $hardware = trim((string) ($tx['hardware_id'] ?? ''));
    $hardware = $hardware !== '' ? $hardware : '(missing)';
    $registered = (string) $row->registered_terminal;
    $key = $hardware . ' -> ' . $registered;

    $byPayloadHardware[$hardware] ??= [
        'hardware_id' => $hardware,
        'tx_count' => 0,
        'unique_receipts' => [],
        'gross' => 0.0,
        'raw_net' => 0.0,
    ];
    $byPayloadHardware[$hardware]['tx_count']++;
    $byPayloadHardware[$hardware]['unique_receipts'][(string) $row->receipt_no] = true;
    $byPayloadHardware[$hardware]['gross'] += (float) $row->gross_sales;
    $byPayloadHardware[$hardware]['raw_net'] += (float) $row->net_sales;

    $hardwareVsRegistered[$key] ??= [
        'payload_hardware_id' => $hardware,
        'registered_terminal' => $registered,
        'tx_count' => 0,
        'gross' => 0.0,
        'raw_net' => 0.0,
    ];
    $hardwareVsRegistered[$key]['tx_count']++;
    $hardwareVsRegistered[$key]['gross'] += (float) $row->gross_sales;
    $hardwareVsRegistered[$key]['raw_net'] += (float) $row->net_sales;
}

foreach ($byPayloadHardware as &$row) {
    $row['unique_receipts'] = count(array_filter(array_keys($row['unique_receipts'])));
    $row['gross'] = money($row['gross']);
    $row['raw_net'] = money($row['raw_net']);
}
unset($row);
ksort($byPayloadHardware);
printRows('DB same date window, grouped by original_payload.transaction.hardware_id', array_values($byPayloadHardware));
echo "Bad original_payload JSON rows: {$badPayload}\n";

foreach ($hardwareVsRegistered as &$row) {
    $row['gross'] = money($row['gross']);
    $row['raw_net'] = money($row['raw_net']);
}
unset($row);
ksort($hardwareVsRegistered);
printRows('DB payload hardware_id mapped to registered terminal serial_number', array_values($hardwareVsRegistered));

$duplicates = DB::table('transactions as t')
    ->join('pos_terminals as term', 't.terminal_id', '=', 'term.id')
    ->whereIn('t.tenant_id', $tenantIds)
    ->whereBetween('t.transaction_timestamp', [$start, $end])
    ->whereNotNull('t.receipt_no')
    ->where('t.receipt_no', '!=', '')
    ->groupBy('term.serial_number', 't.receipt_no')
    ->havingRaw('COUNT(*) > 1')
    ->orderByDesc('copies')
    ->limit(50)
    ->get([
        'term.serial_number',
        't.receipt_no',
        DB::raw('COUNT(*) as copies'),
        DB::raw('ROUND(SUM(t.gross_sales),2) as gross'),
    ]);
printRows('Duplicate receipt_no rows in date window, if any', $duplicates);

if (is_file($xlsx)) {
    $sheet = IOFactory::load($xlsx)->getSheet(0);
    $xlsxTotals = [];
    $xlsxLocalDates = [];
    for ($r = 2; $r <= $sheet->getHighestRow(); $r++) {
        $payload = json_decode((string) $sheet->getCell("D{$r}")->getValue(), true);
        if (! is_array($payload)) {
            continue;
        }
        $tx = $payload['transaction'] ?? [];
        $hardware = (string) ($tx['hardware_id'] ?? '(missing)');
        $ts = (string) ($tx['transaction_timestamp'] ?? '');
        $localDate = $ts !== ''
            ? (new DateTimeImmutable($ts))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d')
            : '(missing)';
        $xlsxLocalDates[$localDate] = ($xlsxLocalDates[$localDate] ?? 0) + 1;

        $xlsxTotals[$hardware] ??= [
            'hardware_id' => $hardware,
            'tx_count' => 0,
            'unique_receipts' => [],
            'gross' => 0.0,
            'raw_net' => 0.0,
        ];
        $xlsxTotals[$hardware]['tx_count']++;
        $xlsxTotals[$hardware]['unique_receipts'][(string) ($tx['receipt_no'] ?? '')] = true;
        $xlsxTotals[$hardware]['gross'] += (float) ($tx['gross_sales'] ?? 0);
        $xlsxTotals[$hardware]['raw_net'] += (float) ($tx['net_sales'] ?? 0);
    }

    foreach ($xlsxTotals as &$row) {
        $row['unique_receipts'] = count(array_filter(array_keys($row['unique_receipts'])));
        $row['gross'] = money($row['gross']);
        $row['raw_net'] = money($row['raw_net']);
    }
    unset($row);
    ksort($xlsxTotals);
    ksort($xlsxLocalDates);
    printRows('XLSX grouped by payload hardware_id', array_values($xlsxTotals));
    printRows('XLSX row count by local business date', array_map(
        fn ($date, $count) => ['local_date' => $date, 'rows' => $count],
        array_keys($xlsxLocalDates),
        array_values($xlsxLocalDates),
    ));
} else {
    echo "\nXLSX not found at {$xlsx}; skipped workbook comparison.\n";
}

