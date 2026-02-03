<?php
if ($argc < 2) {
    echo "Usage: php scripts/compute_checksum.php <json-file>\n";
    exit(1);
}
$path = $argv[1];
if (!file_exists($path)) {
    echo "File not found: $path\n";
    exit(2);
}
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$payload = json_decode(file_get_contents($path), true);
if ($payload === null) {
    echo "Failed to parse JSON from $path\n";
    exit(3);
}
$svc = app(\App\Services\PayloadChecksumService::class);
echo $svc->computeChecksum($payload) . PHP_EOL;
