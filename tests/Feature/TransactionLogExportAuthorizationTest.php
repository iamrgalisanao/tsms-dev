<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransactionLogExportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_log_export_gate_matches_allowed_log_viewer_roles(): void
    {
        foreach (['admin', 'manager', 'finance', 'commercial'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            $user = User::factory()->create();
            $user->assignRole($roleName);

            $this->assertTrue(
                Gate::forUser($user)->allows('export-transaction-logs'),
                "{$roleName} should be allowed to export transaction logs."
            );
        }
    }

    public function test_transaction_log_export_gate_denies_unscoped_roles(): void
    {
        Role::firstOrCreate(['name' => 'tenant', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole('tenant');

        $this->assertFalse(Gate::forUser($user)->allows('export-transaction-logs'));
    }
}
