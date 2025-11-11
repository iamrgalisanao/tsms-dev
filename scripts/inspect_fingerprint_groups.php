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
        $out = [
            'id' => $t->id,
            'tenant_id' => $t->tenant_id,
            'terminal_id' => $t->terminal_id,
            'receipt_no' => $t->receipt_no,
            'transaction_timestamp' => (string) $t->transaction_timestamp,
            'payload_checksum' => $t->payload_checksum,
            // truncate original_payload for safe console output
            'original_payload' => mb_substr((string) $t->original_payload, 0, 400),
        ];
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

dump_ids($group1, 'group1');
dump_ids($group2, 'group2');

// EOF
<?php
// Non-destructive inspector for fingerprint groups (prints truncated payloads)
require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Transaction;

function dump_ids(array $ids, string $label)
{
    echo "=== {$label} ===\n";
    $rows = Transaction::whereIn('id', $ids)->orderBy('id')->get();
    foreach ($rows as $t) {
        $out = [
            'id' => $t->id,
            'tenant_id' => $t->tenant_id,
            'terminal_id' => $t->terminal_id,
            'receipt_no' => $t->receipt_no,
            'transaction_timestamp' => (string) $t->transaction_timestamp,
            'payload_checksum' => $t->payload_checksum,
            // truncate original_payload to avoid huge output
            'original_payload' => mb_substr((string) $t->original_payload, 0, 400),
        ];
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

$group1 = [457,458,459,460,461,462,463,464,465,466,467,468,469,470,471,472,473,474,475,476];
$group2 = [573,574,575,576,577,578,579,580,581,582,583,584,585,590,592,593,594,595,596,597];

dump_ids($group1, 'group1');
dump_ids($group2, 'group2');

echo "Done.\n";
