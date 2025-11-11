<?php
// Non-destructive dry-run: compute canonical_fingerprint for existing transactions
// and report collisions. Writes a JSON report to storage/logs/backfill_fingerprint_dryrun_<ts>.json

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

date_default_timezone_set('UTC');

$opts = getopt('', ['limit::', 'days::']);
$limit = isset($opts['limit']) ? (int)$opts['limit'] : null;
$days = isset($opts['days']) ? (int)$opts['days'] : null;

echo "Starting canonical fingerprint dry-run\n";
echo "Limit=" . ($limit ?? 'none') . " days=" . ($days ?? 'none') . "\n";

$query = Transaction::query()->where('validation_status', 'VALID');
if ($days) {
    $query->where('transaction_timestamp', '>=', now()->subDays($days));
}
if ($limit) {
    $query->limit($limit);
}

$total = 0;
$nullCount = 0;
$fingerprinted = 0;
$map = [];

$reportSamples = [];

$processFn = function ($tx) use (&$total, &$nullCount, &$fingerprinted, &$map, &$reportSamples) {
    $total++;
    $fp = compute_canonical_fingerprint_from_tx($tx);
    if ($fp === null) {
        $nullCount++;
        return;
    }
    $fingerprinted++;
    if (!isset($map[$fp])) $map[$fp] = [];
    if (count($map[$fp]) < 20) $map[$fp][] = $tx->id;
    // sample store
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

$outFile = __DIR__ . '/../storage/logs/backfill_fingerprint_dryrun_' . date('Ymd_His') . '.json';
file_put_contents($outFile, json_encode($report, JSON_PRETTY_PRINT));

echo "Dry-run complete. Report written to: {$outFile}\n";
echo "Total={$total}, fingerprinted={$fingerprinted}, null={$nullCount}, collisions=" . count($collisions) . "\n";
echo "Top sample collisions:\n";
foreach ($report['sample_collisions'] as $c) {
    echo "- fp={$c['fingerprint']} count={$c['count']} ids=" . implode(',', $c['ids']) . "\n";
}

// -- helper function (copied/compatible with JobProcessingService)
function compute_canonical_fingerprint_from_tx($transaction)
{
    // Prefer original_payload if valid
    $source = null;
    if (!empty($transaction->original_payload)) {
        $decoded = json_decode($transaction->original_payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) return null;
        $source = $decoded;
    } else {
        $source = [
            'tenant_id' => $transaction->tenant_id,
            'terminal_id' => $transaction->terminal_id,
            'receipt_no' => $transaction->receipt_no,
            'gross_sales' => isset($transaction->gross_sales) ? (float) $transaction->gross_sales : null,
            'net_sales' => isset($transaction->net_sales) ? (float) $transaction->net_sales : null,
            'discount_total' => isset($transaction->discount_total) ? (float) $transaction->discount_total : null,
            'vat_amount' => isset($transaction->vat_amount) ? (float) $transaction->vat_amount : null,
        ];
        try {
            if (method_exists($transaction, 'adjustments')) {
                $items = $transaction->adjustments()->get()->map(function ($it) {
                    return [
                        'sku' => $it->sku ?? null,
                        'amount' => isset($it->amount) ? (float) $it->amount : null,
                        'quantity' => isset($it->quantity) ? (float) $it->quantity : null,
                    ];
                })->toArray();
                if (!empty($items)) $source['adjustments'] = $items;
            }
        } catch (\Throwable $_) {}
    }

    $cleaner = function ($v) use (&$cleaner) {
        if (is_array($v)) {
            $isAssoc = array_keys($v) !== range(0, count($v) - 1);
            if ($isAssoc) {
                foreach (['submission_uuid', 'transaction_id', 'payload_checksum', 'created_at', 'updated_at', 'completed_at', 'ingestion_timestamp'] as $k) {
                    if (array_key_exists($k, $v)) unset($v[$k]);
                }
                foreach ($v as $k => $sub) $v[$k] = $cleaner($sub);
                ksort($v);
                return $v;
            }
            $out = array_map($cleaner, $v);
            usort($out, function ($a, $b) {
                $ka = is_array($a) && (isset($a['sku']) || isset($a['id'])) ? (string) ($a['sku'] ?? $a['id']) : null;
                $kb = is_array($b) && (isset($b['sku']) || isset($b['id'])) ? (string) ($b['sku'] ?? $b['id']) : null;
                if ($ka !== null && $kb !== null) return strcmp($ka, $kb);
                return 0;
            });
            return $out;
        }
        if (is_float($v) || is_numeric($v)) {
            if (is_float($v) || strpos((string) $v, '.') !== false) return round((float) $v, 2);
            return $v + 0;
        }
        if (is_string($v)) {
            $s = trim($v);
            $s = preg_replace('/\s+/', ' ', $s);
            return $s;
        }
        return $v;
    };

    $clean = $cleaner($source);
    $canonicalJson = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($canonicalJson === false) return null;
    return hash('sha256', $canonicalJson);
}
