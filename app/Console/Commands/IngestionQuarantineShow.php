<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\IngestionQuarantine;
use Illuminate\Support\Str;

class IngestionQuarantineShow extends Command
{
    protected $signature = 'ingestion:quarantine:show {id} {--force}';

    protected $description = 'Show a quarantined payload (redacts by default)';

    public function handle(): int
    {
        $id = $this->argument('id');
        $force = $this->option('force');

        $row = IngestionQuarantine::find($id);
        if (!$row) {
            $this->error("Quarantine record not found: {$id}");
            return 1;
        }

        $this->info("Quarantine id: {$row->id}");
        $this->line('submission_uuid: ' . ($row->submission_uuid ?? '-'));
        $this->line('tenant_id: ' . ($row->tenant_id ?? '-'));
        $this->line('terminal_id: ' . ($row->terminal_id ?? '-'));
        $this->line('status: ' . ($row->status ?? '-'));
        $this->line('attempts: ' . ($row->attempts ?? 0));
        $this->line('created_at: ' . ($row->created_at ? $row->created_at->toDateTimeString() : '-'));

        $payload = $row->payload;
        if (!$force) {
            // try to redact obvious sensitive fields if payload is JSON
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $redacted = $this->redactPayload($decoded);
                $this->line('payload (redacted JSON):');
                $this->line(json_encode($redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                // not JSON or decode failed; print truncated
                $this->line('payload (truncated):');
                $this->line(Str::limit($payload, 1000));
                $this->line('Use --force to view full payload');
            }
        } else {
            // Additional policy check: full-payload display may be disabled
            if (!config('ingestion.quarantine.allow_show_full_payload', false)) {
                $this->warn('Full payload display is restricted by configuration.');
                if (!$this->confirm('Are you sure you want to display full payload? This may expose sensitive data.')) {
                    $this->line('Aborted');
                    return 1;
                }
            }

            $this->line('payload (full):');
            $this->line($payload);
        }

        return 0;
    }

    private function redactPayload(array $p): array
    {
        $sensitiveKeys = ['card_number', 'card_pan', 'cvv', 'secret', 'token', 'pan', 'expiry'];
        $clean = [];
        foreach ($p as $k => $v) {
            if (in_array(strtolower($k), $sensitiveKeys, true)) {
                $clean[$k] = 'REDACTED';
                continue;
            }
            if (is_string($v) && strlen($v) > 200) {
                $clean[$k] = substr($v, 0, 200) . '...';
                continue;
            }
            if (is_array($v)) {
                $clean[$k] = $this->redactPayload($v);
                continue;
            }
            $clean[$k] = $v;
        }
        return $clean;
    }
}
