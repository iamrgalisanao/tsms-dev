<?php

namespace Tests\Feature;

use App\Http\Controllers\TerminalTokenController;
use App\Models\AuditLog;
use App\Models\PosTerminal;
use App\Models\User;
use App\Services\Terminals\TerminalCredentialAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Admin-only Terminal Token Management hardening (slice 1).
 *
 * Covers: role gating, transactional rotate/revoke/reactivate with audit rows,
 * removal of bulk rotation routes, and no credential/exception leakage.
 */
class TerminalTokenAdminTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_ADMINISTRATOR_MESSAGE = 'Contact you token administrator for new token';

    private const TOKEN_EXPIRY_ADMINISTRATOR_MESSAGE = 'Contact you token administrator to update token expiration';

    private const STATUS_ACTIVE = 1;

    private const STATUS_REVOKED = 3;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PermissionSeeder']);

        foreach ([self::STATUS_ACTIVE => 'Active', self::STATUS_REVOKED => 'Revoked'] as $id => $name) {
            DB::table('terminal_statuses')->updateOrInsert(['id' => $id], ['id' => $id, 'name' => $name]);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        // A non-admin staff role; created directly so the test doesn't depend on seeder contents.
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
    }

    // ---------------------------------------------------------------- helpers

    private function makeTerminal(int $statusId = self::STATUS_ACTIVE, bool $isActive = true): PosTerminal
    {
        return PosTerminal::factory()->create([
            'status_id' => $statusId,
            'is_active' => $isActive,
            'revoked_at' => $statusId === self::STATUS_REVOKED ? now()->subDay() : null,
        ]);
    }

    private function issueToken(PosTerminal $terminal): PersonalAccessToken
    {
        $new = $terminal->createToken('terminal-'.$terminal->serial_number, ['transaction:create', 'heartbeat:send']);

        return $new->accessToken;
    }

    private function auditRowsFor(PosTerminal $terminal, string $actionType)
    {
        return AuditLog::where('resource_type', PosTerminal::class)
            ->where('resource_id', (string) $terminal->id)
            ->where('action_type', $actionType)
            ->get();
    }

    // ------------------------------------------------------------------ rotate

    public function test_admin_regenerate_returns_administrator_message_without_rotating_token(): void
    {
        $terminal = $this->makeTerminal();
        $old = $this->issueToken($terminal);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/terminals/tokens/{$terminal->id}/regenerate");

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertNull($response->json('data.access_token'));
        $this->assertSame(self::TOKEN_ADMINISTRATOR_MESSAGE, $response->json('data.token_message'));
        $this->assertSame(self::TOKEN_ADMINISTRATOR_MESSAGE, $response->json('message'));

        // Existing credentials are untouched; this branch only keeps the UI
        // contract alive with an administrator message.
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $old->id]);
        $this->assertSame(1, $terminal->tokens()->count());

        $terminal->refresh();
        $this->assertSame(self::STATUS_ACTIVE, (int) $terminal->status_id);
        $this->assertTrue((bool) $terminal->is_active);

        $audit = $this->auditRowsFor($terminal, TerminalCredentialAuditor::ACTION_TOKEN_ROTATED);
        $this->assertCount(0, $audit);
    }

    public function test_regenerate_revoked_terminal_returns_administrator_message_and_does_not_reactivate_it(): void
    {
        $terminal = $this->makeTerminal(self::STATUS_REVOKED, false);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/terminals/tokens/{$terminal->id}/regenerate");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.access_token', null)
            ->assertJsonPath('data.token_message', self::TOKEN_ADMINISTRATOR_MESSAGE)
            ->assertJsonPath('message', self::TOKEN_ADMINISTRATOR_MESSAGE);

        $terminal->refresh();
        $this->assertSame(self::STATUS_REVOKED, (int) $terminal->status_id);
        $this->assertFalse((bool) $terminal->is_active);
        $this->assertNotNull($terminal->revoked_at);
        $this->assertSame(0, $terminal->tokens()->count());
        $this->assertCount(0, $this->auditRowsFor($terminal, TerminalCredentialAuditor::ACTION_TOKEN_ROTATED));
    }

    public function test_regenerate_unknown_terminal_returns_404_without_exception_details(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/terminals/tokens/999999999/regenerate');

        $response->assertNotFound()->assertJsonPath('message', 'Terminal not found.');
        $this->assertStringNotContainsString('No query results', $response->getContent());
    }

    // ------------------------------------------------------------------ revoke

    public function test_admin_revoke_deletes_tokens_marks_terminal_revoked_and_audits(): void
    {
        $terminal = $this->makeTerminal();
        $old = $this->issueToken($terminal);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/terminals/tokens/{$terminal->id}/revoke");

        $response->assertOk()->assertJsonPath('success', true);

        $terminal->refresh();
        $this->assertSame(self::STATUS_REVOKED, (int) $terminal->status_id);
        $this->assertFalse((bool) $terminal->is_active);
        $this->assertNotNull($terminal->revoked_at);
        $this->assertSame(0, $terminal->tokens()->count());

        $audit = $this->auditRowsFor($terminal, TerminalCredentialAuditor::ACTION_TOKEN_REVOKED);
        $this->assertCount(1, $audit);
        $row = $audit->first();
        $this->assertSame(1, $row->metadata['tokens_deleted_count']);
        $this->assertSame($old->id, $row->metadata['tokens_deleted'][0]['id']);
        $this->assertSame(self::STATUS_ACTIVE, $row->old_values['status_id']);
        $this->assertSame(self::STATUS_REVOKED, $row->new_values['status_id']);
    }

    public function test_revoke_refuses_already_revoked_terminal_and_preserves_original_revoked_at(): void
    {
        $terminal = $this->makeTerminal(self::STATUS_REVOKED, false);
        $originalRevokedAt = $terminal->revoked_at;

        $this->actingAs($this->admin)
            ->postJson("/api/terminals/tokens/{$terminal->id}/revoke")
            ->assertStatus(409);

        $this->assertTrue($originalRevokedAt->equalTo($terminal->fresh()->revoked_at));
        $this->assertCount(0, $this->auditRowsFor($terminal, TerminalCredentialAuditor::ACTION_TOKEN_REVOKED));
    }

    // -------------------------------------------------------------- reactivate

    public function test_reactivate_restores_revoked_terminal_without_issuing_a_token(): void
    {
        $terminal = $this->makeTerminal(self::STATUS_REVOKED, false);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/terminals/tokens/{$terminal->id}/reactivate");

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertStringNotContainsString('access_token', $response->getContent());

        $terminal->refresh();
        $this->assertSame(self::STATUS_ACTIVE, (int) $terminal->status_id);
        $this->assertTrue((bool) $terminal->is_active);
        $this->assertNull($terminal->revoked_at);
        $this->assertSame(0, $terminal->tokens()->count(), 'reactivation must not issue a credential');

        $reactivated = $this->auditRowsFor($terminal, TerminalCredentialAuditor::ACTION_REACTIVATED);
        $this->assertCount(1, $reactivated);
        $this->assertSame(0, $reactivated->first()->metadata['tokens_issued']);

        // The regenerate path is now UI-only and must not issue credentials.
        $this->actingAs($this->admin)
            ->postJson("/api/terminals/tokens/{$terminal->id}/regenerate")
            ->assertOk();
        $this->assertSame(0, $terminal->tokens()->count());
    }

    public function test_reactivate_refuses_terminal_that_is_not_revoked(): void
    {
        $terminal = $this->makeTerminal();

        $this->actingAs($this->admin)
            ->postJson("/api/terminals/tokens/{$terminal->id}/reactivate")
            ->assertStatus(409);

        $this->assertCount(0, $this->auditRowsFor($terminal, TerminalCredentialAuditor::ACTION_REACTIVATED));
    }

    // ------------------------------------------------------------------ expiry

    public function test_update_expiry_returns_administrator_message_without_changing_expiry(): void
    {
        $terminal = $this->makeTerminal();
        $terminal->forceFill(['expires_at' => now()->addDays(5)])->save();
        $previous = $terminal->expires_at->toISOString();
        $newDate = now()->addDays(60)->startOfMinute();

        $this->actingAs($this->admin)
            ->putJson("/api/terminals/{$terminal->id}/expiry", ['expires_at' => $newDate->toDateTimeString()])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', self::TOKEN_EXPIRY_ADMINISTRATOR_MESSAGE);

        $audit = $this->auditRowsFor($terminal, TerminalCredentialAuditor::ACTION_EXPIRY_UPDATED);
        $this->assertCount(0, $audit);
        $this->assertSame($previous, $terminal->fresh()->expires_at->toISOString());
    }

    // ---------------------------------------------------------------- register

    public function test_registration_returns_administrator_message_without_issuing_credential(): void
    {
        $tenant = \App\Models\Tenant::factory()->create();

        $response = $this->actingAs($this->admin)->postJson('/api/terminals', [
            'tenant_id' => $tenant->id,
            'serial_number' => 'SLICE1-'.uniqid(),
        ]);

        $response->assertCreated()->assertJsonPath('success', true);
        $this->assertNull($response->json('data.access_token'));
        $this->assertSame(self::TOKEN_ADMINISTRATOR_MESSAGE, $response->json('data.token_message'));

        $terminal = PosTerminal::findOrFail($response->json('data.terminal.id'));
        $this->assertSame(0, $terminal->tokens()->count());
        $registered = $this->auditRowsFor($terminal, TerminalCredentialAuditor::ACTION_REGISTERED);
        $this->assertCount(0, $registered);
    }

    public function test_registration_validation_failure_does_not_log_request_headers(): void
    {
        $existing = $this->makeTerminal();
        Log::spy();

        $this->actingAs($this->admin)
            ->withHeaders(['Authorization' => 'Bearer super-secret', 'Cookie' => 'session=abc'])
            ->postJson('/api/terminals', [
                'tenant_id' => $existing->tenant_id,
                'serial_number' => $existing->serial_number, // duplicate → 422
            ])
            ->assertStatus(422);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                $encoded = strtolower(json_encode($context));

                return $message === 'Terminal registration validation failed'
                    && ! array_key_exists('headers', $context)
                    && ! str_contains($encoded, 'super-secret')
                    && ! str_contains($encoded, 'session=abc');
            })
            ->once();
    }

    // ------------------------------------------------------------ authorization

    public function test_lifecycle_endpoints_require_admin_role(): void
    {
        $terminal = $this->makeTerminal();

        foreach (['regenerate', 'revoke', 'reactivate'] as $action) {
            $this->actingAs($this->manager)
                ->postJson("/api/terminals/tokens/{$terminal->id}/{$action}")
                ->assertForbidden();
        }

        $this->assertSame(0, $terminal->tokens()->count());
        $this->assertSame(self::STATUS_ACTIVE, (int) $terminal->fresh()->status_id);
    }

    public function test_lifecycle_endpoints_require_authentication(): void
    {
        $terminal = $this->makeTerminal();

        foreach (['regenerate', 'revoke', 'reactivate'] as $action) {
            $this->postJson("/api/terminals/tokens/{$terminal->id}/{$action}")
                ->assertUnauthorized();
        }
    }

    // -------------------------------------------------------- bulk route removal

    public function test_bulk_generate_all_routes_and_handler_are_removed(): void
    {
        $this->assertFalse(Route::has('terminal-tokens.generate-all'));
        $this->assertFalse(method_exists(TerminalTokenController::class, 'generateTokensForAllTerminals'));

        $registeredUris = collect(Route::getRoutes()->getRoutes())->map->uri()->all();
        $this->assertNotContains('terminal-tokens/generate-all', $registeredUris);
        $this->assertNotContains('api/v1/terminals/generate-all-tokens', $registeredUris);
    }

    public function test_bulk_generate_all_http_paths_no_longer_rotate_anything(): void
    {
        $terminal = $this->makeTerminal();
        $token = $this->issueToken($terminal);

        // API path: no fallback route under /api, so this must be a hard 404/405.
        $api = $this->actingAs($this->admin)->postJson('/api/v1/terminals/generate-all-tokens');
        $this->assertContains($api->getStatusCode(), [404, 405], 'bulk API route must not exist');

        // Web path: routes/web.php has a catch-all SPA fallback, so the strongest
        // HTTP-level assertion is "not a JSON success and nothing was rotated".
        $web = $this->actingAs($this->admin)->post('/terminal-tokens/generate-all');
        $this->assertStringStartsWith('text/html', (string) $web->headers->get('content-type'));
        $this->assertNull(json_decode($web->getContent()), 'web bulk path must not return a JSON payload');
        $this->assertDoesNotMatchRegularExpression('/\\d+\\|[A-Za-z0-9]{40}/', $web->getContent(), 'no Sanctum-shaped token in response');

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
        $this->assertSame(1, $terminal->tokens()->count());
    }
}
