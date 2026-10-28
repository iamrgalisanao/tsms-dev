<?php

namespace Tests\Feature;

use App\Services\SystemLogService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SystemLogsPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_command_deletes_old_logs_when_forced(): void
    {
        $this->insertLogs('prune-test-forced', 2, 100);

        $this->artisan('systemlogs:prune --days=90 --force')
            ->assertExitCode(0);

        $count = DB::table('system_logs')->where('log_type', 'prune-test-forced')->whereNull('deleted_at')->count();
        $this->assertEquals(0, $count, 'Old system logs should be soft-deleted by prune command');
    }

    public function test_soft_prune_marks_old_rows_deleted_and_leaves_recent_rows(): void
    {
        $this->insertLogs('prune-test-soft-old', 3, 100);
        $this->insertLogs('prune-test-soft-new', 2, 10);

        $this->artisan('systemlogs:prune --days=90 --force')
            ->assertExitCode(0);

        $this->assertSame(3, DB::table('system_logs')->where('log_type', 'prune-test-soft-old')->whereNotNull('deleted_at')->count());
        $this->assertSame(0, DB::table('system_logs')->where('log_type', 'prune-test-soft-old')->whereNull('deleted_at')->count());
        $this->assertSame(2, DB::table('system_logs')->where('log_type', 'prune-test-soft-new')->whereNull('deleted_at')->count());
    }

    public function test_hard_prune_removes_old_rows_and_leaves_recent_rows(): void
    {
        $this->insertLogs('prune-test-hard-old', 3, 100);
        $this->insertLogs('prune-test-hard-new', 2, 10);

        $this->artisan('systemlogs:prune --days=90 --force --hard')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('system_logs')->where('log_type', 'prune-test-hard-old')->count());
        $this->assertSame(2, DB::table('system_logs')->where('log_type', 'prune-test-hard-new')->whereNull('deleted_at')->count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->insertLogs('prune-test-dry-old', 3, 100);

        $this->artisan('systemlogs:prune --days=90 --dry-run')
            ->assertExitCode(0);

        $this->assertSame(3, DB::table('system_logs')->where('log_type', 'prune-test-dry-old')->whereNull('deleted_at')->count());
    }

    public function test_invalid_before_date_fails_and_deletes_nothing(): void
    {
        $this->insertLogs('prune-test-badbefore-old', 2, 100);
        $this->insertLogs('prune-test-badbefore-new', 2, 10);

        $this->artisan('systemlogs:prune --before=not-a-date --force')
            ->assertFailed();

        $this->assertSame(0, DB::table('system_logs')
            ->whereIn('log_type', ['prune-test-badbefore-old', 'prune-test-badbefore-new'])
            ->whereNotNull('deleted_at')
            ->count());
        $this->assertSame(4, DB::table('system_logs')
            ->whereIn('log_type', ['prune-test-badbefore-old', 'prune-test-badbefore-new'])
            ->count());
    }

    public function test_prune_across_multiple_chunks_does_not_skip_rows(): void
    {
        $this->insertLogs('prune-test-chunk-old', 5, 100);

        app(SystemLogService::class)->prune(['days' => 90, 'chunk' => 2]);

        $this->assertSame(5, DB::table('system_logs')->where('log_type', 'prune-test-chunk-old')->whereNotNull('deleted_at')->count());
        $this->assertSame(0, DB::table('system_logs')->where('log_type', 'prune-test-chunk-old')->whereNull('deleted_at')->count());
    }

    public function test_daily_prune_is_scheduled_in_background_with_overlap_lock(): void
    {
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event) => $event->description === 'systemlogs-prune-daily');

        // routes/console.php may be loaded more than once (withRouting + Kernel::commands);
        // every registration must carry the same settings.
        $this->assertGreaterThanOrEqual(1, $events->count());

        foreach ($events as $event) {
            $this->assertSame('0 2 * * *', $event->expression);
            $this->assertTrue($event->runInBackground);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertSame(720, $event->expiresAt);
            $this->assertTrue($event->onOneServer);
        }
    }

    private function insertLogs(string $logType, int $count, int $daysOld): void
    {
        $date = Carbon::now()->subDays($daysOld)->toDateTimeString();

        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'type' => 'test',
                'log_type' => $logType,
                'severity' => 'low',
                'terminal_uid' => '00000000-0000-0000-0000-000000000000',
                'message' => $logType.'-'.$i,
                'created_at' => $date,
                'updated_at' => $date,
            ];
        }

        DB::table('system_logs')->insert($rows);
    }
}
