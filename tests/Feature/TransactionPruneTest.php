<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Support\Metrics;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * ADR-004 slice 2 prune guard: the hourly 'transactions-prune' schedule never deletes
 * (PENDING is never deletable; deletion needs a retention ADR) and logs an inventory
 * snapshot. Counts are compared as deltas because rows can leak between tests here.
 */
class TransactionPruneTest extends TestCase
{
    private array $snapshots = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['tsms.transactions.enable_pruning' => true]);
        Log::listen(function (MessageLogged $m) {
            if ($m->message === '[Prune] Transaction inventory snapshot') {
                $this->snapshots[] = $m->context;
            }
        });
    }

    private function pruneEvent(): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => $e->description === 'transactions-prune');
        $this->assertNotNull($event, 'transactions-prune schedule must exist');
        $this->assertSame('0 * * * *', $event->expression, 'prune stays hourly');

        return $event;
    }

    private function runPrune(): array
    {
        $before = count($this->snapshots);
        $this->pruneEvent()->run(app());
        $this->assertCount($before + 1, $this->snapshots, 'prune must log one snapshot');

        return end($this->snapshots);
    }

    private function oldTransaction(array $attrs): Transaction
    {
        $tx = Transaction::factory()->create($attrs);
        DB::table('transactions')->where('id', $tx->id)->update(['created_at' => now()->subDays(30)]);

        return $tx;
    }

    /** Creates a legacy '' row the way staging did: an invalid enum write in a non-strict session. */
    private function legacyBlankTransaction(): Transaction
    {
        $tx = $this->oldTransaction(['validation_status' => 'PENDING', 'job_status' => 'FAILED']);
        $previous = DB::selectOne('SELECT @@SESSION.sql_mode AS m')->m;
        DB::statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        try {
            DB::update("UPDATE transactions SET validation_status = 'FAILED' WHERE id = ?", [$tx->id]);
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$previous]);
        }
        $this->assertSame('', DB::table('transactions')->where('id', $tx->id)->value('validation_status'));

        return $tx;
    }

    private function seedRows(): array
    {
        return [
            $this->oldTransaction(['validation_status' => 'PENDING', 'job_status' => 'QUEUED']),
            $this->oldTransaction(['validation_status' => 'PENDING', 'job_status' => 'FAILED']),
            $this->oldTransaction(['validation_status' => 'INVALID', 'job_status' => 'COMPLETED']),
            $this->oldTransaction(['validation_status' => 'VALID', 'job_status' => 'COMPLETED']),
            $this->legacyBlankTransaction(),
        ];
    }

    private function assertDeltas(array $before, array $after): void
    {
        $delta = fn (string $k) => $after[$k] - $before[$k];
        $this->assertSame(1, $delta('valid'));
        $this->assertSame(1, $delta('invalid'));
        $this->assertSame(2, $delta('pending'));
        $this->assertSame(1, $delta('pending_job_failed'));
        $this->assertSame(1, $delta('legacy_blank'));
        $this->assertSame(0, $after['deleted']);
    }

    public function test_prune_deletes_nothing_by_default_and_logs_snapshot(): void
    {
        $before = $this->runPrune();
        $rows = $this->seedRows();

        $after = $this->runPrune();

        $this->assertDeltas($before, $after);
        $this->assertFalse($after['delete_enabled']);
        $this->assertSame(count($rows), DB::table('transactions')->whereIn('id', collect($rows)->pluck('id'))->count());
        $this->assertSame(0, (int) Metrics::get('transaction_prune.deleted', 0));
    }

    public function test_prune_deletes_nothing_even_when_delete_flag_enabled(): void
    {
        config(['tsms.transactions.prune_delete_enabled' => true]);
        $before = $this->runPrune();
        $rows = $this->seedRows();

        $after = $this->runPrune();

        $this->assertDeltas($before, $after);
        $this->assertTrue($after['delete_enabled']);
        $this->assertSame(count($rows), DB::table('transactions')->whereIn('id', collect($rows)->pluck('id'))->count());
        $this->assertSame(0, (int) Metrics::get('transaction_prune.deleted', 0));
    }

    public function test_prune_disabled_logs_nothing(): void
    {
        config(['tsms.transactions.enable_pruning' => false]);
        $this->pruneEvent()->run(app());
        $this->assertSame([], $this->snapshots);
    }
}
