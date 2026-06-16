<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceReportSummarySourceTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private Tenant $tenant;
    private PosTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['trade_name' => 'Report Tenant']);
        $this->terminal = PosTerminal::factory()->create(['tenant_id' => $this->tenant->id]);

        if (class_exists(\Spatie\Permission\Models\Role::class)) {
            \Spatie\Permission\Models\Role::firstOrCreate([
                'name' => 'admin',
                'guard_name' => 'web',
            ]);
        }

        $this->adminUser = User::factory()->create();
        if (method_exists($this->adminUser, 'assignRole')) {
            $this->adminUser->assignRole('admin');
        }
    }

    public function test_finance_report_uses_raw_transactions_when_daily_summary_refresh_is_incomplete(): void
    {
        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_timestamp' => '2026-06-15 09:01:00',
            'gross_sales' => 35.00,
            'net_sales' => 31.25,
            'vat_amount' => 3.75,
            'vatable_sales' => 31.25,
            'validation_status' => 'VALID',
        ]);

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/reports/data?month=2026-06&tenant=' . $this->tenant->id);

        $response->assertOk();
        $this->assertSame('raw_transactions', $response->json('source'));
        $this->assertEquals(35.00, (float) $response->json('totals.gross_sales'));
    }

    public function test_finance_report_uses_daily_summaries_only_when_every_requested_date_is_refreshed(): void
    {
        DB::table('daily_transaction_summaries')->insert([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'business_date' => '2026-06-15',
            'transaction_count' => 1,
            'unique_receipts' => 1,
            'gross_sales' => 35.00,
            'net_sales' => 31.25,
            'vatable_sales' => 31.25,
            'vat_amount' => 3.75,
            'sc_vat_exempt_sales' => 0,
            'refund_amount' => 0,
            'promo_with_approval' => 0,
            'promo_without_approval' => 0,
            'employee_discount' => 0,
            'senior_discount' => 0,
            'pwd_discount' => 0,
            'vip_discount' => 0,
            'regular_discount' => 0,
            'other_tax' => 0,
            'service_charge_distributed' => 0,
            'service_charge_retained' => 0,
            'refreshed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($day = 1; $day <= 30; $day++) {
            DB::table('report_refresh_states')->insert([
                'report_type' => 'daily_transaction_summaries',
                'tenant_id' => null,
                'business_date' => sprintf('2026-06-%02d', $day),
                'status' => 'completed',
                'refreshed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/reports/data?month=2026-06&tenant=' . $this->tenant->id);

        $response->assertOk();
        $this->assertSame('daily_transaction_summaries', $response->json('source'));
        $this->assertEquals(35.00, (float) $response->json('totals.gross_sales'));
    }
}
