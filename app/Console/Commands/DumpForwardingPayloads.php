<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WebappTransactionForward;
use Illuminate\Support\Facades\Log;

class DumpForwardingPayloads extends Command
{
    protected $signature = 'webapp:dump-forwarding {--limit=10 : Number of most recent forwarding records to dump}';
    protected $description = 'Dump recent WebApp forwarding payloads to log (for debugging)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $records = WebappTransactionForward::latest()->limit($limit)->get();

        if ($records->isEmpty()) {
            $this->info('No forwarding records found');
            return self::SUCCESS;
        }

        $records->each(function ($rec) {
            Log::info('Forwarding payload dump', [
                'id' => $rec->id,
                'transaction_id' => $rec->transaction?->transaction_id ?? $rec->transaction_id,
                'status' => $rec->status,
                'attempts' => $rec->attempts,
                'batch_id' => $rec->batch_id,
                'request_payload' => $rec->request_payload,
                'response_status_code' => $rec->response_status_code,
                'created_at' => $rec->created_at?->toIso8601String(),
                'completed_at' => $rec->completed_at?->toIso8601String(),
            ]);
        });

        $this->info('Dumped '.$records->count().' forwarding records to log');
        return self::SUCCESS;
    }
}
