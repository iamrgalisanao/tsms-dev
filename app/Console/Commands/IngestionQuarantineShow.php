<?php

namespace App\Console\Commands;

use App\Models\IngestionQuarantine;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class IngestionQuarantineShow extends Command
{
    protected $signature = 'ingestion:quarantine:show {id} {--full : Show the full raw payload}';

    protected $description = 'Show a quarantined payload with metadata';

    public function handle(): int
    {
        $row = IngestionQuarantine::find($this->argument('id'));

        if (! $row) {
            $this->error('Quarantine record not found.');
            return self::FAILURE;
        }

        $this->line('ID: ' . $row->id);
        $this->line('submission_uuid: ' . ($row->submission_uuid ?? '-'));
        $this->line('tenant_id: ' . ($row->tenant_id ?? '-'));
        $this->line('terminal_id: ' . ($row->terminal_id ?? '-'));
        $this->line('status: ' . $row->status);
        $this->line('attempts: ' . $row->attempts);
        $this->line('received checksum: ' . ($row->payload_checksum_received ?? '-'));
        $this->line('computed checksum: ' . ($row->payload_checksum_computed ?? '-'));
        $this->line('created_at: ' . optional($row->created_at)->toDateTimeString());

        $this->line('');
        $this->line('metadata:');
        $this->line(json_encode($row->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->line('');
        if ($this->option('full')) {
            if (! config('ingestion.quarantine.allow_show_full_payload', false) && ! $this->confirm('Full payload display may expose sensitive data. Continue?')) {
                return self::FAILURE;
            }

            $this->line('payload:');
            $this->line($row->payload);
            return self::SUCCESS;
        }

        $decoded = json_decode($row->payload, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $this->line('payload (redacted):');
            $this->line(json_encode($this->redact($decoded), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('payload (truncated):');
            $this->line(Str::limit($row->payload, 1200));
        }

        return self::SUCCESS;
    }

    private function redact(array $payload): array
    {
        $sensitive = ['authorization', 'card_number', 'card_pan', 'cvv', 'secret', 'token', 'pan', 'expiry'];

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $payload[$key] = 'REDACTED';
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->redact($value);
            } elseif (is_string($value) && strlen($value) > 240) {
                $payload[$key] = substr($value, 0, 240) . '...';
            }
        }

        return $payload;
    }
}
