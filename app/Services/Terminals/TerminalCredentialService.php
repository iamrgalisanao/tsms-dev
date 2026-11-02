<?php

namespace App\Services\Terminals;

use App\Exceptions\TerminalNotEligibleException;
use App\Exceptions\TerminalStateConflictException;
use App\Models\PosTerminal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single owner of the POS terminal credential lifecycle.
 *
 * Every path that creates or deletes a terminal's Sanctum token goes through
 * this service so that:
 *  - the ability set is defined exactly once (PosTerminal::TOKEN_ABILITIES);
 *  - state checks run under a row lock inside the same transaction as the
 *    mutation, so a concurrent revoke/rotate cannot interleave;
 *  - token metadata is snapshotted before deletion and the audit row is written
 *    in the same transaction, so a failed issuance rolls everything back.
 *
 * Lifecycle states are the terminal_statuses ids used by the rest of the app.
 */
class TerminalCredentialService
{
    public const STATUS_ACTIVE = 1;

    public const STATUS_REVOKED = 3;

    /** Days of validity granted to the terminal record on admin rotation. */
    public const ROTATION_VALIDITY_DAYS = 30;

    public function __construct(private readonly TerminalCredentialAuditor $auditor) {}

    /**
     * The one ability list every terminal credential is issued with.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return PosTerminal::TOKEN_ABILITIES;
    }

    /**
     * Create a terminal and issue its first credential (admin registration).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function register(array $attributes): IssuedCredential
    {
        return DB::transaction(function () use ($attributes) {
            $terminal = PosTerminal::create(array_merge($attributes, [
                'status_id' => self::STATUS_ACTIVE,
                'is_active' => true,
            ]));

            $credential = $this->issue($terminal);

            $this->auditor->record(
                TerminalCredentialAuditor::ACTION_REGISTERED,
                $terminal,
                ['tokens_issued' => 1, 'abilities' => $credential->abilities],
                [],
                ['status_id' => self::STATUS_ACTIVE, 'is_active' => true],
                'Terminal registered by admin with initial credential'
            );

            return $credential;
        });
    }

    /**
     * Admin rotation: refuse revoked terminals, mark the terminal active with a
     * fresh validity window, replace all tokens with one new credential.
     *
     * @throws TerminalStateConflictException when the terminal is revoked
     * @throws ModelNotFoundException
     */
    public function rotate(int|string $terminalId, string $via = 'admin.regenerate'): IssuedCredential
    {
        return DB::transaction(function () use ($terminalId, $via) {
            $terminal = $this->lock($terminalId);

            if ($this->isRevoked($terminal)) {
                throw new TerminalStateConflictException(
                    'Terminal is revoked. Reactivate it before regenerating a token.'
                );
            }

            $previousTokens = $this->auditor->snapshotTokens($terminal);
            $previousState = $this->stateOf($terminal);

            $update = ['status_id' => self::STATUS_ACTIVE, 'is_active' => true];
            if (Schema::hasColumn('pos_terminals', 'expires_at')) {
                $update['expires_at'] = now()->addDays(self::ROTATION_VALIDITY_DAYS);
            }
            $terminal->update($update);

            $terminal->tokens()->delete();
            $credential = $this->issue($terminal);

            $this->auditor->record(
                TerminalCredentialAuditor::ACTION_TOKEN_ROTATED,
                $terminal,
                [
                    'tokens_deleted_count' => count($previousTokens),
                    'tokens_deleted' => $previousTokens,
                    'tokens_issued' => 1,
                    'abilities' => $credential->abilities,
                    'via' => $via,
                ],
                $previousState,
                $this->stateOf($terminal),
                'Terminal credential rotated by admin'
            );

            return $credential;
        });
    }

    /**
     * Programmatic issuance (v1 admin API): the terminal must already be active;
     * no status or expiry is changed. Existing tokens are replaced.
     *
     * @throws TerminalNotEligibleException when revoked or inactive (HTTP 403 semantics)
     * @throws ModelNotFoundException
     */
    public function issueForActiveTerminal(int|string $terminalId, string $via = 'v1.generate-token'): IssuedCredential
    {
        return DB::transaction(function () use ($terminalId, $via) {
            $terminal = $this->lock($terminalId);

            if ($this->isRevoked($terminal)) {
                throw new TerminalNotEligibleException('Cannot generate token for revoked terminal');
            }

            if ((int) $terminal->status_id !== self::STATUS_ACTIVE || ! $terminal->is_active) {
                throw new TerminalNotEligibleException('Cannot generate token for inactive terminal');
            }

            $previousTokens = $this->auditor->snapshotTokens($terminal);

            $terminal->tokens()->delete();
            $credential = $this->issue($terminal);

            $this->auditor->record(
                TerminalCredentialAuditor::ACTION_TOKEN_ROTATED,
                $terminal,
                [
                    'tokens_deleted_count' => count($previousTokens),
                    'tokens_deleted' => $previousTokens,
                    'tokens_issued' => 1,
                    'abilities' => $credential->abilities,
                    'via' => $via,
                ],
                [],
                [],
                'Terminal credential issued via v1 admin API'
            );

            return $credential;
        });
    }

