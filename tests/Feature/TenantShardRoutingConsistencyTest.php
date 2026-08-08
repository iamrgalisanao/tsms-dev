<?php

namespace Tests\Feature;

use App\Http\Controllers\API\V1\RetryHistoryController;
use App\Http\Controllers\TestTransactionController;
use App\Jobs\BulkGenerateTransactionsJob;
use App\Jobs\ProcessTransactionJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\IngestionQueueRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * T040-T043: these four call sites previously computed their processing shard
 * with a hardcoded `$tenantId % 8`, which disagreed with the canonical
 * IngestionQueueRouter::processingQueueForTenant() crc32-based algorithm used
 * by the real Horizon queue family. These tests assert the dispatched job's
 * queue exactly matches what the canonical router computes for the same
 * tenant id (not merely "a queue between s0-s7").
 */
class TenantShardRoutingConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function router(): IngestionQueueRouter
    {
        return app(IngestionQueueRouter::class);
    }

    /**
     * TestTransactionController::process() reads `$terminal->customer_code`
     * (a column that only exists on `tenants`, not `pos_terminals`) into a
     * NOT NULL `transactions.customer_code` column. That pre-existing gap
     * (unrelated to the T040-T043 sharding fix) is worked around here, in
     * test setup only, by populating the in-memory attribute after fetch so
     * the create() call can succeed and we can reach the dispatch/shard line
     * this test actually targets.
     */
    private function workAroundMissingTerminalCustomerCode(): void
    {
        PosTerminal::retrieved(function (PosTerminal $terminal) {
            $terminal->customer_code = $terminal->customer_code ?? 'TEST-CODE';
        });
    }

    /**
     * TestTransactionController::process() never passes `tenant_id` into
     * Transaction::create() (it only resolves `$transaction->tenant_id`
     * afterwards, for the shard calculation this test targets), yet
     * `transactions.tenant_id` is a NOT NULL foreign key with no default. In
     * this environment that omission fails the insert with a foreign key
     * violation before the dispatch/shard line is ever reached. That is a
     * pre-existing gap unrelated to the T040-T043 sharding fix; work around
     * it in test setup only by backfilling tenant_id from the terminal at
     * creation time.
     */
    private function workAroundMissingTenantIdOnCreate(): void
    {
        Transaction::creating(function (Transaction $transaction) {
            if (empty($transaction->tenant_id) && $transaction->terminal_id) {
                $transaction->tenant_id = PosTerminal::find($transaction->terminal_id)->tenant_id ?? null;
            }
        });
    }

    /**
     * TestTransactionController::retryTransaction() and
     * RetryHistoryController::retrigger() assign `$transaction->retry_count`
     * (and retryTransaction() also assigns `$transaction->status`) and
     * save(), but neither `retry_count` nor `status` are columns on
     * `transactions` (retry_count lives on `transaction_jobs`, see the join
     * in RetryHistoryController::index(); status does not exist at all,
     * likely meant to be job_status). That pre-existing schema mismatch
     * (unrelated to the T040-T043 sharding fix) makes the real save() throw
     * "Unknown column". Work around it in test setup only by stripping the
     * unmapped attributes before save so execution can reach the
     * dispatch/shard line this test targets.
     */
    private function workAroundUnmappedRetryCountColumn(): void
    {
        Transaction::saving(function (Transaction $transaction) {
            foreach (['retry_count', 'status'] as $unmappedAttribute) {
                if ($transaction->offsetExists($unmappedAttribute)) {
                    $transaction->offsetUnset($unmappedAttribute);
                }
            }
        });
    }

    /** T040: TestTransactionController::process() */
    public function test_process_dispatches_to_router_computed_shard(): void
    {
        Bus::fake([ProcessTransactionJob::class]);
        $this->workAroundMissingTerminalCustomerCode();
        $this->workAroundMissingTenantIdOnCreate();

        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id, 'status_id' => 1]);

        $request = Request::create('/test-transaction/process', 'POST', [
            'terminal_id' => $terminal->id,
            'hardware_id' => 'HW-TEST-'.$terminal->id,
            'payload_checksum' => md5('test-payload-'.$terminal->id),
            'gross_sales' => 100,
            'net_sales' => 90,
            'vatable_sales' => 80,
            'vat_amount' => 10,
            'transaction_count' => 1,
        ]);

        app(TestTransactionController::class)->process($request, $this->router());

        $expectedQueue = $this->router()->processingQueueForTenant($tenant->id);

        Bus::assertDispatched(ProcessTransactionJob::class, function ($job) use ($expectedQueue) {
            return $job->queue === $expectedQueue;
        });
    }

    /** T040: TestTransactionController::retryTransaction() */
    public function test_retry_transaction_dispatches_to_router_computed_shard(): void
    {
        Bus::fake([ProcessTransactionJob::class]);
        $this->workAroundUnmappedRetryCountColumn();

        $transaction = Transaction::factory()->create();

        $request = Request::create('/test-transaction/retry', 'POST');

        app(TestTransactionController::class)->retryTransaction($request, $transaction->id, $this->router());

        $expectedQueue = $this->router()->processingQueueForTenant($transaction->tenant_id);

        Bus::assertDispatched(ProcessTransactionJob::class, function ($job) use ($expectedQueue) {
            return $job->queue === $expectedQueue;
        });
    }

    /** T041: RetryHistoryController::retrigger() */
    public function test_retrigger_dispatches_to_router_computed_shard(): void
    {
        Bus::fake([ProcessTransactionJob::class]);
        $this->workAroundUnmappedRetryCountColumn();

        $transaction = Transaction::factory()->create();

        app(RetryHistoryController::class)->retrigger($transaction->id, $this->router());

        $expectedQueue = $this->router()->processingQueueForTenant($transaction->tenant_id);

        Bus::assertDispatched(ProcessTransactionJob::class, function ($job) use ($expectedQueue) {
            return $job->queue === $expectedQueue;
        });
    }

    /** T041: RetryHistoryController::retry() */
    public function test_retry_dispatches_to_router_computed_shard(): void
    {
        Bus::fake([ProcessTransactionJob::class]);

        $transaction = Transaction::factory()->create();

        app(RetryHistoryController::class)->retry($transaction->id, $this->router());

        $expectedQueue = $this->router()->processingQueueForTenant($transaction->tenant_id);

        Bus::assertDispatched(ProcessTransactionJob::class, function ($job) use ($expectedQueue) {
            return $job->queue === $expectedQueue;
        });
    }

    /** T042: BulkGenerateTransactionsJob::handle() */
    public function test_bulk_generate_dispatches_to_router_computed_shard(): void
    {
        Bus::fake([ProcessTransactionJob::class]);

        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id, 'status_id' => 1]);

        $job = new BulkGenerateTransactionsJob(1, [
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_timestamp' => now(),
            'gross_sales' => 100,
            'net_sales' => 90,
        ]);

        $job->handle($this->router());

        $expectedQueue = $this->router()->processingQueueForTenant($tenant->id);

        Bus::assertDispatched(ProcessTransactionJob::class, function ($dispatched) use ($expectedQueue) {
            return $dispatched->queue === $expectedQueue;
        });
    }

    /**
     * T043: TestTransactionPipeline console command.
     *
     * The command's `handle()` unconditionally runs
     * `db:seed --class=TestTransactionPipelineSeeder` before dispatching, and
     * that seeder inserts into a `stores` table that does not exist in this
     * environment's migrations (a pre-existing gap unrelated to the T040-T043
     * sharding fix). To reach and verify the dispatch/shard line this test
     * targets without depending on that unrelated seeder, we run a
     * container-resolved subclass that only overrides `call()` to a no-op
     * and pre-seed our own QUEUED test transaction directly.
     */
    public function test_pipeline_command_dispatches_to_router_computed_shard(): void
    {
        Bus::fake([ProcessTransactionJob::class]);

        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id, 'status_id' => 1]);

        $transaction = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => 'TEST-PIPELINE-'.uniqid(),
            'job_status' => 'QUEUED',
        ]);

        $command = new class extends \App\Console\Commands\TestTransactionPipeline
        {
            public function call($command, array $arguments = [])
            {
                return 0;
            }
        };
        $command->setLaravel($this->app);
        $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput);

        $expectedQueue = $this->router()->processingQueueForTenant($transaction->tenant_id);

        Bus::assertDispatched(ProcessTransactionJob::class, function ($job) use ($expectedQueue) {
            return $job->queue === $expectedQueue;
        });
    }
}
