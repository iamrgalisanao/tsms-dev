<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Backfill\ConnectionIdentityResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 18 (T077). See
 * specs/002-backfill-transaction-taxes/slice-18-connection-identity-recording-brief.md.
 *
 * Covers `reports:refresh-daily-transaction-summaries`'s connection-identity
 * gate: on a match against the canonical `mysql` connection, the run
 * proceeds and the resolved `server_id`/`database_name` are persisted to
 * `report_refresh_states`; on a mismatch, the command refuses before any
 * aggregation query or write (zero rows written to either
 * `daily_transaction_summaries` or `report_refresh_states`).
 *
 * Named with "Backfill" in the class (not the more literal
 * RefreshDailyTransactionSummariesTest) so it is discoverable via this
 * feature's `--filter=Backfill` test-running convention.
 */
class BackfillConnectionIdentityRefreshTest extends TestCase
{
    private const COMMAND = 'reports:refresh-daily-transaction-summaries';

    public function test_comparison_logic_treats_any_single_field_difference_as_a_mismatch(): void
    {
        $same = ['server_id' => 1, 'database' => 'tsms_db'];
        $sameCopy = ['server_id' => 1, 'database' => 'tsms_db'];
        $differentServerId = ['server_id' => 2, 'database' => 'tsms_db'];
        $differentDatabase = ['server_id' => 1, 'database' => 'other_db'];

        $this->assertTrue($same === $sameCopy);
        $this->assertFalse($same === $differentServerId);
        $this->assertFalse($same === $differentDatabase);
    }

    public function test_refresh_persists_the_real_connection_identity_on_a_normal_run(): void
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_timestamp' => '2026-07-01 09:00:00',
            'gross_sales' => 100.00,
            'net_sales' => 89.29,
            'vatable_sales' => 89.29,
            'vat_amount' => 10.71,
            'validation_status' => 'VALID',
        ]);

        $oracle = DB::connection('mysql')->selectOne('SELECT @@server_id AS server_id, DATABASE() AS db_name');

        $exitCode = $this->artisan(self::COMMAND, [
            '--from' => '2026-07-01',
            '--to' => '2026-07-01',
            '--tenant' => $tenant->id,
        ])->run();

        $this->assertSame(0, $exitCode);

        $state = DB::table('report_refresh_states')
            ->where('report_type', 'daily_transaction_summaries')
            ->where('tenant_id', $tenant->id)
            ->where('business_date', '2026-07-01')
            ->first();

        $this->assertNotNull($state);
        $this->assertSame((int) $oracle->server_id, (int) $state->server_id);
        $this->assertSame($oracle->db_name, $state->database_name);

        $this->assertDatabaseHas('daily_transaction_summaries', [
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'business_date' => '2026-07-01',
        ]);
    }

    public function test_refresh_refuses_and_writes_nothing_when_aggregating_connection_differs_from_primary(): void
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

        Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_timestamp' => '2026-07-02 09:00:00',
            'gross_sales' => 50.00,
            'net_sales' => 44.64,
            'vatable_sales' => 44.64,
            'vat_amount' => 5.36,
            'validation_status' => 'VALID',
        ]);

        // Name-based branching would collapse to a false match in this test
        // env, where config('database.default') already resolves to the
        // literal string "mysql" (same as the primary's connection name).
        // A genuine mismatch is about the *resolved identity* differing,
        // independent of what the two connection names happen to be, so
        // this fake distinguishes by call order instead: the command always
        // resolves the aggregating connection first, then the primary.
        $fake = new class implements ConnectionIdentityResolver
        {
            private int $calls = 0;

            public function resolve(string $connectionName): array
            {
                $this->calls++;

                return $this->calls === 1
                    ? ['server_id' => 999, 'database' => 'wrong_db']
                    : ['server_id' => 1, 'database' => 'primary_db'];
            }
        };

        $this->app->instance(ConnectionIdentityResolver::class, $fake);

        $exitCode = $this->artisan(self::COMMAND, [
            '--from' => '2026-07-02',
            '--to' => '2026-07-02',
            '--tenant' => $tenant->id,
        ])->run();

        $this->assertSame(1, $exitCode);

        $this->assertDatabaseMissing('report_refresh_states', [
            'report_type' => 'daily_transaction_summaries',
            'tenant_id' => $tenant->id,
            'business_date' => '2026-07-02',
        ]);

        $this->assertDatabaseMissing('daily_transaction_summaries', [
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'business_date' => '2026-07-02',
        ]);
    }
}
