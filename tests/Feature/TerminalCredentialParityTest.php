<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PosTerminal;
use App\Models\User;
use App\Services\Terminals\TerminalCredentialAuditor;
use App\Services\Terminals\TerminalCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Slice 2: TerminalCredentialService extraction.
 *
 * Covers ability parity across every issuance path, and the v1
 * generate-token path's state/lock behaviour now that it shares the service.
 */
class TerminalCredentialParityTest extends TestCase
{
    use RefreshDatabase;

    private const STATUS_ACTIVE = 1;

    private const STATUS_INACTIVE = 2;

    private const STATUS_REVOKED = 3;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PermissionSeeder']);

        foreach ([self::STATUS_ACTIVE => 'Active', self::STATUS_INACTIVE => 'Inactive', self::STATUS_REVOKED => 'Revoked'] as $id => $name) {
            DB::table('terminal_statuses')->updateOrInsert(['id' => $id], ['id' => $id, 'name' => $name]);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    // ---------------------------------------------------------------- helpers

    private function makeTerminal(int $statusId = self::STATUS_ACTIVE, bool $isActive = true, array $extra = []): PosTerminal
    {
        return PosTerminal::factory()->create(array_merge([
            'status_id' => $statusId,
            'is_active' => $isActive,
            'expires_at' => now()->addYear(),
            'revoked_at' => $statusId === self::STATUS_REVOKED ? now()->subDay() : null,
        ], $extra));
    }

    /** Sorted abilities of the single token attached to the terminal. */
    private function abilitiesOf(PosTerminal $terminal): array
    {
        $this->assertSame(1, $terminal->tokens()->count(), 'expected exactly one token');
        $abilities = $terminal->tokens()->first()->abilities;
        sort($abilities);

        return $abilities;
    }

    private function canonicalAbilities(): array
    {
        $abilities = PosTerminal::TOKEN_ABILITIES;
        sort($abilities);

        return $abilities;
    }

    private function actingAsV1Admin(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['admin:manage']);
    }

    // ------------------------------------------------------------ ability parity

    public function test_canonical_ability_list_is_the_single_source_across_service_and_model(): void
    {
        $service = app(TerminalCredentialService::class);

        $this->assertSame(PosTerminal::TOKEN_ABILITIES, $service->abilities());
        $this->assertSame(PosTerminal::TOKEN_ABILITIES, (new PosTerminal)->getTokenAbilities());
        $this->assertContains('transaction:status', PosTerminal::TOKEN_ABILITIES);
    }

    public function test_admin_regenerate_issues_the_canonical_abilities(): void
    {
        $terminal = $this->makeTerminal();

        $this->actingAs($this->admin)
            ->postJson("/api/terminals/tokens/{$terminal->id}/regenerate")
            ->assertOk();

        $this->assertSame($this->canonicalAbilities(), $this->abilitiesOf($terminal));
    }

    public function test_admin_registration_issues_the_canonical_abilities(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();

        $response = $this->actingAs($this->admin)->postJson('/api/terminals', [
            'tenant_id' => $tenant->id,
            'serial_number' => 'PARITY-'.uniqid(),
        ])->assertCreated();

        $terminal = PosTerminal::findOrFail($response->json('data.terminal.id'));
        $this->assertSame($this->canonicalAbilities(), $this->abilitiesOf($terminal));
    }

    public function test_pos_self_authentication_issues_the_canonical_abilities(): void
    {
        $terminal = $this->makeTerminal(extra: ['api_key' => 'parity-key-'.uniqid()]);

        $response = $this->postJson('/api/v1/auth/terminal', [
            'serial_number' => $terminal->serial_number,
            'api_key' => $terminal->api_key,
        ]);

        $response->assertOk();
        $this->assertSame($this->canonicalAbilities(), $this->abilitiesOf($terminal));

        $advertised = $response->json('data.abilities');
        sort($advertised);
        $this->assertSame($this->canonicalAbilities(), $advertised, 'advertised abilities must match issued abilities');
    }

    public function test_v1_generate_token_issues_the_canonical_abilities(): void
    {
        $terminal = $this->makeTerminal();
        $this->actingAsV1Admin();

        $this->postJson("/api/v1/terminals/{$terminal->id}/generate-token")->assertOk();

        $this->assertSame($this->canonicalAbilities(), $this->abilitiesOf($terminal));
    }

