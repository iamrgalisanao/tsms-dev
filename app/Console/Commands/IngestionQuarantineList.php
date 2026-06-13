<?php

namespace App\Console\Commands;

use App\Models\IngestionQuarantine;
use Illuminate\Console\Command;

class IngestionQuarantineList extends Command
{
    protected $signature = 'ingestion:quarantine:list
        {--limit=50 : Maximum records to show}
        {--status= : Filter by quarantine status}
        {--tenant= : Filter by tenant id}
        {--terminal= : Filter by terminal id}';

    protected $description = 'List recent ingestion quarantine records';

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));

        $query = IngestionQuarantine::query()
            ->when($this->option('status'), fn ($q, $status) => $q->where('status', strtoupper((string) $status)))
            ->when($this->option('tenant'), fn ($q, $tenant) => $q->where('tenant_id', $tenant))
            ->when($this->option('terminal'), fn ($q, $terminal) => $q->where('terminal_id', $terminal))
            ->orderByDesc('created_at')
            ->limit($limit);

        $rows = $query->get(['id', 'submission_uuid', 'tenant_id', 'terminal_id', 'status', 'attempts', 'created_at']);

        if ($rows->isEmpty()) {
            $this->info('No quarantine records found.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Submission UUID', 'Tenant', 'Terminal', 'Status', 'Attempts', 'Created'],
            $rows->map(fn (IngestionQuarantine $row) => [
                $row->id,
                $row->submission_uuid,
                $row->tenant_id,
                $row->terminal_id,
                $row->status,
                $row->attempts,
                optional($row->created_at)->toDateTimeString(),
            ])->all()
        );

        return self::SUCCESS;
    }
}
