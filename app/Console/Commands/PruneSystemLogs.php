<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SystemLogService;

class PruneSystemLogs extends Command
{
    /**
     * The name and signature of the console command.
     * --days=, --before=, --type=, --dry-run, --force
     *
     * @var string
     */
    protected $signature = 'systemlogs:prune {--days= : Delete logs older than N days} {--before= : Delete logs before date (Y-m-d)} {--type= : Optional log type to prune} {--dry-run : Show what would be deleted} {--force : Actually perform deletion (bypass dry-run)} {--hard : Permanently delete rows instead of soft-deleting (danger) }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune SystemLog rows by age or date range (safe: requires --force to delete)';

    protected $service;

    public function __construct(SystemLogService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle()
    {
        $days = $this->option('days');
        $before = $this->option('before');
        $type = $this->option('type');
    $dry = $this->option('dry-run');
    $force = $this->option('force');
    $hard = $this->option('hard');

        if (!$before && !$days) {
            $this->error('Provide either --days or --before to specify prune criteria.');
            return 1;
        }

        if ($dry && !$force) {
            $this->info('Running dry-run (no deletions will be performed). Use --force to actually delete.');
        }

        $result = $this->service->prune([
            'before' => $before,
            'days' => $days ? (int)$days : null,
            'type' => $type,
            // dry_run true if explicit dry-run or not forced
            'dry_run' => $dry || !$force,
            'hard' => $hard ? true : false,
            'chunk' => 500
        ]);

        if (isset($result['error'])) {
            $this->error($result['error']);
            return 2;
        }

        if (!empty($result['dry_run'])) {
            $this->info('Dry run: '.$result['count'].' rows would be pruned.');
            if (!empty($result['sample_ids'])) {
                $this->line('Sample IDs: '.implode(',', $result['sample_ids']));
            }
            return 0;
        }

        $this->info('Prune complete. Deleted: '.($result['deleted'] ?? 0));
        return 0;
    }
}
