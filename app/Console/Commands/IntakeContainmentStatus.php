<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTransactionIntakeJob;
use App\Models\TransactionIntake;
use App\Support\IntakeContainmentFloor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class IntakeContainmentStatus extends Command
{
    private const MAX_EXECUTION_TIME_MS = 30000;

    private const NO_MANIFEST_ROW_CAP = 5000;

    protected $signature = 'tsms:intake-containment-status
        {--floor= : Preview this floor value instead of the configured one}
        {--manifest= : CSV with an intake_id first column, or a plain ID list, to compare with the excluded IDs}';

    protected $description = 'Read-only report of the intake containment state and the rows the reconciler would exclude';

    public function handle(): int
    {
        $preview = $this->option('floor') !== null;
        $containment = $preview
            ? IntakeContainmentFloor::resolve($this->option('floor'))
            : IntakeContainmentFloor::current();

        $this->line('Environment: '.app()->environment());
        $this->line('Application timezone: '.config('app.timezone'));
        $this->line('Floor source: '.($preview ? 'preview (--floor)' : 'configuration'));
        $this->line('Floor state: '.$containment->state.($containment->reason ? " ({$containment->reason})" : ''));
        $this->line('Normalized floor: '.($containment->floor?->toIso8601String() ?? 'none'));
        $this->line('Retry freeze: '.(config('tsms.intake.retry_freeze') ? 'on' : 'off'));
        $this->line('Overlap expire seconds: '.ProcessTransactionIntakeJob::overlapExpireSeconds());
        $this->line('Overlap release seconds: '.ProcessTransactionIntakeJob::overlapReleaseSeconds());

        $this->line($this->horizonFailedJobsLine());

        if (! $containment->isArmed()) {
            $this->error('The floor is not armed or previewable. No rows were inspected.');

            return self::FAILURE;
        }

        $manifest = null;

        if ($this->option('manifest') !== null) {
            $manifest = $this->readManifest((string) $this->option('manifest'));

            if ($manifest === null) {
                return self::FAILURE;
            }
        }

        // One row past the manifest is enough to prove a mismatch.
        $cap = $manifest === null ? self::NO_MANIFEST_ROW_CAP : count($manifest) + 1;
        $excluded = $this->excludedIds($containment->floor, $cap);
        $truncated = count($excluded) >= $cap;

        $this->line('Excluded rows: '.($truncated ? 'at least ' : '').count($excluded));
        $this->line(($truncated ? "Excluded IDs (first {$cap}, truncated): " : 'Excluded IDs: ').implode(',', $excluded));

        if ($manifest === null) {
            if ($truncated) {
                $this->error("Result truncated at {$cap} rows. More excluded rows may exist and the list may be incomplete.");

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        if ($truncated) {
            $seen = array_values(array_diff($excluded, $manifest));

            $this->line('Manifest rows: '.count($manifest));
            $this->line('Only in manifest: not computed, the result is truncated');
            $this->line('Only excluded (at least '.count($seen).', list incomplete): '.implode(',', $seen));
            $this->error('Result truncated: more rows are excluded than the manifest lists, so the manifest does not match.');

            return self::FAILURE;
        }

        $onlyInManifest = array_values(array_diff($manifest, $excluded));
        $onlyExcluded = array_values(array_diff($excluded, $manifest));

        $this->line('Manifest rows: '.count($manifest));
        $this->line('Only in manifest ('.count($onlyInManifest).'): '.implode(',', $onlyInManifest));
        $this->line('Only excluded ('.count($onlyExcluded).'): '.implode(',', $onlyExcluded));

        if ($onlyInManifest !== [] || $onlyExcluded !== []) {
            $this->error('The manifest does not match the excluded rows.');

            return self::FAILURE;
        }

        $this->info('The manifest matches the excluded rows.');

        return self::SUCCESS;
    }

    /**
     * Non-terminal rows the reconciler's four normal-mode queries consider,
     * ignoring staleness, with received_at before the floor or NULL.
     *
     * @return array<int, int>
     */
    private function excludedIds(CarbonImmutable $floor, int $limit): array
    {
        $idColumn = DB::connection()->getDriverName() === 'mysql'
            ? '/*+ MAX_EXECUTION_TIME('.self::MAX_EXECUTION_TIME_MS.') */ id'
            : 'id';

        return TransactionIntake::query()
            ->selectRaw($idColumn)
            ->where(function (Builder $query): void {
                $query->where('intake_status', TransactionIntake::INTAKE_STATUS_ACCEPTED)
                    ->orWhere(function (Builder $queued): void {
                        $queued->where('intake_status', TransactionIntake::INTAKE_STATUS_QUEUED)
                            ->where(function (Builder $status): void {
                                $status->whereNull('processing_status')
                                    ->orWhereIn('processing_status', [
                                        TransactionIntake::PROCESSING_STATUS_PROCESSING,
                                        TransactionIntake::PROCESSING_STATUS_FAILED_RETRYABLE,
                                    ]);
                            });
                    });
            })
            ->where(function (Builder $query) use ($floor): void {
                $query->where('received_at', '<', $floor)
                    ->orWhereNull('received_at');
            })
            ->orderBy('id')
            ->limit($limit)
            ->toBase()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Same connection and key as Horizon's RedisJobRepository::countFailed(). The
     * horizon connection carries the configured prefix (see Horizon::use()).
     */
    private function horizonFailedJobsLine(): string
    {
        $at = now()->toIso8601String();

        try {
            $count = Redis::connection('horizon')->zcard('failed_jobs');
        } catch (\Throwable) {
            $count = null;
        }

        return is_numeric($count)
            ? "Horizon failed jobs: {$count} at {$at}"
            : "Horizon failed jobs: unavailable at {$at}";
    }

    /**
     * @return array<int, int>|null null when the manifest cannot be trusted
     */
    private function readManifest(string $path): ?array
    {
        $lines = is_file($path) ? @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;

        if ($lines === false) {
            $this->error("The manifest could not be read: {$path}");

            return null;
        }

        $ids = [];

        foreach ($lines as $index => $line) {
            $line = $index === 0 ? preg_replace('/^\xEF\xBB\xBF/', '', $line) : $line;
            $value = trim((string) (str_getcsv($line, ',', '"', '\\')[0] ?? ''));

            if ($index === 0 && $value === 'intake_id') {
                continue;
            }

            if (! ctype_digit($value)) {
                $this->error('The manifest has an invalid intake ID on line '.($index + 1).'.');

                return null;
            }

            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }
}
