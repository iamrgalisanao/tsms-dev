<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StagingDbLoadFollowupsTest extends TestCase
{
    private const INDEX = 'transaction_submissions_submission_uuid_index';

    public function test_daily_summary_refresh_is_scheduled_with_short_lock_in_background(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event) => $event->description === 'reports-refresh-daily-transaction-summaries');

        // routes/console.php may be loaded more than once (withRouting + Kernel::commands);
        // every registration must carry the same settings.
        $this->assertGreaterThanOrEqual(1, $events->count());

        foreach ($events as $event) {
            $this->assertSame('*/15 * * * *', $event->expression);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertSame(60, $event->expiresAt);
            $this->assertTrue($event->runInBackground);
            $this->assertTrue($event->onOneServer);
        }
    }

    public function test_submission_uuid_index_exists_and_migration_is_idempotent_and_reversible(): void
    {
        $this->assertSame(['submission_uuid'], $this->index(self::INDEX)['columns'] ?? null);
        $this->assertFalse($this->index(self::INDEX)['unique']);

        $migration = require database_path('migrations/2026_10_27_000001_add_submission_uuid_index_to_transaction_submissions.php');

        // Re-running up() is a no-op.
        $migration->up();
        $this->assertCount(1, collect(Schema::getIndexes('transaction_submissions'))
            ->filter(fn (array $index) => ($index['columns'][0] ?? null) === 'submission_uuid'));

        // DDL commits implicitly, so restore the index even if an assertion fails.
        try {
            $migration->down();
            $this->assertNull($this->index(self::INDEX));
            $this->assertNotNull($this->index('trx_sub_terminal_submission_unique'));
        } finally {
            $migration->up();
        }
        $this->assertNotNull($this->index(self::INDEX));
    }

    private function index(string $name): ?array
    {
        return collect(Schema::getIndexes('transaction_submissions'))
            ->first(fn (array $index) => $index['name'] === $name);
    }
}
