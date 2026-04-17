<?php

namespace App\Console\Commands;

use App\Models\TransactionIntake;
use App\Support\Metrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GhostFinder extends Command
{
    protected $signature = 'tsms:find-ghost {intake_id : The ID of the failing intake record}';
    protected $description = 'Perform a deep forensic search for a conflict that INSERT IGNORE detects but SELECT misses.';

    public function handle()
    {
        $id = $this->argument('intake_id');
        $intake = TransactionIntake::find($id);

        if (!$intake) {
            $this->error("Intake record #{$id} not found.");
            return;
        }

        $payload = $intake->payload['transaction'];
        $receipt = $payload['receipt_no'];
        $terminal = $intake->terminal_id;
        $tenant = $intake->tenant_id;
        $txId = $payload['transaction_id'];

        $this->info("Starting forensic search for Receipt: {$receipt} on Terminal: {$terminal}");

        // 1. Exact Search
        $exact = DB::table('transactions')->where('transaction_id', $txId)->first();
        if ($exact) {
            $this->success("Found Exact Match by transaction_id: {$exact->id}");
            return;
        }

        // 2. Fuzzy Receipt Search (Handles invisible characters)
        $this->warn("No exact match. Trying fuzzy receipt search...");
        $fuzzy = DB::table('transactions')
            ->where('terminal_id', $terminal)
            ->where('receipt_no', 'LIKE', "%" . trim($receipt) . "%")
            ->get();

        if ($fuzzy->count() > 0) {
            $this->success("Found " . $fuzzy->count() . " potential matches via fuzzy search:");
            foreach ($fuzzy as $row) {
                $this->line("  - ID: {$row->id} | Receipt: '{$row->receipt_no}' | Date: {$row->transaction_timestamp} | Tenant: {$row->tenant_id}");
                if ($row->receipt_no !== $receipt) {
                    $this->info("    [DETECTED] Character mismatch! Payload: '" . $receipt . "' vs DB: '" . $row->receipt_no . "'");
                }
            }
            return;
        }

        // 3. Broad Temporal Search
        $this->warn("No fuzzy match. Checking for ANY receipt on this terminal today...");
        $date = date('Y-m-d', strtotime($payload['transaction_timestamp']));
        $broad = DB::table('transactions')
            ->where('terminal_id', $terminal)
            ->whereDate('transaction_timestamp', $date)
            ->get();

        if ($broad->count() > 0) {
            $this->info("Found " . $broad->count() . " total transactions for this terminal on " . $date . ".");
            $this->line("Listing receipt numbers in DB:");
            foreach ($broad as $row) {
                $this->line("  - '{$row->receipt_no}'");
            }
        } else {
            $this->error("ABSOLUTE GHOST: Database claims conflict, but 0 records found for terminal {$terminal} on {$date}.");
            $this->info("This suggests the conflict might be on a DIFFERENT column (e.g. payload_checksum or refund_reference).");
        }
    }

    protected function success($message)
    {
        $this->output->writeln("<info>✔</info> $message");
    }
}
