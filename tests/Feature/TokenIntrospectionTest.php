<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TokenIntrospectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Use sqlite memory if mysql not configured
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        // Run minimal migrations dynamically if schema empty
        if (!Schema::hasTable('pos_terminals')) {
            Schema::create('pos_terminals', function($table) {
                $table->id();
                $table->string('tenant_id')->nullable();
                $table->string('serial_number')->nullable();
                $table->integer('status_id')->default(1);
                $table->boolean('is_active')->default(true);
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function($table) {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
        PosTerminal::unguard();
        $this->terminal = PosTerminal::create([
            'tenant_id' => 'T-1',
            'serial_number' => 'TERM-1',
            'status_id' => 1,
            'is_active' => true,
        ]);
        $this->token = $this->terminal->generateAccessToken();
    }

    public function test_introspection_returns_active_claims()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->getJson('/api/v1/tokens/introspect');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'active', 'terminal_id', 'terminal_uid', 'tenant_id', 'abilities'
                ]
            ]);
    }

    public function test_invalid_format_token()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalidtokenformat',
            'Accept' => 'application/json'
        ])->getJson('/api/v1/tokens/introspect');

        $response->assertStatus(401)
            ->assertJsonPath('code', 'invalid_token');
    }

    public function test_revoked_token_is_invalid()
    {
        // Revoke by deleting tokens then using stale token value
        $stale = $this->token; // capture
        $this->terminal->tokens()->delete();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $stale,
            'Accept' => 'application/json'
        ])->getJson('/api/v1/tokens/introspect');

        $response->assertStatus(401)
            ->assertJsonPath('code', 'invalid_token');
    }
}
