<?php
// Service-based non-destructive dry-run: compute canonical_fingerprint using
// JobProcessingService::computeCanonicalFingerprintFromTransaction (includes entropy guard)
// Writes a JSON report to storage/logs/backfill_fingerprint_dryrun_service_<ts>.json

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Transaction;
use App\Services\JobProcessingService;

date_default_timezone_set('UTC');

$opts = getopt('', ['limit::', 'days::']);
$limit = isset($opts['limit']) ? (int)$opts['limit'] : null;
$days = isset($opts['days']) ? (int)$opts['days'] : null;

echo "Starting service-based canonical fingerprint dry-run\n";
echo "Limit=" . ($limit ?? 'none') . " days=" . ($days ?? 'none') . "\n";

$query = Transaction::query()->where('validation_status', 'VALID');
if ($days) $query->where('transaction_timestamp', '>=', now()->subDays($days));
if ($limit) $query->limit($limit);

$total = 0;
$nullCount = 0;
$fingerprinted = 0;
$map = [];
$reportSamples = [];

$svc = new JobProcessingService();
$rm = new ReflectionMethod($svc, 'computeCanonicalFingerprintFromTransaction');
$rm->setAccessible(true);

$processFn = function ($tx) use (&$total, &$nullCount, &$fingerprinted, &$map, &$reportSamples, $svc, $rm) {
    $total++;
    try {
        $fp = $rm->invoke($svc, $tx);
    } catch (\Throwable $e) {
        $fp = null;
    }
    if ($fp === null) {
        $nullCount++;
        return;
    }
    $fingerprinted++;
    if (!isset($map[$fp])) $map[$fp] = [];
    if (count($map[$fp]) < 20) $map[$fp][] = $tx->id;
    if (count($reportSamples) < 50 && count($map[$fp]) === 1) {
        $reportSamples[] = ['fingerprint' => $fp, 'transaction_id' => $tx->id, 'tenant_id' => $tx->tenant_id, 'terminal_id' => $tx->terminal_id, 'receipt_no' => $tx->receipt_no];
    }
};

$batch = 0;
$query->chunkById(1000, function ($rows) use ($processFn, &$batch, $limit, &$total) {
    $batch++;
    foreach ($rows as $r) {
        $processFn($r);
        if ($limit && $total >= $limit) return false; // stop chunking
    }
    echo "Processed batch {$batch}, total={$total}\n";
});

$collisions = [];
foreach ($map as $fp => $ids) {
    if (count($ids) > 1) {
        $collisions[$fp] = $ids;
    }
}

// Sort collisions by count desc
uksort($collisions, function ($a, $b) use ($collisions) {
    return count($collisions[$b]) <=> count($collisions[$a]);
});

$report = [
    'timestamp' => date('c'),
    'total_rows_examined' => $total,
    'fingerprinted_count' => $fingerprinted,
    'null_fingerprint_count' => $nullCount,
    'fingerprint_variants' => count($map),
    'collision_count' => count($collisions),
    'sample_collisions' => [],
    'samples' => $reportSamples,
];

$i = 0;
foreach ($collisions as $fp => $ids) {
    if ($i++ >= 20) break;
    $report['sample_collisions'][] = ['fingerprint' => $fp, 'count' => count($ids), 'ids' => $ids];
}

$outFile = __DIR__ . '/../storage/logs/backfill_fingerprint_dryrun_service_' . date('Ymd_His') . '.json';
file_put_contents($outFile, json_encode($report, JSON_PRETTY_PRINT));

echo "Dry-run complete. Report written to: {$outFile}\n";
echo "Total={$total}, fingerprinted={$fingerprinted}, null={$nullCount}, collisions=" . count($collisions) . "\n";
echo "Top sample collisions:\n";
foreach ($report['sample_collisions'] as $c) {
    echo "- fp={$c['fingerprint']} count={$c['count']} ids=" . implode(',', $c['ids']) . "\n";
}

