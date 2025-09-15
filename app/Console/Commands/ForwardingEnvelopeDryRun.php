<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\WebAppForwardingService;
use App\Models\Transaction;

class ForwardingEnvelopeDryRun extends Command
{
    protected $signature = 'tsms:forwarding-dry-run {transaction_id? : Internal numeric transaction PK or external transaction_id UUID}
                           {--immediate : Use immediate single transaction path}
                           {--json : Output raw JSON only}';

    protected $description = 'Build (capture-only) webapp forwarding envelope for inspection without performing HTTP.';

    public function handle(WebAppForwardingService $service): int
    {
        $idArg = $this->argument('transaction_id');
        $tx = null;
        if ($idArg) {
            // Try numeric PK first then external transaction_id
            $tx = is_numeric($idArg)
                ? Transaction::with(['terminal','tenant','adjustments','taxes'])->find($idArg)
                : Transaction::with(['terminal','tenant','adjustments','taxes'])->where('transaction_id', $idArg)->first();
            if (!$tx) {
                $this->error('Transaction not found for identifier: '.$idArg);
                return 1;
            }
        } else {
            $tx = Transaction::with(['terminal','tenant','adjustments','taxes'])
                ->where('validation_status', 'VALID')
                ->orderByDesc('id')
                ->first();
            if (!$tx) {
                $this->error('No VALID transaction found to build envelope. Provide a transaction_id.');
                return 1;
            }
        }

        // Force capture-only for deterministic dry run
        config(['tsms.testing.capture_only' => true]);
        config(['tsms.web_app.enabled' => true]);

        if ($this->option('immediate')) {
            $result = $service->forwardTransactionImmediately($tx);
            $envelope = $result['captured_payload'] ?? null;
            if (!$envelope) {
                $this->error('Failed to build envelope (immediate path).');
                return 1;
            }
        } else {
            // Build as batch with just this one transaction by mimicking forwardUnsentTransactions sequence
            // Simpler: call immediate path anyway because batch and immediate share same envelope shape for single txn.
            $result = $service->forwardTransactionImmediately($tx);
            $envelope = $result['captured_payload'] ?? null;
        }

        if ($this->option('json')) {
            $this->line(json_encode($envelope, JSON_PRETTY_PRINT));
        } else {
            $this->info('Forwarding Envelope (Dry Run)');
            $this->line('Schema Version : '.$envelope['schema_version']);
            $this->line('Batch ID       : '.$envelope['batch_id']);
            $this->line('Tenant ID      : '.$envelope['tenant_id']);
            $this->line('Terminal ID    : '.$envelope['terminal_id']);
            $this->line('Txn Count      : '.$envelope['transaction_count']);
            $this->line('Batch Checksum : '.$envelope['batch_checksum']);
            $this->line('--- Transactions[0] excerpt ---');
            $first = $envelope['transactions'][0];
            $this->line(json_encode(array_intersect_key($first, array_flip([
                'transaction_id','tenant_code','terminal_serial','checksum'
            ])), JSON_PRETTY_PRINT));
        }

        return 0;
    }
}
