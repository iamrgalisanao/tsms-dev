<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$date = date('Y-m-d', strtotime('-1 day'));
$tenant = DB::table('tenants')->first();
if (! $tenant) {
    echo "No tenants found\n";
    exit(1);
}

echo "Checking transactions for tenant id: {$tenant->id} on date: {$date}\n";
$cntTs = DB::table('transactions')->where('tenant_id', $tenant->id)->whereDate('transaction_timestamp', $date)->count();
$cntCreated = DB::table('transactions')->where('tenant_id', $tenant->id)->whereDate('created_at', $date)->count();
$cntAny = DB::table('transactions')->where('tenant_id', $tenant->id)->whereDate(DB::raw("COALESCE(transaction_timestamp, created_at)") , $date)->count();

echo "count where transaction_timestamp = {$cntTs}\n";
echo "count where created_at = {$cntCreated}\n";
echo "count where COALESCE(transaction_timestamp, created_at) = {$cntAny}\n";

exit(0);
