<?php

namespace App\Services\Licensing;

use App\Models\LicenseAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LicenseAuditLogger
{
    private const REDACTED = '[redacted]';

    private const SENSITIVE_KEY_PARTS = [
        'authorization',
        'password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'private_key',
        'signature',
        'raw_fingerprint',
        'env',
        'database_url',
        'db_password',
    ];

    public function log(
        string $eventType,
        LicenseReasonCode|string|null $reasonCode = null,
        array $context = [],
        ?Request $request = null,
    ): LicenseAuditLog {
        $reason = $reasonCode instanceof LicenseReasonCode ? $reasonCode->value : $reasonCode;
        $context = $this->sanitize($context);
        $request ??= request();

        return LicenseAuditLog::create([
            'event_type' => $eventType,
            'reason_code' => $reason,
            'severity' => $context['severity'] ?? $this->severityFor($reason),
            'license_id' => $context['license_id'] ?? null,
            'client_id' => $context['client_id'] ?? null,
            'deployment_id' => $context['deployment_id'] ?? null,
            'location_code' => $context['location_code'] ?? null,
            'tenant_id' => $context['tenant_id'] ?? null,
            'terminal_id' => $context['terminal_id'] ?? null,
            'module_code' => $context['module_code'] ?? null,
            'current_fingerprint_hash' => $context['current_fingerprint_hash'] ?? null,
            'expected_fingerprint_hash' => $context['expected_fingerprint_hash'] ?? null,
            'request_method' => $request?->method(),
            'request_path' => $request?->path(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'user_id' => Auth::id(),
            'metadata' => $context,
        ]);
    }

    public function sanitize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $key => $item) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = self::REDACTED;
                continue;
            }

            $sanitized[$key] = $this->sanitize($item);
        }

        return $sanitized;
    }

    private function severityFor(?string $reasonCode): string
    {
        return match ($reasonCode) {
            LicenseReasonCode::LicenseValid->value, null => 'info',
            LicenseReasonCode::CopySuspected->value,
            LicenseReasonCode::LicenseSignatureInvalid->value,
            LicenseReasonCode::LicenseServerFingerprintMismatch->value => 'critical',
            LicenseReasonCode::LicenseExpired->value,
            LicenseReasonCode::LicenseDeploymentIdMismatch->value,
            LicenseReasonCode::LicenseEnvironmentMismatch->value => 'warning',
            default => 'notice',
        };
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = strtolower($key);

        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($normalizedKey, $part)) {
                return true;
            }
        }

        return false;
    }
}
