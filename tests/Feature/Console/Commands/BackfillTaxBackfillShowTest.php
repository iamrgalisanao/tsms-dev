<?php

namespace Tests\Feature\Console\Commands;

use App\Models\TaxBackfillRecord;
use App\Models\TaxBackfillRun;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, T042 (Command 4). See
 * specs/002-backfill-transaction-taxes/contracts/cli-contract.md.
 *
 * Covers `transactions:tax-backfill-show`: input validation (mutually
 * exclusive modes, invalid ids), transaction-history mode (full history,
 * --run= scoping), quarantined-list mode (filtering to only `quarantined`
 * outcomes, --run= scoping), and that the command never writes anything.
 *
 * Named with "Backfill" in the class for this feature's `--filter=Backfill`
 * convention.
 */
class BackfillTaxBackfillShowTest extends TestCase
{
    private const COMMAND = 'transactions:tax-backfill-show';

    private function decodeJson(string $output): array
    {
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "Command output was not valid JSON: {$output}");

        return $decoded;
    }

    public function test_neither_transaction_nor_quarantined_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--json' => true]);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('Either --transaction=<id> or --quarantined is required', Artisan::output());
    }

    public function test_both_transaction_and_quarantined_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--transaction' => 1, '--quarantined' => true]);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('mutually exclusive', Artisan::output());
    }

    public function test_non_numeric_transaction_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--transaction' => 'abc']);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('must be a positive integer', Artisan::output());
    }

    public function test_non_numeric_run_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--quarantined' => true, '--run' => 'abc']);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('must be a positive integer', Artisan::output());
    }

    public function test_zero_transaction_id_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--transaction' => '0']);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('must be a positive integer', Artisan::output());
    }

    public function test_zero_run_id_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--quarantined' => true, '--run' => '0']);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('must be a positive integer', Artisan::output());
    }

    public function test_limit_without_quarantined_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--transaction' => 1, '--limit' => 5]);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('--limit only applies to --quarantined', Artisan::output());
    }

    public function test_zero_limit_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--quarantined' => true, '--limit' => '0']);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('must be a positive integer', Artisan::output());
    }

    public function test_transaction_mode_shows_full_history_for_one_transaction_only(): void
    {
        $tenant = Tenant::factory()->create();
        $transaction = Transaction::factory()->create(['tenant_id' => $tenant->id]);
        $otherTransaction = Transaction::factory()->create(['tenant_id' => $tenant->id]);

        $runA = TaxBackfillRun::factory()->create();
        $runB = TaxBackfillRun::factory()->create();

        TaxBackfillRecord::factory()->quarantined('missing_payload')->create([
            'run_id' => $runA->id,
            'transaction_pk' => $transaction->id,
            'tenant_id' => $tenant->id,
        ]);
        $secondAttempt = TaxBackfillRecord::factory()->applied()->create([
            'run_id' => $runB->id,
            'transaction_pk' => $transaction->id,
            'tenant_id' => $tenant->id,
        ]);
        // A record for a DIFFERENT transaction must never appear.
        TaxBackfillRecord::factory()->applied()->create([
            'run_id' => $runA->id,
            'transaction_pk' => $otherTransaction->id,
            'tenant_id' => $tenant->id,
        ]);

        $exit = Artisan::call(self::COMMAND, ['--transaction' => $transaction->id, '--json' => true]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame('transaction', $result['mode']);
        $this->assertSame(2, $result['record_count']);
        $this->assertCount(2, $result['records']);

        $outcomes = array_column($result['records'], 'outcome');
        $this->assertContains('quarantined', $outcomes);
        $this->assertContains('applied', $outcomes);

        $applied = array_values(array_filter($result['records'], fn (array $r) => $r['record_id'] === $secondAttempt->id))[0];
        $this->assertSame($runB->id, $applied['run_id']);
        $this->assertFalse($applied['had_linked_rows_before']);
        $this->assertSame(1, $applied['reconstructed_tax_row_count']);
    }

    public function test_transaction_mode_can_be_scoped_by_run(): void
    {
        $tenant = Tenant::factory()->create();
        $transaction = Transaction::factory()->create(['tenant_id' => $tenant->id]);

        $runA = TaxBackfillRun::factory()->create();
        $runB = TaxBackfillRun::factory()->create();

        TaxBackfillRecord::factory()->quarantined()->create([
            'run_id' => $runA->id,
            'transaction_pk' => $transaction->id,
            'tenant_id' => $tenant->id,
        ]);
        TaxBackfillRecord::factory()->applied()->create([
            'run_id' => $runB->id,
            'transaction_pk' => $transaction->id,
            'tenant_id' => $tenant->id,
        ]);

        $exit = Artisan::call(self::COMMAND, [
            '--transaction' => $transaction->id,
            '--run' => $runB->id,
            '--json' => true,
        ]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame(1, $result['record_count']);
        $this->assertSame('applied', $result['records'][0]['outcome']);
        $this->assertSame($runB->id, $result['run_filter']);
    }

    public function test_transaction_mode_with_no_records_reports_empty_not_an_error(): void
    {
        $tenant = Tenant::factory()->create();
        $transaction = Transaction::factory()->create(['tenant_id' => $tenant->id]);

        $exit = Artisan::call(self::COMMAND, ['--transaction' => $transaction->id, '--json' => true]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame(0, $result['record_count']);
        $this->assertSame([], $result['records']);
    }

    public function test_quarantined_mode_lists_only_quarantined_outcomes(): void
    {
        $tenant = Tenant::factory()->create();
        $run = TaxBackfillRun::factory()->create();

        $quarantinedA = TaxBackfillRecord::factory()->quarantined('missing_payload')->create([
            'run_id' => $run->id,
            'transaction_pk' => Transaction::factory()->create(['tenant_id' => $tenant->id])->id,
            'tenant_id' => $tenant->id,
        ]);
        $quarantinedB = TaxBackfillRecord::factory()->quarantined('cross_check_mismatch')->create([
            'run_id' => $run->id,
            'transaction_pk' => Transaction::factory()->create(['tenant_id' => $tenant->id])->id,
            'tenant_id' => $tenant->id,
        ]);
        // Must never appear in quarantined-mode output.
        TaxBackfillRecord::factory()->applied()->create([
            'run_id' => $run->id,
            'transaction_pk' => Transaction::factory()->create(['tenant_id' => $tenant->id])->id,
            'tenant_id' => $tenant->id,
        ]);
        TaxBackfillRecord::factory()->skippedExisting()->create([
            'run_id' => $run->id,
            'transaction_pk' => Transaction::factory()->create(['tenant_id' => $tenant->id])->id,
            'tenant_id' => $tenant->id,
        ]);
        TaxBackfillRecord::factory()->failed('db_error')->create([
            'run_id' => $run->id,
            'transaction_pk' => Transaction::factory()->create(['tenant_id' => $tenant->id])->id,
            'tenant_id' => $tenant->id,
        ]);

        $exit = Artisan::call(self::COMMAND, ['--quarantined' => true, '--run' => $run->id, '--json' => true]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame('quarantined', $result['mode']);
        $this->assertSame(2, $result['total_count']);
        $this->assertSame(2, $result['shown_count']);
        $this->assertFalse($result['truncated']);

        $ids = array_column($result['records'], 'record_id');
        $this->assertContains($quarantinedA->id, $ids);
        $this->assertContains($quarantinedB->id, $ids);

        $reasonCodes = array_column($result['records'], 'reason_code');
        $this->assertContains('missing_payload', $reasonCodes);
        $this->assertContains('cross_check_mismatch', $reasonCodes);
    }

    public function test_quarantined_mode_limit_can_be_overridden(): void
    {
        $tenant = Tenant::factory()->create();
        $run = TaxBackfillRun::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            TaxBackfillRecord::factory()->quarantined()->create([
                'run_id' => $run->id,
                'transaction_pk' => Transaction::factory()->create(['tenant_id' => $tenant->id])->id,
                'tenant_id' => $tenant->id,
            ]);
        }

        $exit = Artisan::call(self::COMMAND, ['--quarantined' => true, '--run' => $run->id, '--limit' => 2, '--json' => true]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame(3, $result['total_count']);
        $this->assertSame(2, $result['shown_count']);
        $this->assertTrue($result['truncated']);
        $this->assertSame(2, $result['limit']);
    }

    public function test_quarantined_mode_can_be_scoped_by_run(): void
    {
        $tenant = Tenant::factory()->create();
        $runA = TaxBackfillRun::factory()->create();
        $runB = TaxBackfillRun::factory()->create();

        TaxBackfillRecord::factory()->quarantined()->create([
            'run_id' => $runA->id,
            'transaction_pk' => Transaction::factory()->create(['tenant_id' => $tenant->id])->id,
            'tenant_id' => $tenant->id,
        ]);
        TaxBackfillRecord::factory()->quarantined()->create([
            'run_id' => $runB->id,
            'transaction_pk' => Transaction::factory()->create(['tenant_id' => $tenant->id])->id,
            'tenant_id' => $tenant->id,
        ]);

        $exit = Artisan::call(self::COMMAND, ['--quarantined' => true, '--run' => $runA->id, '--json' => true]);
        $result = $this->decodeJson(Artisan::output());

        $this->assertSame(0, $exit);
        $this->assertSame(1, $result['total_count']);
        $this->assertSame($runA->id, $result['records'][0]['run_id']);
    }

    public function test_command_never_writes_anything(): void
    {
        $tenant = Tenant::factory()->create();
        $transaction = Transaction::factory()->create(['tenant_id' => $tenant->id]);
        $run = TaxBackfillRun::factory()->create();
        TaxBackfillRecord::factory()->quarantined()->create([
            'run_id' => $run->id,
            'transaction_pk' => $transaction->id,
            'tenant_id' => $tenant->id,
        ]);

        $tablesToWatch = ['tax_backfill_records', 'tax_backfill_runs', 'transactions', 'transaction_taxes'];
        $watermarks = [];
        foreach ($tablesToWatch as $table) {
            $watermarks[$table] = DB::table($table)->count();
        }

        Artisan::call(self::COMMAND, ['--transaction' => $transaction->id]);
        Artisan::call(self::COMMAND, ['--quarantined' => true]);

        foreach ($tablesToWatch as $table) {
            $this->assertSame($watermarks[$table], DB::table($table)->count(), "Table {$table} row count changed -- this command must never write anything.");
        }
    }
}
