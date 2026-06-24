<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

    public function test_finance_report_recovers_discount_columns_from_original_payload(): void
    {
        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_timestamp' => '2026-06-15 09:01:00',
            'gross_sales' => 120.00,
            'net_sales' => 100.00,
            'vat_amount' => 10.71,
            'vatable_sales' => 89.29,
            'senior_discount' => 0,
            'pwd_discount' => 0,
            'promo_discount' => 0,
            'promo_status' => 'WITH_APPROVAL',
            'validation_status' => 'VALID',
            'original_payload' => json_encode([
                'adjustments' => [
                    ['adjustment_type' => 'senior_discount', 'amount' => '12.50'],
                    ['adjustment_type' => 'pwd_discount', 'amount' => '7.25'],
                ],
            ]),
        ]);

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/reports/data?month=2026-06&tenant=' . $this->tenant->id);

        $response->assertOk();
        $this->assertSame('raw_transactions', $response->json('source'));
        $this->assertEquals(12.50, (float) $response->json('totals.senior_discount'));
        $this->assertEquals(7.25, (float) $response->json('totals.pwd_discount'));
        $this->assertEquals(19.75, (float) $response->json('totals.senior_pwd'));
    }

    public function test_finance_report_groups_utc_timestamps_by_manila_business_day(): void
    {
        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_timestamp' => '2026-06-17 22:03:54',
            'gross_sales' => 120.00,
            'net_sales' => 107.14,
            'vat_amount' => 12.86,
            'vatable_sales' => 107.14,
            'validation_status' => 'VALID',
        ]);

        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_timestamp' => '2026-06-17 15:59:59',
            'gross_sales' => 99.00,
            'net_sales' => 88.39,
            'vat_amount' => 10.61,
            'vatable_sales' => 88.39,
            'validation_status' => 'VALID',
        ]);

        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/reports/data?month=2026-06&tenant=' . $this->tenant->id);

        $response->assertOk();
        $this->assertEquals(99.00, (float) $response->json('daily_totals.2026-06-17.gross_sales'));
        $this->assertEquals(120.00, (float) $response->json('daily_totals.2026-06-18.gross_sales'));
    }

    public function test_finance_report_aligns_gross_sales_with_transaction_summary_formula(): void
    {
        $transaction = Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_timestamp' => '2026-06-22 09:01:00',
            'gross_sales' => 98663.86,
            'net_sales' => 99149.26,
            'vatable_sales' => 83846.90,
            'sc_vat_exempt_sales' => 4758.78,
            'vat_amount' => 10061.63,
            'senior_discount' => 235.69,
            'pwd_discount' => 716.05,
            'validation_status' => 'VALID',
            'original_payload' => json_encode([
                'taxes' => [
                    ['tax_type' => 'LOCAL_TAX', 'amount' => '1515.28'],
                ],
            ]),
        ]);

        DB::table('transaction_adjustments')->insert([
            'transaction_pk' => $transaction->id,
            'adjustment_type' => 'employee_discount',
            'amount' => 940.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/reports/data?month=2026-06&tenant=' . $this->tenant->id);

        $response->assertOk();
        $this->assertSame('raw_transactions', $response->json('source'));
        $this->assertEquals(102074.33, (float) $response->json('daily_totals.2026-06-22.gross_sales'));
        $this->assertEquals(102074.33, (float) $response->json('totals.gross_sales'));
        $this->assertEquals(1515.28, (float) $response->json('totals.other_tax'));
    }

    public function test_finance_report_uses_daily_summaries_only_when_every_requested_date_is_refreshed(): void
    {
        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_timestamp' => '2026-06-15 09:01:00',
            'gross_sales' => 35.00,
            'net_sales' => 31.25,
            'vat_amount' => 3.75,
            'vatable_sales' => 31.25,
            'senior_discount' => 0,
            'pwd_discount' => 0,
            'validation_status' => 'VALID',
            'original_payload' => json_encode([
                'adjustments' => [
                    ['adjustment_type' => 'senior_discount', 'amount' => '4.00'],
                    ['adjustment_type' => 'pwd_discount', 'amount' => '3.00'],
                ],
            ]),
        ]);

        DB::table('daily_transaction_summaries')->insert([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'business_date' => '2026-06-15',
            'transaction_count' => 1,
            'unique_receipts' => 1,
            'gross_sales' => 42.00,
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
            'other_tax' => 7.00,
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
        $this->assertEquals(49.00, (float) $response->json('totals.gross_sales'));
        $this->assertEquals(4.00, (float) $response->json('totals.senior_discount'));
        $this->assertEquals(3.00, (float) $response->json('totals.pwd_discount'));
    }

    public function test_finance_user_can_download_cmsr_excel_export(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'finance',
            'guard_name' => 'web',
        ]);

        $financeUser = User::factory()->create();
        $financeUser->assignRole('finance');

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

        Sanctum::actingAs($financeUser);

        $response = $this->get('/finance/reports/export?year=2026&month=06&tenant=' . $this->tenant->id);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('content-disposition');
        $this->assertStringStartsWith('PK', $response->streamedContent());
    }

    public function test_cmsr_excel_export_uses_same_daily_summary_source_as_web_report(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'finance',
            'guard_name' => 'web',
        ]);

        $financeUser = User::factory()->create();
        $financeUser->assignRole('finance');

        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_timestamp' => '2026-06-15 09:01:00',
            'gross_sales' => 35.00,
            'net_sales' => 31.25,
            'vat_amount' => 3.75,
            'vatable_sales' => 31.25,
            'senior_discount' => 0,
            'pwd_discount' => 0,
            'validation_status' => 'VALID',
            'original_payload' => json_encode([
                'adjustments' => [
                    ['adjustment_type' => 'senior_discount', 'amount' => '4.00'],
                    ['adjustment_type' => 'pwd_discount', 'amount' => '3.00'],
                ],
            ]),
        ]);

        DB::table('daily_transaction_summaries')->insert([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'business_date' => '2026-06-15',
            'transaction_count' => 1,
            'unique_receipts' => 1,
            'gross_sales' => 42.00,
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
            'other_tax' => 7.00,
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

        Sanctum::actingAs($financeUser);

        $response = $this->get('/finance/reports/export?year=2026&month=06&tenant=' . $this->tenant->id);

        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'cmsr-export-');
        file_put_contents($tmp, $response->streamedContent());

        try {
            $sheet = IOFactory::load($tmp)->getActiveSheet();

            $this->assertEquals(31.25, (float) $sheet->getCell('B31')->getCalculatedValue());
            $this->assertEquals(4.00, (float) $sheet->getCell('H31')->getCalculatedValue());
            $this->assertEquals(3.00, (float) $sheet->getCell('I31')->getCalculatedValue());
            $this->assertEquals(7.00, (float) $sheet->getCell('K31')->getCalculatedValue());
            $this->assertEquals(49.00, (float) $sheet->getCell('N31')->getCalculatedValue());
            $this->assertEquals(49.00, (float) $sheet->getCell('N49')->getCalculatedValue());
        } finally {
            @unlink($tmp);
        }
    }
}
