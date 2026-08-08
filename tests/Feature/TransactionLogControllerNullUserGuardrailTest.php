<?php

namespace Tests\Feature;

use App\Http\Controllers\TransactionLogController;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Tests\TestCase;

class TransactionLogControllerNullUserGuardrailTest extends TestCase
{
    public function test_null_user_transaction_log_scoping_helpers_fail_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'status_id' => 1,
        ]);
        Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
        ]);

        $controller = app(TransactionLogController::class);
        $request = Request::create('/transactions/logs');
        $request->setUserResolver(fn () => null);

        $scopeFilters = new \ReflectionMethod($controller, 'scopeFiltersForUser');
        $scopeFilters->setAccessible(true);
        $filters = $scopeFilters->invoke($controller, $request, []);

        $this->assertSame(-1, $filters['tenant_id']);

        $query = Transaction::withoutGlobalScopes()->newQuery();
        $scopeQuery = new \ReflectionMethod($controller, 'scopeTransactionQueryForUser');
        $scopeQuery->setAccessible(true);
        $scopeQuery->invoke($controller, $request, $query);

        $this->assertSame(0, $query->count());
    }
}