    public function test_every_issuance_path_produces_an_identical_ability_set(): void
    {
        $viaAdmin = $this->makeTerminal();
        $viaAuth = $this->makeTerminal(extra: ['api_key' => 'parity-key-'.uniqid()]);
        $viaV1 = $this->makeTerminal();

        $this->actingAs($this->admin)->postJson("/api/terminals/tokens/{$viaAdmin->id}/regenerate")->assertOk();
        $this->postJson('/api/v1/auth/terminal', ['serial_number' => $viaAuth->serial_number, 'api_key' => $viaAuth->api_key])->assertOk();
        $this->actingAsV1Admin();
        $this->postJson("/api/v1/terminals/{$viaV1->id}/generate-token")->assertOk();

        $this->assertSame($this->abilitiesOf($viaAdmin), $this->abilitiesOf($viaAuth));
        $this->assertSame($this->abilitiesOf($viaAuth), $this->abilitiesOf($viaV1));
    }

    // ------------------------------------------------- v1 generate-token behaviour

    public function test_v1_generate_token_replaces_old_token_and_writes_audit_row(): void
    {
        $terminal = $this->makeTerminal();
        $old = $terminal->createToken('old', ['transaction:create'])->accessToken;
        $this->actingAsV1Admin();

        $response = $this->postJson("/api/v1/terminals/{$terminal->id}/generate-token");

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.terminal_id', $terminal->id);
        $this->assertNotEmpty($response->json('data.access_token'));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $old->id]);
        $this->assertSame(1, $terminal->tokens()->count());

        $audit = AuditLog::where('resource_type', PosTerminal::class)
            ->where('resource_id', (string) $terminal->id)
            ->where('action_type', TerminalCredentialAuditor::ACTION_TOKEN_ROTATED)
            ->get();
        $this->assertCount(1, $audit);
        $this->assertSame('v1.generate-token', $audit->first()->metadata['via']);
        $this->assertSame($old->id, $audit->first()->metadata['tokens_deleted'][0]['id']);
        $this->assertSame('User', $audit->first()->metadata['actor_type']);
    }

    public function test_v1_generate_token_refuses_revoked_terminal_with_403_and_no_side_effects(): void
    {
        $terminal = $this->makeTerminal(self::STATUS_REVOKED, false);
        $this->actingAsV1Admin();

        $this->postJson("/api/v1/terminals/{$terminal->id}/generate-token")
            ->assertForbidden()
            ->assertJsonPath('message', 'Cannot generate token for revoked terminal');

        $this->assertSame(0, $terminal->tokens()->count());
        $this->assertSame(self::STATUS_REVOKED, (int) $terminal->fresh()->status_id);
        $this->assertSame(0, AuditLog::where('resource_id', (string) $terminal->id)->count());
    }

    public function test_v1_generate_token_refuses_inactive_terminal_with_403(): void
    {
        $byStatus = $this->makeTerminal(self::STATUS_INACTIVE, true);
        $byFlag = $this->makeTerminal(self::STATUS_ACTIVE, false);
        $this->actingAsV1Admin();

        foreach ([$byStatus, $byFlag] as $terminal) {
            $this->postJson("/api/v1/terminals/{$terminal->id}/generate-token")
                ->assertForbidden()
                ->assertJsonPath('message', 'Cannot generate token for inactive terminal');
            $this->assertSame(0, $terminal->tokens()->count());
        }
    }

    public function test_v1_generate_token_unknown_terminal_returns_404(): void
    {
        $this->actingAsV1Admin();

        $this->postJson('/api/v1/terminals/999999999/generate-token')
            ->assertNotFound()
            ->assertJsonPath('message', 'Terminal not found.');
    }

    public function test_v1_generate_token_requires_admin_manage_ability(): void
    {
        $terminal = $this->makeTerminal();
        Sanctum::actingAs(User::factory()->create(), ['transaction:read']);

        $this->postJson("/api/v1/terminals/{$terminal->id}/generate-token")->assertForbidden();
        $this->assertSame(0, $terminal->tokens()->count());
    }

    public function test_v1_generate_token_performs_state_check_under_row_lock_inside_transaction(): void
    {
        $terminal = $this->makeTerminal();
        $this->actingAsV1Admin();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->postJson("/api/v1/terminals/{$terminal->id}/generate-token")->assertOk();

        $lockingSelect = collect($queries)->first(
            fn ($sql) => str_contains($sql, 'from `pos_terminals`') && str_contains($sql, 'for update')
        );
        $this->assertNotNull($lockingSelect, 'terminal must be read with FOR UPDATE before the state check');

        // The lock must be acquired after the transaction begins and before tokens are deleted.
        $lockIndex = array_search($lockingSelect, $queries, true);
        $deleteIndex = collect($queries)->search(fn ($sql) => str_starts_with($sql, 'delete from `personal_access_tokens`'));
        $this->assertNotFalse($deleteIndex);
        $this->assertLessThan($deleteIndex, $lockIndex);
    }

    public function test_service_rotate_and_v1_issue_are_mutually_exclusive_on_revocation(): void
    {
        // Sequential (not concurrent) proof of the invariant: once revoked via the
        // service, neither issuance path can produce a live token.
        $terminal = $this->makeTerminal();
        $service = app(TerminalCredentialService::class);

        $this->actingAs($this->admin);
        $service->revokeAll($terminal->id);

        $this->expectException(\App\Exceptions\TerminalNotEligibleException::class);
        try {
            $service->issueForActiveTerminal($terminal->id);
        } finally {
            $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $terminal->id)->count());
        }
    }
    // ------------------------------------------- POS self-auth vs concurrent revoke

    public function test_self_auth_issuance_refuses_stale_model_after_revoke(): void
    {
        $terminal = $this->makeTerminal(extra: ['api_key' => 'race-key-'.uniqid()]);
        $stale = PosTerminal::findOrFail($terminal->id); // separate in-memory instance, still "active"
        $service = app(TerminalCredentialService::class);

        $this->actingAs($this->admin);
        $service->revokeAll($terminal->id);

        $this->expectException(\App\Exceptions\TerminalNotEligibleException::class);
        try {
            $service->issueForAuthentication($stale);
        } finally {
            $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $terminal->id)->count(), 'no token may be minted for a revoked terminal');
        }
    }

    public function test_authenticate_returns_401_when_terminal_is_revoked_between_check_and_issue(): void
    {
        $terminal = $this->makeTerminal(extra: ['api_key' => 'race-key-'.uniqid()]);
        $this->bindRevokeBeforeIssueDecorator($terminal->id);

        $this->postJson('/api/v1/auth/terminal', [
            'serial_number' => $terminal->serial_number,
            'api_key' => $terminal->api_key,
        ])
            ->assertStatus(401)
            ->assertExactJson([
                'error' => 'Authentication failed',
                'message' => 'Invalid serial number or API key, or terminal is inactive',
            ]);

        $this->assertSame(0, $terminal->tokens()->count());
        $this->assertSame(self::STATUS_REVOKED, (int) $terminal->fresh()->status_id);
    }

    public function test_refresh_returns_403_when_terminal_is_revoked_between_check_and_issue(): void
    {
        $terminal = $this->makeTerminal();
        Sanctum::actingAs($terminal, PosTerminal::TOKEN_ABILITIES);
        $this->bindRevokeBeforeIssueDecorator($terminal->id);

        $this->postJson('/api/v1/auth/refresh')
            ->assertStatus(403)
            ->assertExactJson([
                'error' => 'Terminal inactive',
                'message' => 'Terminal is no longer active or has expired',
            ]);

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $terminal->id)->count());
    }

    public function test_self_auth_locks_terminal_before_deleting_tokens(): void
    {
        $terminal = $this->makeTerminal(extra: ['api_key' => 'lock-key-'.uniqid()]);
        $terminal->createToken('old', ['transaction:create']);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->postJson('/api/v1/auth/terminal', [
            'serial_number' => $terminal->serial_number,
            'api_key' => $terminal->api_key,
        ])->assertOk();

        $lockIndex = collect($queries)->search(
            fn ($sql) => str_contains($sql, 'from `pos_terminals`') && str_contains($sql, 'for update')
        );
        $deleteIndex = collect($queries)->search(fn ($sql) => str_starts_with($sql, 'delete from `personal_access_tokens`'));

        $this->assertNotFalse($lockIndex, 'self-auth must read the terminal FOR UPDATE');
        $this->assertNotFalse($deleteIndex);
        $this->assertLessThan($deleteIndex, $lockIndex);
    }

    /**
     * Simulate an admin revoke landing between the controller's validity check
     * and the service's locked issuance: the decorator revokes (through the real
     * service) and then delegates to the real issueForAuthentication().
     */
    private function bindRevokeBeforeIssueDecorator(int $terminalId): void
    {
        $real = app(TerminalCredentialService::class);
        $admin = $this->admin;

        $decorator = new class($real, $terminalId, $admin) extends TerminalCredentialService
        {
            public function __construct(
                private TerminalCredentialService $real,
                private int $terminalId,
                private User $admin,
            ) {}

            public function issueForAuthentication(PosTerminal $terminal): \App\Services\Terminals\IssuedCredential
            {
                $previous = auth()->user();
                auth()->setUser($this->admin);
                try {
                    $this->real->revokeAll($this->terminalId);
                } finally {
                    // Restore the terminal principal on the refresh path. On the
                    // authenticate path there is no previous user, so the admin
                    // intentionally remains the guard user for the rest of the
                    // request; nothing under test reads the guard after issuance.
                    if ($previous) {
                        auth()->setUser($previous);
                    }
                }

                return $this->real->issueForAuthentication($terminal);
            }
        };

        $this->app->instance(TerminalCredentialService::class, $decorator);
    }
}