    /**
     * POS self-authentication (/v1/auth/terminal, /v1/auth/refresh): the terminal
     * has already proven possession of its api_key or a live token. The terminal
     * row is re-read under a row lock and its validity re-checked inside the
     * transaction, so a concurrent admin revoke/expiry change cannot interleave
     * between the controller's check and token creation. Replaces all tokens
     * with one new credential. No audit row and no state change (behaviour
     * identical to the previous PosTerminal::generateAccessToken()).
     *
     * @throws TerminalNotEligibleException when the terminal is no longer active/valid under lock
     * @throws ModelNotFoundException
     */
    public function issueForAuthentication(PosTerminal $terminal): IssuedCredential
    {
        return DB::transaction(function () use ($terminal) {
            $fresh = $this->lock($terminal->getKey());

            if (! $fresh->isActiveAndValid()) {
                throw new TerminalNotEligibleException('Terminal is no longer active or has expired');
            }

            $fresh->tokens()->delete();

            return $this->issue($fresh);
        });
    }

    /**
     * Revoke: mark the terminal revoked and delete every token.
     *
     * @return array{0: PosTerminal, 1: int} the terminal and the number of tokens deleted
     *
     * @throws TerminalStateConflictException when already revoked
     * @throws ModelNotFoundException
     */
    public function revokeAll(int|string $terminalId): array
    {
        return DB::transaction(function () use ($terminalId) {
            $terminal = $this->lock($terminalId);

            if ($this->isRevoked($terminal)) {
                throw new TerminalStateConflictException('Terminal is already revoked.');
            }

            $previousTokens = $this->auditor->snapshotTokens($terminal);
            $previousState = $this->stateOf($terminal);

            $terminal->status_id = self::STATUS_REVOKED;
            $terminal->is_active = false;
            $terminal->revoked_at = now();
            $terminal->save();

            $terminal->tokens()->delete();

            $this->auditor->record(
                TerminalCredentialAuditor::ACTION_TOKEN_REVOKED,
                $terminal,
                [
                    'tokens_deleted_count' => count($previousTokens),
                    'tokens_deleted' => $previousTokens,
                ],
                $previousState,
                $this->stateOf($terminal),
                'Terminal credentials revoked by admin'
            );

            return [$terminal, count($previousTokens)];
        });
    }

    /**
     * The only sanctioned exit from the revoked state. Issues no credential;
     * rotate() must be called afterwards to restore access.
     *
     * @throws TerminalStateConflictException when the terminal is not revoked
     * @throws ModelNotFoundException
     */
    public function reactivate(int|string $terminalId): PosTerminal
    {
        return DB::transaction(function () use ($terminalId) {
            $terminal = $this->lock($terminalId);

            if (! $this->isRevoked($terminal)) {
                throw new TerminalStateConflictException('Terminal is not revoked; nothing to reactivate.');
            }

            $previousState = $this->stateOf($terminal);

            $terminal->status_id = self::STATUS_ACTIVE;
            $terminal->is_active = true;
            $terminal->revoked_at = null;
            $terminal->save();

            $this->auditor->record(
                TerminalCredentialAuditor::ACTION_REACTIVATED,
                $terminal,
                ['tokens_issued' => 0],
                $previousState,
                $this->stateOf($terminal),
                'Revoked terminal reactivated by admin (no credential issued)'
            );

            return $terminal;
        });
    }

    /**
     * @throws ModelNotFoundException
     */
    public function updateExpiry(int|string $terminalId, \DateTimeInterface|string $expiresAt): PosTerminal
    {
        return DB::transaction(function () use ($terminalId, $expiresAt) {
            $terminal = $this->lock($terminalId);
            $previous = optional($terminal->expires_at)->toISOString();

            $terminal->expires_at = $expiresAt;
            $terminal->save();

            $this->auditor->record(
                TerminalCredentialAuditor::ACTION_EXPIRY_UPDATED,
                $terminal,
                [],
                ['expires_at' => $previous],
                ['expires_at' => optional($terminal->expires_at)->toISOString()],
                'Terminal expiry updated by admin'
            );

            return $terminal;
        });
    }

    // ------------------------------------------------------------------ internals

    /**
     * Create the Sanctum token. Callers are responsible for deleting prior tokens
     * and for the surrounding transaction/audit.
     */
    private function issue(PosTerminal $terminal): IssuedCredential
    {
        $abilities = $this->abilities();
        $name = 'terminal-'.($terminal->serial_number ?? $terminal->terminal_uid ?? $terminal->id);

        $token = $terminal->createToken($name, $abilities);

        return new IssuedCredential($terminal, $token->plainTextToken, $abilities);
    }

    /**
     * @throws ModelNotFoundException
     */
    private function lock(int|string $terminalId): PosTerminal
    {
        return PosTerminal::lockForUpdate()->findOrFail($terminalId);
    }

    private function isRevoked(PosTerminal $terminal): bool
    {
        return (int) $terminal->status_id === self::STATUS_REVOKED;
    }

    /**
     * @return array<string, mixed>
     */
    private function stateOf(PosTerminal $terminal): array
    {
        return [
            'status_id' => (int) $terminal->status_id,
            'is_active' => (bool) $terminal->is_active,
            'expires_at' => optional($terminal->expires_at)->toISOString(),
            'revoked_at' => optional($terminal->revoked_at)->toISOString(),
        ];
    }
}
