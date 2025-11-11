<?php
// Helper: inspect canonical fingerprint groups by transaction id lists
// Usage:
//  php scripts/inspect_fingerprint_groups.php --group1=457,458,459 --group2=573,574

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Transaction;

$opts = getopt('', ['group1::', 'group2::']);

if (isset($opts['group1']) && strlen(trim($opts['group1'])) > 0) {
    $group1 = array_map('intval', array_filter(array_map('trim', explode(',', $opts['group1']))));
} else {
    // default sample from dry-run output
    $group1 = [457,458,459,460,461,462,463,464,465,466,467,468,469,470,471,472,473,474,475,476];
}

if (isset($opts['group2']) && strlen(trim($opts['group2'])) > 0) {
    $group2 = array_map('intval', array_filter(array_map('trim', explode(',', $opts['group2']))));
} else {
    $group2 = [573,574,575,576,577,578,579,580,581,582,583,584,585,590,592,593,594,595,596,597];
}

function dump_ids(array $ids, string $label)
{
    echo "=== {$label} (" . count($ids) . ") ===\n";
    $rows = Transaction::whereIn('id', $ids)->orderBy('id')->get();
    foreach ($rows as $t) {
        // compute a canonical fingerprint (compatible with JobProcessingService)
        $fp = null;
        try {
            // prefer original_payload when present
            if (!empty($t->original_payload)) {
                $decoded = json_decode($t->original_payload, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $source = $decoded;
                } else {
                    $source = null;
                }
            } else {
                $source = [
                    'tenant_id' => $t->tenant_id,
                    'terminal_id' => $t->terminal_id,
                    'receipt_no' => $t->receipt_no,
                    'gross_sales' => isset($t->gross_sales) ? (float) $t->gross_sales : null,
                    'net_sales' => isset($t->net_sales) ? (float) $t->net_sales : null,
                    'discount_total' => isset($t->discount_total) ? (float) $t->discount_total : null,
                    'vat_amount' => isset($t->vat_amount) ? (float) $t->vat_amount : null,
                ];
                try {
                    if (method_exists($t, 'adjustments')) {
                        $items = $t->adjustments()->get()->map(function ($it) {
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

            if ($source !== null) {
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
                if ($canonicalJson !== false) $fp = hash('sha256', $canonicalJson);
            }
        } catch (\Throwable $_e) {
            $fp = null;
        }

        $out = [
            'id' => $t->id,
            'tenant_id' => $t->tenant_id,
            'terminal_id' => $t->terminal_id,
            'receipt_no' => $t->receipt_no,
            'transaction_timestamp' => (string) $t->transaction_timestamp,
            'payload_checksum' => $t->payload_checksum,
            'gross_sales' => $t->gross_sales ?? null,
            'net_sales' => $t->net_sales ?? null,
            'discount_total' => $t->discount_total ?? null,
            'vat_amount' => $t->vat_amount ?? null,
            'adjustments_count' => method_exists($t, 'adjustments') ? $t->adjustments()->count() : null,
            'canonical_fingerprint' => $fp,
            // truncate original_payload for safe console output
            'original_payload' => mb_substr((string) $t->original_payload, 0, 400),
        ];
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

dump_ids($group1, 'group1');
dump_ids($group2, 'group2');

