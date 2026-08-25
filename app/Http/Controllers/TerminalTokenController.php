<?php

namespace App\Http\Controllers;

use App\Exceptions\TerminalStateConflictException;
use App\Models\PosTerminal;
use App\Services\Terminals\TerminalCredentialAuditor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

class TerminalTokenController extends Controller
{
    /** terminal_statuses.id values used by the credential lifecycle. */
    private const STATUS_ACTIVE = 1;

    private const STATUS_REVOKED = 3;

    public function __construct(private readonly TerminalCredentialAuditor $auditor) {}

    /**
     * Update expiry date for a terminal (API)
     */
    public function updateExpiry($terminalId, Request $request)
    {
        try {
            $terminal = PosTerminal::findOrFail($terminalId);
            $validated = $request->validate([
                'expires_at' => ['required', 'date'],
            ]);

            DB::transaction(function () use ($terminal, $validated) {
                $previous = optional($terminal->expires_at)->toISOString();

                $terminal->expires_at = $validated['expires_at'];
                $terminal->save();

                $this->auditor->record(
                    TerminalCredentialAuditor::ACTION_EXPIRY_UPDATED,
                    $terminal,
                    [],
                    ['expires_at' => $previous],
                    ['expires_at' => optional($terminal->expires_at)->toISOString()],
                    'Terminal expiry updated by admin'
                );
            });

            return response()->json([
                'success' => true,
                'message' => 'Expiry date updated.',
                'terminal' => $terminal,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return $this->notFoundResponse();
        } catch (\Exception $e) {
            return $this->failureResponse($e, 'Error updating terminal expiry', ['terminal_id' => $terminalId], 'Unable to update expiry date.');
        }
    }

    /**
     * Reactivate a revoked terminal without issuing a credential.
     *
     * This is the only sanctioned path out of the revoked state; regeneration
     * deliberately refuses revoked terminals so that revocation cannot be undone
     * as a side effect of rotating a key. A new token must be requested
     * separately via regenerate once the terminal is active again.
     */
    public function reactivate($terminalId)
    {
        try {
            $terminal = DB::transaction(function () use ($terminalId) {
                $terminal = PosTerminal::lockForUpdate()->findOrFail($terminalId);

                if ((int) $terminal->status_id !== self::STATUS_REVOKED) {
                    throw new TerminalStateConflictException('Terminal is not revoked; nothing to reactivate.');
                }

                $previous = [
                    'status_id' => (int) $terminal->status_id,
                    'is_active' => (bool) $terminal->is_active,
                    'revoked_at' => optional($terminal->revoked_at)->toISOString(),
                ];

                $terminal->status_id = self::STATUS_ACTIVE;
                $terminal->is_active = true;
                $terminal->revoked_at = null;
                $terminal->save();

                $this->auditor->record(
                    TerminalCredentialAuditor::ACTION_REACTIVATED,
                    $terminal,
                    ['tokens_issued' => 0],
                    $previous,
                    ['status_id' => self::STATUS_ACTIVE, 'is_active' => true, 'revoked_at' => null],
                    'Revoked terminal reactivated by admin (no credential issued)'
                );

                return $terminal;
            });

            return response()->json([
                'success' => true,
                'message' => 'Terminal reactivated. Regenerate a token to restore access.',
                'data' => $terminal->fresh()->load('tenant:id,trade_name'),
            ]);
        } catch (TerminalStateConflictException $e) {
            return $this->conflictResponse($e->getMessage());
        } catch (ModelNotFoundException $e) {
            return $this->notFoundResponse();
        } catch (\Exception $e) {
            return $this->failureResponse($e, 'Error reactivating terminal', ['terminal_id' => $terminalId], 'Unable to reactivate terminal.');
        }
    }

    /**
     * Update admin-managed terminal metadata without rotating API credentials.
     */
    public function apiUpdateTerminal(Request $request, PosTerminal $terminal)
    {
        try {
            $validated = $request->validate([
                'tenant_id' => ['required', 'exists:tenants,id'],
                'serial_number' => ['required', 'string', 'max:255', 'unique:pos_terminals,serial_number,'.$terminal->id],
                'machine_number' => ['nullable', 'string', 'max:255'],
                'ip_address' => ['nullable', 'string', 'max:255'],
            ], [
                'serial_number.unique' => 'Terminal serial number is already registered in the system.',
                'tenant_id.exists' => 'Selected tenant does not exist.',
            ]);

            $terminal->update($validated);

            Log::info('POS terminal details updated via admin UI', [
                'terminal_id' => $terminal->id,
                'serial_number' => $terminal->serial_number,
                'tenant_id' => $terminal->tenant_id,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Terminal details updated successfully.',
                'data' => $terminal->fresh()->load('tenant:id,trade_name'),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'The provided terminal details were invalid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating POS terminal details via admin UI', [
                'terminal_id' => $terminal->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update terminal details.',
            ], 500);
        }
    }

    /**
     * API endpoint to register a new POS terminal and optionally
     * provision an initial Bearer token for it.
     */
    public function apiStore(Request $request)
    {
        try {
            $validated = $request->validate([
                'tenant_id' => ['required', 'exists:tenants,id'],
                'serial_number' => ['required', 'string', 'max:255', 'unique:pos_terminals,serial_number'],
                'machine_number' => ['nullable', 'string', 'max:255'],
                'ip_address' => ['nullable', 'string', 'max:255'],
            ], [
                'serial_number.unique' => 'Terminal serial number is already registered in the system.',
                'tenant_id.exists' => 'Selected tenant does not exist.',
            ]);

            $payload = array_merge($validated, [
                'status_id' => 1, // Active
                'is_active' => true,
                'registered_at' => now(),
                'heartbeat_threshold' => config('tsms.terminals.default_heartbeat_threshold', 300),
                'notifications_enabled' => true,
            ]);

            [$terminal, $token] = DB::transaction(function () use ($payload) {
                $terminal = PosTerminal::create($payload);
                $token = $this->generateBearerToken($terminal);

                $this->auditor->record(
                    TerminalCredentialAuditor::ACTION_REGISTERED,
                    $terminal,
                    [
                        'tokens_issued' => 1,
                        'abilities' => $this->tokenAbilities(),
                    ],
                    [],
                    ['status_id' => self::STATUS_ACTIVE, 'is_active' => true],
                    'Terminal registered by admin with initial credential'
                );

                return [$terminal, $token];
            });

            Log::info('POS terminal registered via API', [
                'terminal_id' => $terminal->id,
                'serial_number' => $terminal->serial_number,
                'tenant_id' => $terminal->tenant_id,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Terminal registered successfully',
                'data' => [
                    'terminal' => $terminal->load('tenant:id,trade_name'),
                    'access_token' => $token,
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Deliberately no raw headers here: they can carry Cookie / Authorization material.
            Log::warning('Terminal registration validation failed', [
                'errors' => $e->errors(),
                'payload' => $request->except(['api_key', 'token']),
                'request_id' => $request->header('X-Request-Id'),
                'user_agent' => $request->userAgent(),
                'user_id' => auth()->id(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The provided data was invalid. Check for duplicate serial numbers.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return $this->failureResponse($e, 'Error registering POS terminal via API', [], 'Unable to register terminal.');
        }
    }

    public function revoke($terminalId)
    {
        try {
            [$terminal, $tokenCount] = $this->performRevocation($terminalId);

            return redirect()
                ->route('terminal-tokens')
                ->with('success', "All Bearer tokens ({$tokenCount}) revoked for terminal ".($terminal->terminal_uid ?? $terminal->serial_number));
        } catch (TerminalStateConflictException $e) {
            return redirect()
                ->route('terminal-tokens')
                ->with('error', $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Error revoking terminal Bearer tokens', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()
                ->route('terminal-tokens')
                ->with('error', 'Unable to revoke Bearer tokens. The error has been logged.');
        }
    }

    public function index(Request $request)
    {
        $query = PosTerminal::with([
            'tenant',
            'tokens' => function ($query) {
                $query->select('tokenable_id', 'name', 'created_at', 'last_used_at')
                    ->where('tokenable_type', 'App\Models\PosTerminal');
            },
        ]);

        // Apply filters
        $this->applyFilters($query, $request);

        // Get paginated results with configurable page size (default 10 to match UI)
        $perPage = (int) ($request->input('per_page', 10));
        if ($perPage <= 0) {
            $perPage = 10;
        }
        $terminals = $query->paginate($perPage)->appends($request->all());

        return view('dashboard.terminal-tokens', compact('terminals'));
    }

    /**
     * API version of index for React frontend
     */
    public function apiIndex(Request $request)
    {
        try {
            $query = PosTerminal::with([
                'tenant:id,trade_name',
                'tokens' => function ($query) {
                    $query->select('id', 'tokenable_id', 'name', 'created_at', 'last_used_at', 'expires_at')
                        ->where('tokenable_type', 'App\Models\PosTerminal')
                        ->orderBy('created_at', 'desc');
                },
            ]);

            $this->applyFilters($query, $request);

            $perPage = (int) ($request->input('per_page', 10));
            if ($perPage <= 0) {
                $perPage = 10;
            }

            $terminals = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $terminals->items(),
                'meta' => [
                    'current_page' => $terminals->currentPage(),
                    'last_page' => $terminals->lastPage(),
                    'per_page' => $terminals->perPage(),
                    'total' => $terminals->total(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->failureResponse($e, 'API Error fetching terminal tokens', [], 'Unable to fetch terminal tokens.');
        }
    }

    /**
     * Shared filter logic for index and apiIndex
     */
    private function applyFilters($query, Request $request)
    {
        // Global search
        if ($request->has('search') && ! empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('serial_number', 'like', "%{$search}%")
                    ->orWhere('machine_number', 'like', "%{$search}%")
                    ->orWhereHas('tenant', function ($subQ) use ($search) {
                        $subQ->where('trade_name', 'like', "%{$search}%");
                    });
            });
        }

        // Legacy/specific filters
        if ($request->has('terminal_id') && ! empty($request->terminal_id)) {
            $query->where(function ($q) use ($request) {
                $q->where('serial_number', 'like', '%'.$request->terminal_id.'%');
            });
        }

        if ($request->has('status') && ! empty($request->status)) {
            switch ($request->status) {
                case 'active':
                    $query->where('status_id', 1)->where('is_active', true);
                    break;
                case 'expired':
                    $query->where('status_id', 4);
                    break;
                case 'revoked':
                    $query->where('status_id', 3);
                    break;
                case 'inactive':
                    $query->where(function ($q) {
                        $q->where('status_id', 2)->orWhere('is_active', false);
                    });
                    break;
                case 'has_tokens':
                    $query->whereHas('tokens');
                    break;
                case 'no_tokens':
                    $query->whereDoesntHave('tokens');
                    break;
            }
        }

        if ($request->has('tenant_id') && ! empty($request->tenant_id)) {
            $query->where('tenant_id', $request->tenant_id);
        }
    }

    public function regenerate($terminalId)
    {
        try {
            $bearerToken = $this->performRegeneration($terminalId);

            return redirect()
                ->route('terminal-tokens')
                ->with('success', 'Bearer token regenerated successfully')
                ->with('bearer_token', $bearerToken);

        } catch (TerminalStateConflictException $e) {
            return redirect()
                ->route('terminal-tokens')
                ->with('error', $e->getMessage());
        } catch (\Exception $e) {
            Log::error('Error regenerating terminal Bearer token', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()
                ->route('terminal-tokens')
                ->with('error', 'Unable to regenerate Bearer token. The error has been logged.');
        }
    }

    /**
     * API version of regenerate
     */
    public function apiRegenerate($terminalId)
    {
        try {
            $bearerToken = $this->performRegeneration($terminalId);

            return response()->json([
                'success' => true,
                'message' => 'Bearer token regenerated successfully',
                'data' => [
                    'access_token' => $bearerToken,
                ],
            ]);
        } catch (TerminalStateConflictException $e) {
            return $this->conflictResponse($e->getMessage());
        } catch (ModelNotFoundException $e) {
            return $this->notFoundResponse();
        } catch (\Exception $e) {
            return $this->failureResponse($e, 'Error regenerating terminal token', ['terminal_id' => $terminalId], 'Unable to regenerate token.');
        }
    }

    /**
     * Rotate a terminal's credential: snapshot existing tokens for the audit
     * ledger, mark the terminal active, delete the old tokens and issue one new
     * token — all in a single transaction so a failure leaves the previous
     * credential state intact and explainable.
     *
     * Revoked terminals are refused; use reactivate() first.
     *
     * @throws TerminalStateConflictException
     * @throws ModelNotFoundException
     */
    private function performRegeneration($terminalId): string
    {
        return DB::transaction(function () use ($terminalId) {
            // Row lock so a concurrent revoke cannot interleave between the state check and the mutation.
            $terminal = PosTerminal::lockForUpdate()->findOrFail($terminalId);

            if ((int) $terminal->status_id === self::STATUS_REVOKED) {
                throw new TerminalStateConflictException(
                    'Terminal is revoked. Reactivate it before regenerating a token.'
                );
            }

            $previousTokens = $this->auditor->snapshotTokens($terminal);
            $previousState = [
                'status_id' => (int) $terminal->status_id,
                'is_active' => (bool) $terminal->is_active,
                'expires_at' => optional($terminal->expires_at)->toISOString(),
            ];

            $updateData = [
                'status_id' => self::STATUS_ACTIVE,
                'is_active' => true,
            ];
            if (Schema::hasColumn('pos_terminals', 'expires_at')) {
                $updateData['expires_at'] = now()->addDays(30);
            }

            $terminal->update($updateData);
            $terminal->tokens()->delete();

            $plainText = $this->generateBearerToken($terminal);

            $this->auditor->record(
                TerminalCredentialAuditor::ACTION_TOKEN_ROTATED,
                $terminal,
                [
                    'tokens_deleted_count' => count($previousTokens),
                    'tokens_deleted' => $previousTokens,
                    'tokens_issued' => 1,
                    'abilities' => $this->tokenAbilities(),
                ],
                $previousState,
                [
                    'status_id' => self::STATUS_ACTIVE,
                    'is_active' => true,
                    'expires_at' => optional($terminal->expires_at)->toISOString(),
                ],
                'Terminal credential rotated by admin'
            );

            return $plainText;
        });
    }

    /**
     * Revoke a terminal: snapshot tokens for the audit ledger, mark the terminal
     * revoked and delete every token, in one transaction.
     *
     * @return array{0: PosTerminal, 1: int}
     *
     * @throws ModelNotFoundException
     */
    private function performRevocation($terminalId): array
    {
        [$terminal, $tokenCount] = DB::transaction(function () use ($terminalId) {
            $terminal = PosTerminal::lockForUpdate()->findOrFail($terminalId);

            if ((int) $terminal->status_id === self::STATUS_REVOKED) {
                throw new TerminalStateConflictException('Terminal is already revoked.');
            }

            $previousTokens = $this->auditor->snapshotTokens($terminal);
            $previousState = [
                'status_id' => (int) $terminal->status_id,
                'is_active' => (bool) $terminal->is_active,
                'revoked_at' => optional($terminal->revoked_at)->toISOString(),
            ];

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
                [
                    'status_id' => self::STATUS_REVOKED,
                    'is_active' => false,
                    'revoked_at' => optional($terminal->revoked_at)->toISOString(),
                ],
                'Terminal credentials revoked by admin'
            );

            return [$terminal, count($previousTokens)];
        });

        Log::info('Terminal Bearer tokens revoked', [
            'terminal_id' => $terminal->id,
            'terminal_uid' => $terminal->terminal_uid ?? $terminal->serial_number,
            'tokens_revoked' => $tokenCount,
            'user_id' => auth()->id(),
        ]);

        return [$terminal, $tokenCount];
    }

    /**
     * API version of revoke
     */
    public function apiRevoke($terminalId)
    {
        try {
            [$terminal, $tokenCount] = $this->performRevocation($terminalId);

            return response()->json([
                'success' => true,
                'message' => "All tokens ({$tokenCount}) revoked for terminal ".($terminal->terminal_uid ?? $terminal->serial_number),
            ]);
        } catch (TerminalStateConflictException $e) {
            return $this->conflictResponse($e->getMessage());
        } catch (ModelNotFoundException $e) {
            return $this->notFoundResponse();
        } catch (\Exception $e) {
            return $this->failureResponse($e, 'Error revoking terminal tokens', ['terminal_id' => $terminalId], 'Unable to revoke tokens.');
        }
    }

    /**
     * Abilities granted to admin-issued terminal credentials.
     *
     * NOTE: intentionally unchanged in this hardening slice. Unifying this with
     * PosTerminal::getTokenAbilities() is deferred to the credential-service
     * extraction so POS-facing behaviour is not altered here.
     *
     * @return list<string>
     */
    private function tokenAbilities(): array
    {
        return [
            'transaction:create',
            'transaction:read',
            'heartbeat:send',
        ];
    }

    /**
     * Generate Bearer token for a terminal using Sanctum
     */
    private function generateBearerToken(PosTerminal $terminal): string
    {
        // Generate token name
        $tokenName = 'terminal-'.($terminal->serial_number ?? $terminal->terminal_uid ?? $terminal->id);

        // Create Sanctum token
        $token = $terminal->createToken($tokenName, $this->tokenAbilities());

        return $token->plainTextToken;
    }

    private function conflictResponse(string $message)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 409);
    }

    private function notFoundResponse()
    {
        return response()->json([
            'success' => false,
            'message' => 'Terminal not found.',
        ], 404);
    }

    /**
     * Log the real exception server-side and return a generic, non-leaking 500.
     *
     * @param  array<string, mixed>  $context
     */
    private function failureResponse(\Throwable $e, string $logMessage, array $context, string $publicMessage)
    {
        Log::error($logMessage, array_merge($context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]));

        return response()->json([
            'success' => false,
            'message' => $publicMessage,
        ], 500);
    }

    /**
     * Generate Bearer token via API endpoint (for programmatic access)
     */
    public function generateToken($terminalId)
    {
        try {
            $terminal = PosTerminal::findOrFail($terminalId);

            // Check if terminal is active and not revoked
            if ((int) $terminal->status_id === self::STATUS_REVOKED) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot generate token for revoked terminal',
                ], 403);
            }

            // Check if terminal is active (status_id = 1 and is_active = true)
            if ((int) $terminal->status_id !== self::STATUS_ACTIVE || ! $terminal->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot generate token for inactive terminal',
                ], 403);
            }

            $bearerToken = DB::transaction(function () use ($terminal) {
                $previousTokens = $this->auditor->snapshotTokens($terminal);

                $terminal->tokens()->delete();
                $plainText = $this->generateBearerToken($terminal);

                $this->auditor->record(
                    TerminalCredentialAuditor::ACTION_TOKEN_ROTATED,
                    $terminal,
                    [
                        'tokens_deleted_count' => count($previousTokens),
                        'tokens_deleted' => $previousTokens,
                        'tokens_issued' => 1,
                        'abilities' => $this->tokenAbilities(),
                        'via' => 'v1.generate-token',
                    ],
                    [],
                    [],
                    'Terminal credential issued via v1 admin API'
                );

                return $plainText;
            });

            Log::info('Bearer token generated via API', [
                'terminal_uid' => $terminal->terminal_uid ?? $terminal->serial_number,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'access_token' => $bearerToken,
                    'token_type' => 'Bearer',
                    'terminal_id' => $terminal->id,
                    'terminal_uid' => $terminal->terminal_uid ?? $terminal->serial_number,
                    'expires_in' => config('sanctum.expiration', 1440) * 60, // Convert minutes to seconds
                ],
            ]);

        } catch (ModelNotFoundException $e) {
            return $this->notFoundResponse();
        } catch (\Exception $e) {
            return $this->failureResponse($e, 'Error generating Bearer token via API', ['terminal_id' => $terminalId], 'Unable to generate Bearer token.');
        }
    }

    /**
     * List all tokens for a specific terminal
     */
    public function listTokens($terminalId)
    {
        try {
            $terminal = PosTerminal::with('tokens')->findOrFail($terminalId);

            $tokens = $terminal->tokens->map(function ($token) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'created_at' => $token->created_at,
                    'last_used_at' => $token->last_used_at,
                    'expires_at' => $token->expires_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'terminal_id' => $terminal->id,
                    'terminal_uid' => $terminal->terminal_uid ?? $terminal->serial_number,
                    'tokens' => $tokens,
                ],
            ]);

        } catch (ModelNotFoundException $e) {
            return $this->notFoundResponse();
        } catch (\Exception $e) {
            return $this->failureResponse($e, 'Error retrieving terminal tokens', ['terminal_id' => $terminalId], 'Unable to retrieve tokens.');
        }
    }

    // NOTE: generateTokensForAllTerminals() was removed deliberately. It rotated
    // every active terminal and returned every plaintext token in one response.
    // Bulk rotation must not be reintroduced without break-glass controls, per-
    // terminal audit rows and a narrowed scope. See tests/Feature/TerminalTokenAdminTest.

    /**
     * Introspect a token to validate and retrieve its claims.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function introspectToken(Request $request)
    {
        $raw = $request->bearerToken();
        if (! $raw) {
            return $this->invalidTokenResponse('missing');
        }

        // Sanctum tokens have the form id|plaintexttoken
        if (! str_contains($raw, '|')) {
            Log::warning('Token introspection: malformed token format');

            return $this->invalidTokenResponse('malformed');
        }

        [$id, $plain] = explode('|', $raw, 2);
        if (! ctype_digit($id) || empty($plain)) {
            Log::warning('Token introspection: invalid id or empty plain segment', ['token_id' => $id]);

            return $this->invalidTokenResponse('malformed');
        }

        $hashed = hash('sha256', $plain);

        // Query personal access token
        $pat = PersonalAccessToken::query()
            ->where('id', $id)
            ->where('token', $hashed)
            ->first();

        if (! $pat) {
            Log::info('Token introspection: token not found or hash mismatch', ['token_id' => $id]);

            return $this->invalidTokenResponse('not_found');
        }

        // Ensure tokenable is a POS terminal
        if ($pat->tokenable_type !== PosTerminal::class) {
            Log::warning('Token introspection: tokenable type mismatch', ['token_id' => $id, 'type' => $pat->tokenable_type]);

            return $this->invalidTokenResponse('wrong_type');
        }

        $terminal = PosTerminal::find($pat->tokenable_id);
        if (! $terminal) {
            Log::warning('Token introspection: terminal missing', ['token_id' => $id]);

            return $this->invalidTokenResponse('orphan');
        }

        $expired = $pat->expires_at && now()->gte($pat->expires_at);
        $revoked = property_exists($pat, 'is_revoked') ? ($pat->is_revoked ?? false) : false;
        $inactive = ! $terminal->isActiveAndValid();

        if ($expired || $revoked || $inactive) {
            Log::info('Token introspection: inactive token', [
                'token_id' => $id,
                'expired' => $expired,
                'revoked' => $revoked,
                'inactive_terminal' => $inactive,
            ]);

            return $this->invalidTokenResponse('inactive');
        }

        // Parse abilities JSON (Sanctum stores as JSON in abilities attribute)
        $abilities = $pat->abilities ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'active' => true,
                'terminal_id' => $terminal->id,
                'terminal_uid' => $terminal->terminal_uid ?? $terminal->serial_number,
                'tenant_id' => $terminal->tenant_id,
                'provider_id' => $terminal->provider_id ?? null,
                'abilities' => $abilities,
                'expires_at' => $pat->expires_at,
                'last_used_at' => $pat->last_used_at,
                'issued_at' => $pat->created_at,
            ],
        ]);
    }

    /**
     * Export terminal tokens to CSV
     */
    public function export(Request $request)
    {
        try {
            $query = PosTerminal::with([
                'tenant:id,trade_name',
                'tokens' => function ($query) {
                    $query->select('id', 'tokenable_id', 'name', 'created_at', 'last_used_at', 'expires_at')
                        ->where('tokenable_type', 'App\Models\PosTerminal')
                        ->orderBy('created_at', 'desc');
                },
            ]);

            $this->applyFilters($query, $request);

            $filename = 'terminal_tokens_'.now()->format('Ymd_His').'.csv';
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Pragma' => 'no-cache',
                'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                'Expires' => '0',
            ];

            $callback = function () use ($query) {
                $handle = fopen('php://output', 'w');
                // Header row
                fputcsv($handle, [
                    'Terminal ID',
                    'Serial Number',
                    'Machine Number',
                    'Tenant',
                    'IP Address',
                    'Status',
                    'Token Name',
                    'Token Created',
                    'Token Last Used',
                    'Token Expires',
                ]);

                // Chunk results to avoid memory exhaustion
                $query->chunk(200, function ($terminals) use ($handle) {
                    foreach ($terminals as $terminal) {
                        $status = 'Inactive';
                        if ($terminal->status_id === 1 && $terminal->is_active) {
                            $status = 'Active';
                        } elseif ($terminal->status_id === 3) {
                            $status = 'Revoked';
                        } elseif ($terminal->status_id === 4) {
                            $status = 'Expired';
                        }

                        $token = $terminal->tokens->first();

                        fputcsv($handle, [
                            $terminal->id,
                            $terminal->serial_number,
                            $terminal->machine_number ?? 'N/A',
                            $terminal->tenant ? $terminal->tenant->trade_name : 'Unassigned',
                            $terminal->ip_address ?? 'N/A',
                            $status,
                            $token ? $token->name : 'No Token',
                            $token ? $token->created_at->toISOString() : 'N/A',
                            $token && $token->last_used_at ? $token->last_used_at->toISOString() : 'N/A',
                            $token && $token->expires_at ? $token->expires_at->toISOString() : 'N/A',
                        ]);
                    }
                });

                fclose($handle);
            };

            return response()->stream($callback, 200, $headers);
        } catch (\Exception $e) {
            return $this->failureResponse($e, 'Error exporting terminal tokens', [], 'Unable to export terminal tokens.');
        }
    }

    private function invalidTokenResponse(string $reason)
    {
        // Always collapse to same outward response (avoid enumeration), include code per contract
        return response()->json([
            'success' => false,
            'code' => 'invalid_token',
            'message' => 'Invalid or expired token',
        ], 401);
    }
}
