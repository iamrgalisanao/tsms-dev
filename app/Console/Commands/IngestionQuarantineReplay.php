<?php

namespace App\Console\Commands;

use App\Models\IngestionQuarantine;
use App\Services\PayloadChecksumService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class IngestionQuarantineReplay extends Command
{
    protected $signature = 'ingestion:quarantine:replay
        {id : Quarantine record id}
        {--mark-ready : Mark payload as ready for manual replay after diagnostics pass}
        {--execute : Reserved for future authenticated replay; currently disabled unless explicitly configured}';

    protected $description = 'Run checksum diagnostics for a quarantined payload and optionally mark it replay-ready';

    public function handle(PayloadChecksumService $checksumService): int
    {
        $row = IngestionQuarantine::find($this->argument('id'));

        if (! $row) {
            $this->error('Quarantine record not found.');
            return self::FAILURE;
        }

        $row->increment('attempts');
        $payload = json_decode($row->payload, true);

        if (! is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            $row->update([
                'status' => IngestionQuarantine::STATUS_FAILED,
                'metadata' => array_merge($row->metadata ?? [], [
                    'last_replay_error' => 'Invalid JSON payload: ' . json_last_error_msg(),
                    'last_replay_at' => now()->toISOString(),
                ]),
            ]);
            $this->error('Payload is not valid JSON: ' . json_last_error_msg());
            return self::FAILURE;
        }

        $result = $checksumService->validateSubmissionChecksums($payload);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $metadata = array_merge($row->metadata ?? [], [
            'last_replay_at' => now()->toISOString(),
            'last_replay_valid' => $result['valid'],
            'last_replay_checksum_version' => $result['checksum_version'] ?? null,
            'last_replay_errors' => $result['errors'] ?? [],
            'last_replay_diagnostics' => $result['diagnostics'] ?? [],
        ]);

        $status = $result['valid'] && $this->option('mark-ready')
            ? IngestionQuarantine::STATUS_REPLAY_READY
            : IngestionQuarantine::STATUS_INSPECTED;

        if ($this->option('execute')) {
            $allowed = (bool) config('ingestion.quarantine.allow_replay_execute', false);
            if (! $allowed) {
                $metadata['last_replay_error'] = 'Authenticated replay execution is disabled.';
                $row->update(['status' => $status, 'metadata' => $metadata]);
                $this->warn('Authenticated replay execution is disabled. Use --mark-ready for manual replay handoff.');
                return self::FAILURE;
            }

            Log::warning('ingestion:quarantine:replay execute requested but no authenticated replay adapter is configured', [
                'quarantine_id' => $row->id,
                'submission_uuid' => $row->submission_uuid,
            ]);
            $this->warn('No authenticated replay adapter is configured yet.');
        }

        $row->update([
            'status' => $status,
            'metadata' => $metadata,
        ]);

        return $result['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
