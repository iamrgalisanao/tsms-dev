<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SystemLogsPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function prune_command_deletes_old_logs_when_forced()
    {
        // Insert some old logs (100+ days)
        $oldDate = Carbon::now()->subDays(100)->toDateTimeString();
        DB::table('system_logs')->insert([
            ['type' => 'test', 'log_type' => 'old', 'message' => 'old1', 'created_at' => $oldDate, 'updated_at' => $oldDate],
            ['type' => 'test', 'log_type' => 'old', 'message' => 'old2', 'created_at' => $oldDate, 'updated_at' => $oldDate],
        ]);

        $this->artisan('systemlogs:prune --days=90 --force')
            ->assertExitCode(0);

    $count = DB::table('system_logs')->where('log_type', 'old')->whereNull('deleted_at')->count();
    $this->assertEquals(0, $count, 'Old system logs should be soft-deleted by prune command');
    }
}
