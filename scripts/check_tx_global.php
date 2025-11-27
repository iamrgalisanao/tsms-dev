<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$date = date('Y-m-d', strtotime('-1 day'));
echo "Checking global transactions on date: {$date}\n";
$cntTs = DB::table('transactions')->whereDate('transaction_timestamp', $date)->count();
$cntCreated = DB::table('transactions')->whereDate('created_at', $date)->count();
echo "global count transaction_timestamp = {$cntTs}\n";
echo "global count created_at = {$cntCreated}\n";

// show a few sample rows if present
$rows = DB::table('transactions')->select('id','tenant_id','terminal_id','transaction_timestamp','created_at','gross_sales')->whereDate('transaction_timestamp', $date)->orWhereDate('created_at', $date)->limit(5)->get();
if (count($rows) > 0) {
    echo "Sample rows:\n";
    print_r($rows->toArray());
} else {
    echo "No sample rows found for that date.\n";
}

exit(0);
