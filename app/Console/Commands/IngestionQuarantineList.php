<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\IngestionQuarantine;

class IngestionQuarantineList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ingestion:quarantine:list {--limit=100} {--status=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List recent ingestion quarantine records';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $status = $this->option('status');

        $query = IngestionQuarantine::query()->orderByDesc('created_at');
        if ($status) {
            $query->where('status', $status);
        }

        $rows = $query->limit($limit)->get(['id', 'submission_uuid', 'tenant_id', 'terminal_id', 'status', 'attempts', 'created_at']);

        if ($rows->isEmpty()) {
            $this->info('No quarantine records found.');
            return 0;
        }

        $this->table(
            ['id', 'submission_uuid', 'tenant_id', 'terminal_id', 'status', 'attempts', 'created_at'],
            $rows->map(function ($r) {
                return [
                    $r->id,
                    $r->submission_uuid,
                    $r->tenant_id,
                    $r->terminal_id,
                    $r->status,
                    $r->attempts,
                    $r->created_at ? $r->created_at->toDateTimeString() : null,
                ];
            })->toArray()
        );

        return 0;
    }
}
