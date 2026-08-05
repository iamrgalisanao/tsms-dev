<?php

namespace Tests\Feature;

use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CommercialReportsTenantBreakdownTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private string $originalTimezone;
    private User $commercialUser;
    private Tenant $tenant;
    private PosTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Manila');
        config([
            'app.timezone' => 'Asia/Manila',
            'tsms.transaction_logs.timezone' => 'Asia/Manila',
        ]);

        Cache::flush();

        Role::firstOrCreate([
            'name' => 'commercial',
            'guard_name' => 'web',
        ]);

        $this->commercialUser = User::factory()->create();
        $this->commercialUser->assignRole('commercial');

        $this->tenant = Tenant::factory()->create([
            'trade_name' => 'Timezone Tenant',
            'customer_code' => 'TZ-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        ]);
        $this->terminal = PosTerminal::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);

        parent::tearDown();
    }

    public function test_all_tenants_hourly_uses_manila_business_date_for_utc_timestamps(): void
    {
        $this->createReportTransaction('2026-07-14 17:15:08', '2026-07-14T17:15:08Z', 100.00);

        $this->actingAs($this->commercialUser);

        $july15 = $this->getJson('/commercial/reports/transactions/hourly?date=2026-07-15');
        $july15->assertOk();
        $july15Rows = $this->rowsForCurrentTenant($july15->json('data'));
        $this->assertCount(1, $july15Rows);
        $this->assertSame('01:00', $july15Rows[0]['hour']);
        $this->assertSame('2026-07-15 01:00', $july15Rows[0]['period']);
        $this->assertEquals(100.00, (float) $july15Rows[0]['gross_sales']);

        $july14 = $this->getJson('/commercial/reports/transactions/hourly?date=2026-07-14');
        $july14->assertOk();
        $this->assertSame([], $this->rowsForCurrentTenant($july14->json('data')));
    }

    public function test_all_tenants_weekly_and_monthly_bucket_utc_rows_on_manila_date(): void
    {
        $this->createReportTransaction('2026-07-14 17:15:08', '2026-07-14T17:15:08Z', 100.00);

        $this->actingAs($this->commercialUser);

        $weekly = $this->getJson('/commercial/reports/transactions/weekly?date_from=2026-07-14&date_to=2026-07-15');
        $weekly->assertOk();
        $this->assertSame(['2026-07-15'], collect($this->rowsForCurrentTenant($weekly->json('days')))->pluck('date')->all());

        $monthly = $this->getJson('/commercial/reports/transactions/monthly?date_from=2026-07-14&date_to=2026-07-15');
        $monthly->assertOk();
        $this->assertSame(['2026-07-15'], collect($this->rowsForCurrentTenant($monthly->json('days')))->pluck('date')->all());
    }

    public function test_all_tenants_weekday_weekend_filters_use_shifted_business_date(): void
    {
        $this->createReportTransaction('2026-07-17 17:00:00', '2026-07-17T17:00:00Z', 80.00);

        $this->actingAs($this->commercialUser);

        $weekend = $this->getJson('/commercial/reports/transactions/weekend?date_from=2026-07-18&date_to=2026-07-18');
        $weekend->assertOk();
        $this->assertSame(['2026-07-18'], collect($this->rowsForCurrentTenant($weekend->json('days')))->pluck('date')->all());

        $weekday = $this->getJson('/commercial/reports/transactions/weekday?date_from=2026-07-18&date_to=2026-07-18');
        $weekday->assertOk();
        $this->assertSame([], $this->rowsForCurrentTenant($weekday->json('days')));
    }

    public function test_plain_local_payload_timestamp_stays_on_stored_business_date(): void
    {
        $this->createReportTransaction('2026-07-14 17:15:08', '2026-07-14 17:15:08', 100.00);

        $this->actingAs($this->commercialUser);

        $july14 = $this->getJson('/commercial/reports/transactions/hourly?date=2026-07-14');
        $july14->assertOk();
        $july14Rows = $this->rowsForCurrentTenant($july14->json('data'));
        $this->assertCount(1, $july14Rows);
        $this->assertSame('17:00', $july14Rows[0]['hour']);
        $this->assertSame('2026-07-14 17:00', $july14Rows[0]['period']);

        $july15 = $this->getJson('/commercial/reports/transactions/hourly?date=2026-07-15');
        $july15->assertOk();
        $this->assertSame([], $this->rowsForCurrentTenant($july15->json('data')));
    }

    public function test_tenant_scoped_hourly_report_uses_shifted_business_hour(): void
    {
        $this->createReportTransaction('2026-07-14 17:15:08', '2026-07-14T17:15:08Z', 100.00);

        $this->actingAs($this->commercialUser);

        $response = $this->getJson('/commercial/reports/transactions/hourly?date=2026-07-15&tenant_id=' . $this->tenant->id);

        $response->assertOk();
        $this->assertSame('01:00', $response->json('data.0.hour'));
        $this->assertEquals(100.00, (float) $response->json('data.0.gross_sales'));
    }

    public function test_legacy_local_time_with_z_provider_null_payload_stays_on_stored_business_date(): void
    {
        $provider = PosProvider::factory()->create(['timestamp_mode' => 'local_time_with_z']);
        $this->terminal->forceFill(['provider_id' => $provider->id])->save();

        $this->createReportTransaction('2026-07-14 17:15:08', null, 100.00);

        $this->actingAs($this->commercialUser);

        $july14 = $this->getJson('/commercial/reports/transactions/hourly?date=2026-07-14');
        $july14->assertOk();
        $rows = $this->rowsForCurrentTenant($july14->json('data'));
        $this->assertCount(1, $rows);
        $this->assertSame('17:00', $rows[0]['hour']);

        $july15 = $this->getJson('/commercial/reports/transactions/hourly?date=2026-07-15');
        $july15->assertOk();
        $this->assertSame([], $this->rowsForCurrentTenant($july15->json('data')));
    }

    public function test_legacy_true_utc_provider_null_payload_still_shifts(): void
    {
        $provider = PosProvider::factory()->create(['timestamp_mode' => 'true_utc']);
        $this->terminal->forceFill(['provider_id' => $provider->id])->save();

        $this->createReportTransaction('2026-07-14 17:15:08', null, 100.00);

        $this->actingAs($this->commercialUser);

        $july15 = $this->getJson('/commercial/reports/transactions/hourly?date=2026-07-15');
        $july15->assertOk();
        $rows = $this->rowsForCurrentTenant($july15->json('data'));
        $this->assertCount(1, $rows);
        $this->assertSame('01:00', $rows[0]['hour']);

        $july14 = $this->getJson('/commercial/reports/transactions/hourly?date=2026-07-14');
        $july14->assertOk();
        $this->assertSame([], $this->rowsForCurrentTenant($july14->json('data')));
    }

    public function test_legacy_row_without_provider_still_shifts(): void
    {
        // Terminal has no provider_id at all — safe default when the signal is unavailable.
        $this->createReportTransaction('2026-07-14 17:15:08', null, 100.00);

        $this->actingAs($this->commercialUser);

        $july15 = $this->getJson('/commercial/reports/transactions/hourly?date=2026-07-15');
        $july15->assertOk();
        $rows = $this->rowsForCurrentTenant($july15->json('data'));
        $this->assertCount(1, $rows);
        $this->assertSame('01:00', $rows[0]['hour']);
    }

    public function test_payload_evidence_wins_over_provider_fallback_for_plain_local(): void
    {
        // Provider is true_utc (would shift via the safe default if it fell through to the
        // fallback), but a present, plain-local payload must still win and prevent the shift.
        $provider = PosProvider::factory()->create(['timestamp_mode' => 'true_utc']);
        $this->terminal->forceFill(['provider_id' => $provider->id])->save();

        $this->createReportTransaction('2026-07-14 17:15:08', '2026-07-14 17:15:08', 100.00);

        $this->actingAs($this->commercialUser);

        $july14 = $this->getJson('/commercial/reports/transactions/hourly?date=2026-07-14');
        $july14->assertOk();
        $rows = $this->rowsForCurrentTenant($july14->json('data'));
        $this->assertCount(1, $rows);
        $this->assertSame('17:00', $rows[0]['hour']);
    }

    public function test_payload_confirms_utc_even_for_local_time_with_z_provider(): void
    {
        // Provider is local_time_with_z (would trust raw via the fallback if there were no
        // payload), but a present, Z-suffixed payload proves this specific row is genuinely
        // absolute and must still shift — the fallback must never override confirmed evidence.
        $provider = PosProvider::factory()->create(['timestamp_mode' => 'local_time_with_z']);
        $this->terminal->forceFill(['provider_id' => $provider->id])->save();

        $this->createReportTransaction('2026-07-14 17:15:08', '2026-07-14T17:15:08Z', 100.00);

        $this->actingAs($this->commercialUser);

        $july15 = $this->getJson('/commercial/reports/transactions/hourly?date=2026-07-15');
        $july15->assertOk();
        $rows = $this->rowsForCurrentTenant($july15->json('data'));
        $this->assertCount(1, $rows);
        $this->assertSame('01:00', $rows[0]['hour']);
    }

    public function test_malformed_payload_falls_back_sanely(): void
    {
        $provider = PosProvider::factory()->create(['timestamp_mode' => 'local_time_with_z']);
        $this->terminal->forceFill(['provider_id' => $provider->id])->save();

        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_timestamp' => '2026-07-14 17:15:08',
            'gross_sales' => 100.00,
            'net_sales' => 100.00,
            'vatable_sales' => 100.00,
            'vat_amount' => 0,
            'sc_vat_exempt_sales' => 0,
            'validation_status' => 'VALID',
            'original_payload' => 'not valid json{',
        ]);

        $this->actingAs($this->commercialUser);

        // Malformed payload is not the same as no payload at all — it must not be treated as
        // "legacy no-evidence" (which would trust raw); it falls through to the existing
        // shift-by-default behavior without throwing.
        $response = $this->getJson('/commercial/reports/transactions/hourly?date=2026-07-15');
        $response->assertOk();
        $rows = $this->rowsForCurrentTenant($response->json('data'));
        $this->assertCount(1, $rows);
        $this->assertSame('01:00', $rows[0]['hour']);
    }

    public function test_null_transaction_timestamp_fallback_column_still_shifts_and_is_not_dropped_by_prefilter(): void
    {
        $provider = PosProvider::factory()->create(['timestamp_mode' => 'local_time_with_z']);
        $this->terminal->forceFill(['provider_id' => $provider->id])->save();

        // transaction_timestamp itself is null, so resolveBusinessMoment() falls back to
        // completed_at — the legacy-provider exception must not extend to that column, and
        // the coarse performance pre-filter (whereBetween(transaction_timestamp, ...)) must
        // not silently exclude this row via its orWhereNull() widening.
        Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_timestamp' => null,
            'completed_at' => '2026-07-14 17:15:08',
            'gross_sales' => 100.00,
            'net_sales' => 100.00,
            'vatable_sales' => 100.00,
            'vat_amount' => 0,
            'sc_vat_exempt_sales' => 0,
            'validation_status' => 'VALID',
            'original_payload' => null,
        ]);

        $this->actingAs($this->commercialUser);

        $response = $this->getJson('/commercial/reports/transactions/hourly?date=2026-07-15');
        $response->assertOk();
        $rows = $this->rowsForCurrentTenant($response->json('data'));
        $this->assertCount(1, $rows);
        $this->assertSame('01:00', $rows[0]['hour']);
    }

    private function createReportTransaction(string $storedTimestamp, ?string $payloadTimestamp, float $grossSales): Transaction
    {
        return Transaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'transaction_timestamp' => $storedTimestamp,
            'gross_sales' => $grossSales,
            'net_sales' => $grossSales,
            'vatable_sales' => $grossSales,
            'vat_amount' => 0,
            'sc_vat_exempt_sales' => 0,
            'validation_status' => 'VALID',
            'original_payload' => $payloadTimestamp === null ? null : json_encode([
                'transaction_timestamp' => $payloadTimestamp,
            ]),
        ]);
    }

    private function rowsForCurrentTenant(?array $rows): array
    {
        return collect($rows ?? [])
            ->where('tenant_id', $this->tenant->id)
            ->values()
            ->all();
    }
}
