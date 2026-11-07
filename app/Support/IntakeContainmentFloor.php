<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for the temporary intake containment floor
 * (TSMS_INTAKE_CONTAINMENT_FLOOR). To be replaced by an exact hold table before
 * any recovery. See docs/FAILED_JOB_REPLAY_RUNBOOK.md section 10.
 */
final class IntakeContainmentFloor
{
    public const STATE_ABSENT = 'absent';

    public const STATE_ARMED = 'armed';

    public const STATE_INVALID = 'invalid';

    public const STATE_IGNORED = 'ignored';

    private const HONOURING_ENVIRONMENTS = ['staging', 'testing'];

    private const ALERT_INTERVAL_SECONDS = 300;

    private const FORMAT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D';

    private function __construct(
        public readonly string $state,
        public readonly ?CarbonImmutable $floor = null,
        public readonly ?string $reason = null,
    ) {}

    public static function current(): self
    {
        return self::resolve(config('tsms.intake.containment_floor'));
    }

    public static function resolve(mixed $value): self
    {
        if ($value === null || $value === '') {
            return new self(self::STATE_ABSENT);
        }

        if (! app()->environment(self::HONOURING_ENVIRONMENTS)) {
            return new self(self::STATE_IGNORED, null, 'environment_not_allowed');
        }

        $floor = is_string($value) ? self::parse($value) : null;

        if ($floor === null) {
            return new self(self::STATE_INVALID, null, 'malformed');
        }

        if ($floor->isAfter(now())) {
            return new self(self::STATE_INVALID, null, 'future');
        }

        return new self(self::STATE_ARMED, $floor);
    }

    public function isArmed(): bool
    {
        return $this->state === self::STATE_ARMED;
    }

    public function isInvalid(): bool
    {
        return $this->state === self::STATE_INVALID;
    }

    public function isIgnored(): bool
    {
        return $this->state === self::STATE_IGNORED;
    }

    /**
     * Restrict a reconciler query to rows at or after an armed floor. NULL
     * received_at never satisfies the comparison, so such rows are excluded.
     */
    public function applyScope(Builder $query): Builder
    {
        return $this->isArmed() ? $query->where('received_at', '>=', $this->floor) : $query;
    }

    /**
     * Rate-limited critical alert for a floor that is set but not usable. A
     * limiter outage must never turn into a critical per job, or into a failure
     * of the caller, so it only downgrades the alert to a warning.
     */
    public function alertIfNeeded(): void
    {
        if (! $this->isInvalid() && ! $this->isIgnored()) {
            return;
        }

        $context = [
            'state' => $this->state,
            'reason' => $this->reason,
            'environment' => app()->environment(),
        ];

        try {
            $shouldAlert = Cache::add('tsms:intake-containment-alert:'.$this->state, 1, self::ALERT_INTERVAL_SECONDS);
        } catch (\Throwable $e) {
            Log::warning('Intake containment floor alert could not be rate limited', $context + ['error' => $e->getMessage()]);

            return;
        }

        if ($shouldAlert) {
            Log::critical('Intake containment floor is set but not usable', $context);
        }
    }

    /**
     * Strict ISO-8601 parse with a mandatory offset, converted to the application
     * timezone because Connection::prepareBindings formats dates without converting.
     */
    private static function parse(string $value): ?CarbonImmutable
    {
        if (preg_match(self::FORMAT, $value) !== 1) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);

        // Overflow dates such as month 13 or Feb 30 roll over and fail the round trip.
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
            return null;
        }

        return CarbonImmutable::instance($parsed)->setTimezone(config('app.timezone'));
    }
}
