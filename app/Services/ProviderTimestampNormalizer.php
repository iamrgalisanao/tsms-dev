<?php

namespace App\Services;

use App\Models\PosTerminal;
use Illuminate\Support\Carbon;

class ProviderTimestampNormalizer
{
    public function normalize(string $timestamp, PosTerminal|int $terminal): string
    {
        $terminal = $this->resolveTerminal($terminal);
        $mode = $this->providerTimestampMode($terminal);
        $timezone = $this->providerTimestampTimezone($terminal);

        if ($mode === 'local_time_with_z') {
            return $this->parseProviderLocalTimestamp($timestamp, $timezone)
                ->utc()
                ->format('Y-m-d H:i:s');
        }

        return Carbon::parse($timestamp)->utc()->format('Y-m-d H:i:s');
    }

    private function resolveTerminal(PosTerminal|int $terminal): PosTerminal
    {
        if ($terminal instanceof PosTerminal) {
            return $terminal->relationLoaded('provider')
                ? $terminal
                : $terminal->load('provider');
        }

        $resolved = PosTerminal::with('provider')->find($terminal);

        if (! $resolved) {
            throw new \InvalidArgumentException("Terminal {$terminal} was not found for timestamp normalization.");
        }

        return $resolved;
    }

    private function providerTimestampMode(PosTerminal $terminal): string
    {
        $mode = (string) ($terminal->provider?->timestamp_mode ?? config('tsms.intake.timestamp_mode', 'true_utc'));

        return in_array($mode, ['true_utc', 'local_time_with_z'], true) ? $mode : 'true_utc';
    }

    private function providerTimestampTimezone(PosTerminal $terminal): string
    {
        $timezone = (string) ($terminal->provider?->timezone ?? config('tsms.intake.provider_timezone', 'Asia/Manila') ?: 'Asia/Manila');

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Asia/Manila';
    }

    private function parseProviderLocalTimestamp(string $timestamp, string $timezone): Carbon
    {
        $value = trim($timestamp);
        if ($value === '') {
            throw new \InvalidArgumentException('Provider timestamp is empty.');
        }

        $value = preg_replace('/\.\d+Z$/', 'Z', $value) ?? $value;
        $value = preg_replace('/Z$/', '', $value) ?? $value;
        $value = preg_replace('/([+-]\d{2}:?\d{2})$/', '', $value) ?? $value;
        $value = trim(str_replace('T', ' ', $value));

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value, $timezone);
            } catch (\Throwable) {
                continue;
            }
        }

        return Carbon::parse($value, $timezone);
    }
}
