<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * TSMS-0061: large page sizes on the transaction logs list must not run
 * per-row queries, and the page size is capped at 500.
 */
class TransactionLogPageSizeTest extends TestCase
{
    use RefreshDatabase;

    private array $createdTransactionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $tenant = Tenant::factory()->create(['status' => 'active']);
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id, 'status_id' => 1]);
        $this->createdTransactionIds = Transaction::factory()->count(520)->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
        ])->pluck('id')->all();

        $admin = User::factory()->create(['tenant_id' => null]);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);
    }

    protected function tearDown(): void
    {
        // Rows created here can outlive the RefreshDatabase transaction in
        // this suite; remove them so later tests don't see 500+ extra rows.
        Transaction::query()->whereIn('id', $this->createdTransactionIds)->delete();

        parent::tearDown();
    }

    private function queryCountFor(int $perPage): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/transactions/logs?per_page='.$perPage);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();

        return [$count, $response];
    }

    public function test_query_count_does_not_grow_with_page_size(): void
    {
        [$small] = $this->queryCountFor(15);
        [$large, $response] = $this->queryCountFor(500);

        $this->assertCount(500, $response->json('data'));
        $this->assertLessThanOrEqual(
            $small + 5,
            $large,
            "Expected a flat query count; got {$small} queries for 15 rows and {$large} for 500 rows."
        );
    }

    public function test_list_rows_do_not_include_unused_computed_fields(): void
    {
        [, $response] = $this->queryCountFor(15);

        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('net_amount', $row);
        $this->assertArrayNotHasKey('calculated_net_sales', $row);
        $this->assertArrayNotHasKey('display_tenant_code', $row);
        $this->assertArrayHasKey('transaction_id', $row);
    }

    public function test_page_size_is_capped_at_500(): void
    {
        [, $response] = $this->queryCountFor(1000);

        $this->assertCount(500, $response->json('data'));
    }
}
