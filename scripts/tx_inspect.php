<?php
// Inspect transactions table: counts and min/max timestamps
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Inspecting transactions table...\n";

try {
    $total = DB::table('transactions')->count();
    echo "Total rows in transactions: {$total}\n";

    $minTs = DB::table('transactions')->whereNotNull('transaction_timestamp')->min('transaction_timestamp');
    $maxTs = DB::table('transactions')->whereNotNull('transaction_timestamp')->max('transaction_timestamp');
    $minCreated = DB::table('transactions')->whereNotNull('created_at')->min('created_at');
    $maxCreated = DB::table('transactions')->whereNotNull('created_at')->max('created_at');

    echo "transaction_timestamp min: " . ($minTs ?? 'NULL') . "\n";
    echo "transaction_timestamp max: " . ($maxTs ?? 'NULL') . "\n";
    echo "created_at min: " . ($minCreated ?? 'NULL') . "\n";
    echo "created_at max: " . ($maxCreated ?? 'NULL') . "\n";

    // Show counts for last 7 days by created_at
    echo "Counts by created_at (last 7 days):\n";
    $dates = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $c = DB::table('transactions')->whereDate('created_at', $d)->count();
        $dates[] = [ 'date' => $d, 'count' => $c ];
    }
    foreach ($dates as $row) {
        echo "  {$row['date']}: {$row['count']}\n";
    }

    // Show counts by transaction_timestamp last 7 days
    echo "Counts by transaction_timestamp (last 7 days):\n";
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $c = DB::table('transactions')->whereDate('transaction_timestamp', $d)->count();
        echo "  {$d}: {$c}\n";
    }

    // Show sample rows
    $samples = DB::table('transactions')->select('id','tenant_id','terminal_id','transaction_timestamp','created_at','gross_sales')->limit(5)->get();
    if (count($samples) > 0) {
        echo "Sample rows:\n";
        foreach ($samples as $s) {
            echo json_encode((array)$s) . "\n";
        }
    } else {
        echo "No sample rows available.\n";
    }
} catch (\Throwable $e) {
    echo "Error inspecting transactions: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Inspection complete.\n";
