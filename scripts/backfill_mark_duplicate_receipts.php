#!/usr/bin/env php
<?php
/**
 * Backfill script: mark duplicate transactions (same tenant_id, terminal_id,
 * receipt_no, and calendar date) as DUPLICATE non-destructively.
 *
 * Usage:
 *   php scripts/backfill_mark_duplicate_receipts.php        # dry-run, shows groups
 *   php scripts/backfill_mark_duplicate_receipts.php --apply  # perform updates
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv, true);

echo ($apply ? "APPLY MODE\n" : "DRY-RUN MODE (no changes)\n");

// Find groups with duplicate receipt_no on same calendar date
$groups = DB::table('transactions')
    ->select('tenant_id','terminal_id','receipt_no', DB::raw("DATE(COALESCE(transaction_timestamp, created_at)) as tx_date"), DB::raw('COUNT(*) as cnt'))
    ->whereNotNull('receipt_no')
    ->where('receipt_no', '!=', '')
    ->groupBy('tenant_id','terminal_id','receipt_no', 'tx_date')
    ->having('cnt', '>', 1)
    ->orderBy('tx_date', 'desc')
    ->get();

if ($groups->isEmpty()) {
    echo "No duplicate receipt groups found.\n";
    exit(0);
}

echo "Found " . $groups->count() . " duplicate receipt groups\n";

$totalMarked = 0;
$totalGroups = 0;

foreach ($groups as $g) {
    $totalGroups++;
    echo "\nGroup: tenant={$g->tenant_id} terminal={$g->terminal_id} receipt={$g->receipt_no} date={$g->tx_date} count={$g->cnt}\n";

    $rows = DB::table('transactions')
        ->where('tenant_id', $g->tenant_id)
        ->where('terminal_id', $g->terminal_id)
        ->where('receipt_no', $g->receipt_no)
        ->whereRaw("DATE(COALESCE(transaction_timestamp, created_at)) = ?", [$g->tx_date])
        ->orderBy('created_at', 'asc')
        ->get();

    // Determine canonical row: prefer earliest VALID, otherwise earliest row
    $canonical = null;
    foreach ($rows as $r) {
        if ($r->validation_status === 'VALID') {
            $canonical = $r;
            break;
        }
    }
    if (!$canonical) {
        $canonical = $rows->first();
    }

    echo "Canonical id={$canonical->id} status={$canonical->validation_status} created_at={$canonical->created_at}\n";

    // Identify candidates to mark DUPLICATE: any row that is not the canonical and is not already DUPLICATE
    $candidates = collect($rows)->filter(function($r) use ($canonical) {
        return $r->id !== $canonical->id && ($r->validation_status !== 'DUPLICATE');
    });

    if ($candidates->isEmpty()) {
        echo "  No non-duplicate candidates to mark for this group.\n";
        continue;
    }

    echo "  Candidates to mark: " . $candidates->pluck('id')->implode(', ') . "\n";

    if ($apply) {
        DB::beginTransaction();
        try {
            $ids = $candidates->pluck('id')->all();
            $updated = DB::table('transactions')->whereIn('id', $ids)->update([
                'validation_status' => 'DUPLICATE',
                'job_status' => 'DUPLICATE'
            ]);
            DB::commit();
            echo "  Marked {$updated} rows as DUPLICATE.\n";
            $totalMarked += $updated;
        } catch (\Throwable $e) {
            DB::rollBack();
            echo "  ERROR applying updates: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nProcessed {$totalGroups} groups.\n";
if ($apply) {
    echo "Total rows marked DUPLICATE: {$totalMarked}\n";
} else {
    echo "Dry-run complete. Rerun with --apply to make changes.\n";
}

exit(0);
