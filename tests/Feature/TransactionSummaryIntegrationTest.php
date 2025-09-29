<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\TransactionTax;
use App\Models\TransactionAdjustment;
use App\Helpers\FormatHelper;
use App\Models\User;
use Spatie\Permission\Models\Role;

class TransactionSummaryIntegrationTest extends TestCase
{
    public function test_summary_route_renders_formatted_values()
    {
        // Create a transaction that will be included in summary
        $tx = Transaction::factory()->create([
            'tenant_id' => 1,
            'terminal_id' => 1,
            'gross_sales' => 120.00,
            'net_sales' => 110.00,
            'refund_amount' => 0.00,
        ]);

        TransactionTax::create([
            'transaction_pk' => $tx->id,
            'tax_type' => 'VAT',
            'amount' => 12.00,
        ]);

        TransactionAdjustment::create([
            'transaction_pk' => $tx->id,
            'adjustment_type' => 'promo_discount',
            'amount' => 5.00,
        ]);

        // Create an admin user and authenticate so RBAC middleware allows access
        $admin = User::factory()->create([
            'name' => 'Test Admin',
            'email' => 'admin-summary-test@example.com',
        ]);

        // Ensure the 'admin' role exists and assign it
        if (class_exists(Role::class)) {
            Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
            if (method_exists($admin, 'assignRole')) {
                $admin->assignRole('admin');
            }
        }

        // Authenticate as the admin user (web guard)
        $this->actingAs($admin);

        // Hit the summary route
        $response = $this->get(route('transactions.logs.summary'));

        $response->assertStatus(200);

        // Expect formatted strings in the HTML
        $response->assertSee(FormatHelper::formatCurrency(120.00));
        $response->assertSee(FormatHelper::formatCurrency(12.00));
        $response->assertSee(FormatHelper::formatCurrency(5.00));
    }
}
