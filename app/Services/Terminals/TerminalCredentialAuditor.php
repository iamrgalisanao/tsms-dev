<?php

namespace App\Services\Terminals;

use App\Models\AuditLog;
use App\Models\PosTerminal;

/**
 * Writes admin terminal-credential lifecycle events to the shared audit_logs
 * ledger (the same table surfaced via DashboardController::apiAuditLogs).
 *
 * Callers must snapshot token metadata BEFORE deleting Sanctum token rows and
 * must invoke record() inside the same DB transaction as the mutation, so a
 * failed rotation leaves neither a half-rotated terminal nor an orphan audit row.
 *
 * Plaintext tokens are never accepted: keys that look like secrets are stripped
 * defensively before persistence.
 */
class TerminalCredentialAuditor
{
    public const ACTION_REGISTERED = 'terminal.registered';

    public const ACTION_TOKEN_ROTATED = 'terminal.token.rotated';

    public const ACTION_TOKEN_REVOKED = 'terminal.token.revoked';

    public const ACTION_REACTIVATED = 'terminal.reactivated';

    public const ACTION_EXPIRY_UPDATED = 'terminal.expiry_updated';

    /**
     * Metadata keys that are always safe: forensic counters/handles that mention
     * "token" but never carry the credential itself. Compared after normalisation
     * (lower-case, non-alphanumerics removed), so `tokens_deleted` == `tokensDeleted`.
     */
    private const ALLOWED_KEYS = [
        'tokenids',
        'tokenidsdeleted',
        'tokennames',
        'tokens',
        'tokensdeleted',
        'tokensdeletedcount',
        'tokensissued',
        'tokensrevoked',
        'tokencount',
    ];

    /**
     * Substrings that mark a key as potentially carrying a credential. Matched
     * against the normalised key so `accessToken`, `plain_text_token`,
     * `BearerToken` and `X-Authorization` are all caught.
     */
    private const DENIED_FRAGMENTS = [
        'token',
        'secret',
        'password',
        'passwd',
        'authorization',
        'cookie',
        'plaintext',
        'bearer',
        'apikey',
        'credential',
        'headers',
    ];

    /**
     * Capture non-secret metadata for every Sanctum token currently attached to the terminal.
     *
     * @return array<int, array<string, mixed>>
     */
    public function snapshotTokens(PosTerminal $terminal): array
    {
        return $terminal->tokens()
            ->orderBy('created_at')
            ->get(['id', 'name', 'abilities', 'created_at', 'last_used_at', 'expires_at'])
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'created_at' => optional($token->created_at)->toISOString(),
                'last_used_at' => optional($token->last_used_at)->toISOString(),
                'expires_at' => optional($token->expires_at)->toISOString(),
            ])
            ->all();
    }

    /**
     * Persist one audit row for a terminal credential event.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function record(
        string $actionType,
        PosTerminal $terminal,
        array $metadata = [],
        array $oldValues = [],
        array $newValues = [],
        ?string $message = null
    ): AuditLog {
        $request = request();

        $baseMetadata = [
            'tenant_id' => $terminal->tenant_id,
            'serial_number' => $terminal->serial_number,
            'actor_guard' => $this->resolveActorGuard(),
            // user_id is polymorphic across guards (User vs PosTerminal); record the type explicitly.
            'actor_type' => auth()->check() ? class_basename(auth()->user()) : null,
        ];

        return AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $actionType,
            'action_type' => $actionType,
            'resource_type' => PosTerminal::class,
            'resource_id' => (string) $terminal->getKey(),
            'ip_address' => $request?->ip(),
            'message' => $message,
            'old_values' => $this->sanitize($oldValues),
            'new_values' => $this->sanitize($newValues),
            'metadata' => $this->sanitize(array_merge($baseMetadata, $metadata)),
            'logged_at' => now(),
        ]);
    }

    /**
     * Recursively drop any key that looks like it could carry a credential.
     *
     * Token *ids* and *names* are preserved because they are the forensic handle
     * for a deleted row; only keys that look like the credential itself are removed.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function sanitize(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->looksSecret($key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }

    private function looksSecret(string $key): bool
    {
        $normalized = preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? '';

        if (in_array($normalized, self::ALLOWED_KEYS, true)) {
            return false;
        }

        foreach (self::DENIED_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The guard that authenticated the current request. `auth:sanctum` calls
     * Auth::shouldUse(), so the default driver reflects the resolving guard
     * without re-probing every configured guard (which would re-run Sanctum's
     * token lookup and side effects for bearer-authenticated requests).
     */
    private function resolveActorGuard(): ?string
    {
        if (! auth()->check()) {
            return null;
        }

        return auth()->getDefaultDriver();
    }
}
