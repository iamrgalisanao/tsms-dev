<?php
// Lightweight script to print recent SecurityEvent rows for smoke-testing.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SecurityEvent;

$events = SecurityEvent::latest()->take(10)->get()->toArray();
echo json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
