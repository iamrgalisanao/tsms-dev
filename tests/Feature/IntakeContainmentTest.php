<?php

namespace Tests\Feature;

use App\Console\Commands\ReconcileStrandedIntake;
use App\Jobs\Middleware\HoldContainedIntake;
use App\Jobs\ProcessTransactionIntakeJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TransactionIntake;
use App\Models\User;
use App\Services\DeadlockRetryService;
use App\Services\ProviderTimestampNormalizer;
use App\Services\TransactionIngestService;
use App\Support\IntakeContainmentFloor;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntakeContainmentTest extends TestCase
{
    private const NOW = '2026-11-07 12:00:00';

    private const FLOOR = '2026-11-07T10:00:00+00:00';

    private const BEFORE_FLOOR = '2026-11-07 09:59:59';

    private const AT_FLOOR = '2026-11-07 10:00:00';

    private const AFTER_FLOOR = '2026-11-07 10:30:00';

    private const STALE = '2026-11-07 11:00:00';

    private Tenant $tenant;

    private PosTerminal $terminal;

    private array $intakeIds = [];

    private array $failedJobUuids = [];

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
        $this->tenant = Tenant::factory()->create();
        $this->terminal = PosTerminal::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    protected function tearDown(): void
    {
        // TestCase::setUp refreshes the application, so RefreshDatabase does not roll these back.
        TransactionIntake::query()->whereIn('id', $this->intakeIds)->delete();
        DB::table('failed_jobs')->whereIn('uuid', $this->failedJobUuids)->delete();
        array_map('unlink', array_filter($this->tempFiles, 'is_file'));
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- parser

    public function test_absent_floor(): void
    {
        $this->assertSame('absent', IntakeContainmentFloor::resolve(null)->state);
        $this->assertSame('absent', IntakeContainmentFloor::resolve('')->state);
        $this->assertSame('absent', IntakeContainmentFloor::current()->state);
    }

    #[DataProvider('malformedFloors')]
    public function test_malformed_floor_is_invalid(mixed $value): void
    {
        $containment = IntakeContainmentFloor::resolve($value);

        $this->assertSame('invalid', $containment->state);
        $this->assertSame('malformed', $containment->reason);
        $this->assertNull($containment->floor);
    }

    public static function malformedFloors(): array
    {
        return [
            'missing offset' => ['2026-10-27T00:00:00'],
            'month 13' => ['2026-13-01T00:00:00+08:00'],
            'february 30' => ['2026-02-30T00:00:00+08:00'],
            'hour 24' => ['2026-10-27T24:00:00+08:00'],
            'second 60' => ['2026-10-27T00:00:60+08:00'],
            'trailing junk' => ['2026-10-27T00:00:00+08:00abc'],
            'trailing newline' => ["2026-10-27T00:00:00+08:00\n"],
            'leading space' => [' 2026-10-27T00:00:00+08:00'],
            'date only' => ['2026-10-27'],
            'space separator' => ['2026-10-27 00:00:00+08:00'],
            'offset range' => ['2026-10-27T00:00:00+99:00'],
            'whitespace' => [' '],
            'text' => ['garbage'],
            'not a string' => [true],
        ];
    }

    public function test_future_floor_is_invalid(): void
    {
        $this->assertSame('future', IntakeContainmentFloor::resolve('2026-11-07T12:00:01+00:00')->reason);
        $this->assertSame('invalid', IntakeContainmentFloor::resolve('2026-11-07T20:00:01+08:00')->state);
        $this->assertSame('armed', IntakeContainmentFloor::resolve('2026-11-07T20:00:00+08:00')->state);
    }

    public function test_z_and_offset_are_the_same_instant_in_the_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Manila']);

        $offset = IntakeContainmentFloor::resolve('2026-10-27T00:00:00+08:00');
        $zulu = IntakeContainmentFloor::resolve('2026-10-26T16:00:00Z');

        $this->assertSame('armed', $zulu->state);
        $this->assertTrue($offset->floor->equalTo($zulu->floor));
        $this->assertSame('2026-10-27 00:00:00', $zulu->floor->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Manila', $zulu->floor->getTimezone()->getName());
    }

    public function test_z_floor_does_not_shift_the_boundary_in_the_query_or_the_job(): void
    {
        config([
            'app.timezone' => 'Asia/Manila',
            'tsms.intake.containment_floor' => '2026-10-26T16:00:00Z',
        ]);
        Queue::fake();

        $before = $this->intake(['received_at' => '2026-10-26 23:59:59', 'queued_at' => self::STALE]);
        $at = $this->intake(['received_at' => '2026-10-27 00:00:00', 'queued_at' => self::STALE]);

        $this->artisan('tsms:reconcile-intake')->assertSuccessful();

        $this->assertNotDispatched($before);
        $this->assertDispatched($at);

        $this->assertHeldByPipeline($before);
        $this->assertProcessedByPipeline($at);
    }

    #[DataProvider('nonHonouringEnvironments')]
    public function test_set_floor_is_ignored_outside_staging_and_testing(string $environment): void
    {
        $this->setEnvironment($environment);

        $this->assertSame('ignored', IntakeContainmentFloor::resolve(self::FLOOR)->state);
        $this->assertSame('ignored', IntakeContainmentFloor::resolve('garbage')->state);
        $this->assertNull(IntakeContainmentFloor::resolve(self::FLOOR)->floor);
    }

    public static function nonHonouringEnvironments(): array
    {
        return [['production'], ['local']];
    }

    public function test_staging_honours_the_floor(): void
    {
        $this->setEnvironment('staging');

        $this->assertSame('armed', IntakeContainmentFloor::resolve(self::FLOOR)->state);
    }

    public function test_ignored_floor_never_holds_and_alerts_with_rate_limit(): void
    {
        $this->setEnvironment('production');
        config(['tsms.intake.containment_floor' => self::FLOOR]);
        Log::spy();

        $first = $this->intake(['received_at' => self::BEFORE_FLOOR]);
        $second = $this->intake(['received_at' => self::BEFORE_FLOOR]);

        $this->assertProcessedByPipeline($first);
        $this->assertProcessedByPipeline($second);

        Log::shouldHaveReceived('critical')->once();
    }

    // ------------------------------------------------------------ reconciler

    #[DataProvider('normalModeQueries')]
    public function test_each_normal_mode_query_respects_the_floor(array $state, array $stale): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);
        Queue::fake();

        $pre = $this->intake($state + $stale + ['received_at' => self::BEFORE_FLOOR]);
        $at = $this->intake($state + $stale + ['received_at' => self::AT_FLOOR]);
        $post = $this->intake($state + $stale + ['received_at' => self::AFTER_FLOOR]);
        $this->ageUpdatedAt($pre, $at, $post);
        $preRow = $this->rawRow($pre);

        $this->artisan('tsms:reconcile-intake')->assertSuccessful();

        $this->assertNotDispatched($pre);
        $this->assertDispatched($at);
        $this->assertDispatched($post);
        $this->assertEquals($preRow, $this->rawRow($pre));
    }

    #[DataProvider('normalModeQueries')]
    public function test_each_normal_mode_query_is_unchanged_without_a_floor(array $state, array $stale): void
    {
        Queue::fake();

        $pre = $this->intake($state + $stale + ['received_at' => self::BEFORE_FLOOR]);
        $this->ageUpdatedAt($pre);

        $this->artisan('tsms:reconcile-intake')->assertSuccessful();

        $this->assertDispatched($pre);
    }

    public static function normalModeQueries(): array
    {
        return [
            'accepted' => [['intake_status' => TransactionIntake::INTAKE_STATUS_ACCEPTED, 'processing_status' => null], []],
            'queued pending' => [['intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED, 'processing_status' => null], ['queued_at' => self::STALE]],
            'queued processing' => [['intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED, 'processing_status' => TransactionIntake::PROCESSING_STATUS_PROCESSING], ['queued_at' => self::STALE]],
            'queued retryable' => [['intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED, 'processing_status' => TransactionIntake::PROCESSING_STATUS_FAILED_RETRYABLE], ['queued_at' => self::STALE]],
        ];
    }

    public function test_null_received_at_is_excluded_when_armed(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);
        Queue::fake();

        $row = $this->intake(['received_at' => null, 'queued_at' => self::STALE]);

        $this->artisan('tsms:reconcile-intake')->assertSuccessful();

        $this->assertNotDispatched($row);
    }

    #[DataProvider('invalidFloors')]
    public function test_invalid_floor_skips_normal_mode_without_dispatch(string $floor): void
    {
        config(['tsms.intake.containment_floor' => $floor]);
        Queue::fake();
        Log::spy();

        $row = $this->intake(['received_at' => self::AFTER_FLOOR, 'queued_at' => self::STALE]);
        $before = $this->rawRow($row);

        $this->artisan('tsms:reconcile-intake')->assertFailed();
        $this->artisan('tsms:reconcile-intake')->assertFailed();

        Queue::assertNotPushed(ProcessTransactionIntakeJob::class);
        $this->assertEquals($before, $this->rawRow($row));
        Log::shouldHaveReceived('critical')->once();
    }

    public static function invalidFloors(): array
    {
        return [
            'malformed' => ['garbage'],
            'overflow' => ['2026-13-01T00:00:00+08:00'],
            'future' => ['2030-01-01T00:00:00+00:00'],
        ];
    }

    public function test_armed_run_logs_one_line_and_runs_no_breakdown_query(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);
        Queue::fake();
        Log::spy();
        $queries = $this->captureQueries();

        $this->artisan('tsms:reconcile-intake')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => $message === 'ReconcileStrandedIntake: containment floor armed')
            ->once();
        $this->assertSame([], array_filter($queries->all, fn (string $sql) => stripos($sql, 'group by') !== false));
    }

    public function test_armed_log_is_not_written_for_dry_run(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);
        Log::spy();

        $this->artisan('tsms:reconcile-intake', ['--dry-run' => true, '--tenant' => $this->tenant->id])->assertSuccessful();

        Log::shouldNotHaveReceived('info', ['ReconcileStrandedIntake: containment floor armed', \Mockery::any()]);
    }

    // ---------------------------------------------------------------- repair

    public function test_dry_run_alone_is_unaffected_by_the_freeze_and_a_bad_floor(): void
    {
        config(['tsms.intake.retry_freeze' => true, 'tsms.intake.containment_floor' => 'garbage']);
        Queue::fake();
        $ingest = $this->spyIngest();

        $this->processedIntake();

        $this->artisan('tsms:reconcile-intake', ['--dry-run' => true, '--tenant' => $this->tenant->id])
            ->expectsOutputToContain('Dry run only')
            ->assertSuccessful();

        $this->assertSame(0, $ingest->calls);
        Queue::assertNothingPushed();
    }

    #[DataProvider('pausedRepairOptions')]
    public function test_repair_is_paused_by_the_freeze_before_any_query(array $options): void
    {
        config(['tsms.intake.retry_freeze' => true]);
        Queue::fake();
        $ingest = $this->spyIngest();
        $row = $this->processedIntake();
        $before = $this->rawRow($row);
        $queries = $this->captureQueries();

        $this->artisan('tsms:reconcile-intake', $options + ['--tenant' => $this->tenant->id])
            ->expectsOutputToContain('Repair is paused')
            ->assertExitCode(ReconcileStrandedIntake::EXIT_REPAIR_PAUSED);

        $this->assertSame([], $queries->all);
        $this->assertSame(0, $ingest->calls);
        Queue::assertNothingPushed();
        $this->assertEquals($before, $this->rawRow($row));
    }

    public static function pausedRepairOptions(): array
    {
        return [
            'repair' => [['--repair-missing' => true]],
            'dry run and repair' => [['--dry-run' => true, '--repair-missing' => true]],
        ];
    }

    public function test_pre_floor_processed_rows_repair_and_held_queued_rows_are_untouched_with_freeze_off(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);
        Queue::fake();
        $ingest = $this->spyIngest(['status' => 'success', 'id' => 1]);

        $processed = $this->processedIntake();
        $held = $this->intake(['received_at' => self::BEFORE_FLOOR, 'queued_at' => self::STALE]);
        $heldRow = $this->rawRow($held);

        $this->artisan('tsms:reconcile-intake', ['--repair-missing' => true, '--tenant' => $this->tenant->id])
            ->expectsOutputToContain('Repaired: 1')
            ->assertSuccessful();

        $this->assertSame(1, $ingest->calls);
        $this->assertSame($processed->submission_uuid, $ingest->payloads[0]['submission_uuid']);
        $this->assertEquals($heldRow, $this->rawRow($held));
        $this->assertNotDispatched($held);
    }

    // ------------------------------------------------------------ job pipeline

    public function test_contained_middleware_runs_before_without_overlapping(): void
    {
        $middleware = (new ProcessTransactionIntakeJob(1))->middleware();

        $this->assertInstanceOf(HoldContainedIntake::class, $middleware[0]);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[1]);
    }

    public function test_pre_floor_job_with_lock_held_is_deleted_without_any_effect(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);
        $row = $this->intake(['received_at' => self::BEFORE_FLOOR]);

        $this->holdLock($row);
        $locks = Cache::getStore()->locks;

        $this->assertHeldByPipeline($row);

        $this->assertSame($locks, Cache::getStore()->locks);
        $this->assertFalse(Cache::lock($this->lockKey($row))->get());
    }

    public function test_pre_floor_job_with_lock_absent_is_deleted_without_taking_the_lock(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);
        $row = $this->intake(['received_at' => self::BEFORE_FLOOR]);
        $observed = [];
        DB::listen(function ($query) use (&$observed, $row): void {
            $observed[] = isset(Cache::getStore()->locks[$this->lockKey($row)]);
        });

        $this->assertHeldByPipeline($row);

        $this->assertNotContains(true, $observed);
        $this->assertArrayNotHasKey($this->lockKey($row), Cache::getStore()->locks);
    }

    public function test_null_received_at_job_is_held(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);
        Log::spy();
        $row = $this->intake(['received_at' => null]);

        $this->assertHeldByPipeline($row);

        $this->assertHeldLogged($row, 'null_received_at', $this->tenant->id);
    }

    public function test_held_log_is_structured_without_payload_and_never_critical(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);
        Log::spy();
        $row = $this->intake(['received_at' => self::BEFORE_FLOOR]);

        $this->assertHeldByPipeline($row);
        $this->assertHeldByPipeline($row);

        $this->assertHeldLogged($row, 'before_floor', $this->tenant->id, 2);
        Log::shouldNotHaveReceived('critical');
    }

    public function test_at_and_after_floor_jobs_process(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);

        $this->assertProcessedByPipeline($this->intake(['received_at' => self::AT_FLOOR]));
        $this->assertProcessedByPipeline($this->intake(['received_at' => self::AFTER_FLOOR]));
    }

    public function test_invalid_floor_holds_every_row_with_a_rate_limited_alert(): void
    {
        config(['tsms.intake.containment_floor' => 'garbage']);
        Log::spy();

        $this->assertHeldByPipeline($this->intake(['received_at' => self::BEFORE_FLOOR]));
        $this->assertHeldByPipeline($this->intake(['received_at' => self::AFTER_FLOOR]));

        Log::shouldHaveReceived('critical')->once();
    }

    public function test_future_floor_holds_every_row(): void
    {
        config(['tsms.intake.containment_floor' => '2030-01-01T00:00:00+00:00']);

        $this->assertHeldByPipeline($this->intake(['received_at' => self::AFTER_FLOOR]));
    }

    public function test_alert_limiter_outage_does_not_fail_the_hold_or_log_critical(): void
    {
        config(['tsms.intake.containment_floor' => 'garbage']);
        Log::spy();
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache down'));

        IntakeContainmentFloor::current()->alertIfNeeded();

        Log::shouldHaveReceived('warning')->once();
        Log::shouldNotHaveReceived('critical');
    }

    public function test_absent_floor_processes_and_releases_on_a_held_lock_as_today(): void
    {
        $this->assertProcessedByPipeline($this->intake(['received_at' => self::BEFORE_FLOOR]));

        $locked = $this->intake(['received_at' => self::BEFORE_FLOOR]);
        $this->holdLock($locked);
        $ingest = $this->spyIngest();

        $job = $this->runPipeline($locked);

        $this->assertTrue($job->isReleased());
        $this->assertSame(10, $job->releaseDelay);
        $this->assertSame(0, $ingest->calls);
    }

    public function test_absent_floor_adds_no_query_to_the_held_lock_path(): void
    {
        $row = $this->intake(['received_at' => self::BEFORE_FLOOR]);
        $this->holdLock($row);
        $this->spyIngest();
        $queries = $this->captureQueries();

        $this->runPipeline($row);

        $this->assertSame([], $queries->all);
    }

    public function test_missing_row_continues_to_the_not_found_path(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);
        Log::spy();
        $ingest = $this->spyIngest();

        $job = $this->runPipeline(0);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => $message === 'ProcessTransactionIntakeJob: Intake record not found')
            ->once();
        $this->assertTrue($job->isDeleted());
        $this->assertFalse($job->hasFailed());
        $this->assertSame(0, $ingest->calls);
    }

    public function test_failed_database_read_is_not_swallowed(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);
        $row = $this->intake(['received_at' => self::AFTER_FLOOR]);
        $ingest = $this->spyIngest();
        DB::listen(function ($query): void {
            if (str_contains($query->sql, '`received_at`, `tenant_id`')) {
                throw new \RuntimeException('database read failed');
            }
        });

        try {
            $this->runPipeline($row);
            $this->fail('The failed read was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('database read failed', $e->getMessage());
        }

        $this->assertSame(0, $ingest->calls);
    }

    // ------------------------------------------------------------ overlap timing

    public function test_overlap_timing_defaults_keep_todays_behaviour(): void
    {
        $this->assertSame(0, config('tsms.intake.overlap_expire_seconds'));
        $this->assertSame(10, config('tsms.intake.overlap_release_seconds'));

        $overlap = (new ProcessTransactionIntakeJob(1))->middleware()[1];

        $this->assertSame(0, $overlap->expiresAfter);
        $this->assertSame(10, $overlap->releaseAfter);
    }

    public function test_overlap_timing_follows_config_and_holds_against_staging_config(): void
    {
        config(['tsms.intake.overlap_expire_seconds' => 300, 'tsms.intake.overlap_release_seconds' => 310]);

        $overlap = (new ProcessTransactionIntakeJob(1))->middleware()[1];

        $this->assertSame(300, $overlap->expiresAfter);
        $this->assertSame(310, $overlap->releaseAfter);

        // The staging intake supervisor sets no timeout, so Horizon's default of 60 applies.
        $timeout = config('horizon.environments.staging.intake-supervisor.timeout', 60);
        $this->assertSame(60, $timeout);
        $this->assertLessThan($overlap->expiresAfter, $timeout);
        $this->assertLessThan(config('queue.connections.redis.retry_after'), $overlap->expiresAfter);
        $this->assertSame(360, config('queue.connections.redis.retry_after'));
        $this->assertLessThan($overlap->releaseAfter, $overlap->expiresAfter);

        $row = $this->intake(['received_at' => self::AFTER_FLOOR]);
        $this->holdLock($row);
        $this->spyIngest();

        $this->assertSame(310, $this->runPipeline($row)->releaseDelay);
    }

    #[DataProvider('unusableOverlapConfig')]
    public function test_held_lock_releases_with_defaults_when_overlap_config_is_unusable(mixed $release, mixed $expire, bool $missing): void
    {
        $this->setOverlapConfig($release, $expire, $missing);

        $overlap = (new ProcessTransactionIntakeJob(1))->middleware()[1];
        $this->assertSame(10, $overlap->releaseAfter);
        $this->assertSame(0, $overlap->expiresAfter);

        $row = $this->intake(['received_at' => self::AFTER_FLOOR]);
        $this->holdLock($row);
        $ingest = $this->spyIngest();
        $before = $this->rawRow($row);

        $job = $this->runPipeline($row);

        $this->assertTrue($job->isReleased());
        $this->assertSame(10, $job->releaseDelay);
        $this->assertFalse($job->isDeleted());
        $this->assertFalse($job->hasFailed());
        $this->assertSame(0, $ingest->calls);
        $this->assertEquals($before, $this->rawRow($row));
    }

    public static function unusableOverlapConfig(): array
    {
        return [
            'missing keys' => [null, null, true],
            'null' => [null, null, false],
            'blank' => ['', '', false],
            'zero release' => [0, 0, false],
            'negative' => [-5, -5, false],
            'text' => ['abc', 'abc', false],
        ];
    }

    public function test_lock_is_still_acquired_and_job_processes_when_overlap_config_is_missing(): void
    {
        $this->setOverlapConfig(null, null, true);

        $this->assertProcessedByPipeline($this->intake(['received_at' => self::AFTER_FLOOR]));
    }

    #[DataProvider('overlapEnvValues')]
    public function test_overlap_env_values_at_the_config_level(?string $release, ?string $expire, int $expectedRelease, int $expectedExpire): void
    {
        $names = [
            'TSMS_INTAKE_OVERLAP_RELEASE_SECONDS' => $release,
            'TSMS_INTAKE_OVERLAP_EXPIRE_SECONDS' => $expire,
        ];

        try {
            foreach ($names as $name => $value) {
                $this->setEnvValue($name, $value);
            }

            $intake = (require config_path('tsms.php'))['intake'];
        } finally {
            foreach (array_keys($names) as $name) {
                $this->setEnvValue($name, null);
            }
        }

        $this->assertSame($expectedRelease, $intake['overlap_release_seconds']);
        $this->assertSame($expectedExpire, $intake['overlap_expire_seconds']);
    }

    public static function overlapEnvValues(): array
    {
        return [
            'unset' => [null, null, 10, 0],
            'blank' => ['', '', 10, 0],
            'staging values' => ['310', '300', 310, 300],
        ];
    }

    public function test_missing_floor_and_freeze_keys_behave_as_absent_and_off(): void
    {
        $this->forgetIntakeConfig(['containment_floor', 'retry_freeze', 'overlap_release_seconds', 'overlap_expire_seconds']);
        Queue::fake();
        $ingest = $this->spyIngest(['status' => 'success', 'id' => 1]);

        $this->assertSame('absent', IntakeContainmentFloor::current()->state);

        $stranded = $this->intake(['received_at' => self::BEFORE_FLOOR, 'queued_at' => self::STALE]);
        $this->artisan('tsms:reconcile-intake')->assertSuccessful();
        $this->assertDispatched($stranded);

        $this->processedIntake();
        $this->artisan('tsms:reconcile-intake', ['--repair-missing' => true, '--tenant' => $this->tenant->id])->assertSuccessful();
        $this->assertSame(1, $ingest->calls);

        $this->assertProcessedByPipeline($this->intake(['received_at' => self::BEFORE_FLOOR]));

        [$exit, $output] = $this->runStatus();
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Floor state: absent', $output);
        $this->assertStringContainsString('Retry freeze: off', $output);
        $this->assertStringContainsString('Overlap expire seconds: 0', $output);
        $this->assertStringContainsString('Overlap release seconds: 10', $output);

        $this->actingAsDlqAdmin();
        Artisan::shouldReceive('call')->once()->andReturn(0);
        $uuid = $this->failedJob($this->frozenPayloads()['display name']);
        $this->postJson("/api/v1/admin/failed-jobs/{$uuid}/retry")->assertOk();
    }

    // ------------------------------------------------------------- retry freeze

    #[DataProvider('floorStates')]
    public function test_retry_all_is_frozen_before_counting_in_every_floor_state(?string $floor, string $environment, string $state): void
    {
        $this->arrangeFreeze($floor, $environment, $state);
        $uuid = $this->failedJob(json_encode(['displayName' => 'App\\Jobs\\SomeOtherJob']));
        $queries = $this->captureQueries();

        $response = $this->postJson('/api/v1/admin/failed-jobs/retry-all');

        $response->assertStatus(409);
        $this->assertSame([], array_filter($queries->all, fn (string $sql) => str_contains($sql, 'failed_jobs')));
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
    }

    #[DataProvider('floorStates')]
    public function test_single_retry_is_frozen_in_every_floor_state(?string $floor, string $environment, string $state): void
    {
        $this->arrangeFreeze($floor, $environment, $state);

        foreach ($this->frozenPayloads() as $payload) {
            $uuid = $this->failedJob($payload);

            $this->postJson("/api/v1/admin/failed-jobs/{$uuid}/retry")->assertStatus(409);
            $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
        }

        $this->postJson('/api/v1/admin/failed-jobs/'.Str::uuid().'/retry')->assertStatus(404);

        $other = $this->failedJob(json_encode([
            'displayName' => 'App\\Jobs\\SomeOtherJob',
            'data' => ['commandName' => 'App\\Jobs\\SomeOtherJob'],
        ]));

        $this->postJson("/api/v1/admin/failed-jobs/{$other}/retry")->assertOk();
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $other]);
    }

    public static function floorStates(): array
    {
        return [
            'armed' => [self::FLOOR, 'testing', 'armed'],
            'absent' => [null, 'testing', 'absent'],
            'malformed' => ['garbage', 'testing', 'invalid'],
            'future' => ['2030-01-01T00:00:00+00:00', 'testing', 'invalid'],
            'non staging ignored' => [self::FLOOR, 'production', 'ignored'],
        ];
    }

    public function test_freeze_off_leaves_retries_unchanged(): void
    {
        $this->actingAsDlqAdmin();
        $intakeJob = $this->failedJob($this->frozenPayloads()['display name']);
        $unreadable = $this->failedJob('{not json');

        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:retry', ['id' => [$intakeJob]])
            ->andReturn(0);
        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:retry', ['id' => [$unreadable]])
            ->andReturn(0);
        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:retry', ['id' => ['all']])
            ->andReturn(0);

        $this->postJson("/api/v1/admin/failed-jobs/{$intakeJob}/retry")->assertOk();
        $this->postJson("/api/v1/admin/failed-jobs/{$unreadable}/retry")->assertOk();
        $this->postJson('/api/v1/admin/failed-jobs/retry-all')->assertOk();
        $this->postJson('/api/v1/admin/failed-jobs/'.Str::uuid().'/retry')->assertStatus(404);
    }

    // --------------------------------------------------------------- dashboard

    public function test_dashboard_reports_paused_repair_as_non_success_with_normal_output(): void
    {
        config(['tsms.intake.retry_freeze' => true]);
        Queue::fake();
        $ingest = $this->spyIngest();
        $this->actingAsReconciler();
        $stranded = $this->intake(['received_at' => self::AFTER_FLOOR, 'queued_at' => self::STALE]);

        $response = $this->postJson('/api/transactions/logs/reconcile');

        $response->assertStatus(409)
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('details.repair_paused', true)
            ->assertJsonPath('details.stranded_skipped', false);
        $this->assertStringContainsString('Repair is paused', $response->json('message'));
        $this->assertStringContainsString('Re-dispatching', $response->json('message'));
        $this->assertStringContainsString('Re-dispatching', $response->json('details.stranded'));
        $this->assertDispatched($stranded);
        $this->assertSame(0, $ingest->calls);
    }

    public function test_dashboard_reports_a_skipped_normal_run_as_non_success(): void
    {
        config(['tsms.intake.containment_floor' => 'garbage']);
        Queue::fake();
        $this->spyIngest();
        $this->actingAsReconciler();

        $response = $this->postJson('/api/transactions/logs/reconcile');

        $response->assertStatus(409)
            ->assertJsonPath('details.stranded_skipped', true)
            ->assertJsonPath('details.repair_paused', false);
        $this->assertStringContainsString('skipped', $response->json('message'));
        Queue::assertNotPushed(ProcessTransactionIntakeJob::class);
    }

    public function test_dashboard_is_unchanged_with_the_freeze_off(): void
    {
        Queue::fake();
        $this->spyIngest();
        $this->actingAsReconciler();

        $this->postJson('/api/transactions/logs/reconcile')
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    // ---------------------------------------------------------- status command

    public function test_status_lists_excluded_rows_that_the_reconciler_does_not_dispatch(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR, 'tsms.intake.retry_freeze' => true]);
        Queue::fake();
        $this->spyIngest();

        $leftover = $this->excludedIds();
        $excluded = $this->arrangeStatusRows();
        $post = $this->intake(['received_at' => self::AFTER_FLOOR, 'queued_at' => self::STALE]);
        $terminal = $this->intake([
            'received_at' => self::BEFORE_FLOOR,
            'processing_status' => TransactionIntake::PROCESSING_STATUS_PROCESSED,
        ]);

        [$exit, $output] = $this->runStatus();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Environment: testing', $output);
        $this->assertStringContainsString('Floor state: armed', $output);
        $this->assertStringContainsString('Normalized floor: 2026-11-07T10:00:00+00:00', $output);
        $this->assertStringContainsString('Retry freeze: on', $output);
        $this->assertStringContainsString('Overlap expire seconds: 0', $output);
        $this->assertStringContainsString('Overlap release seconds: 10', $output);
        $this->assertStringContainsString('Excluded rows: '.(count($leftover) + count($excluded)), $output);

        $listed = $this->listedIds($output);
        $this->assertEqualsCanonicalizing(array_merge($leftover, $excluded), $listed);
        $this->assertNotContains($post->id, $listed);
        $this->assertNotContains($terminal->id, $listed);

        $this->artisan('tsms:reconcile-intake')->assertSuccessful();

        foreach ($excluded as $id) {
            Queue::assertNotPushed(ProcessTransactionIntakeJob::class, fn ($job) => $job->intakeId === $id);
        }
        $this->assertDispatched($post);
    }

    #[DataProvider('manifestFormats')]
    public function test_status_manifest_match_exits_zero(bool $withHeader): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);

        $ids = array_merge($this->excludedIds(), $this->arrangeStatusRows());

        [$exit, $output] = $this->runStatus(['--manifest' => $this->manifest($ids, $withHeader)]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Manifest rows: '.count($ids), $output);
        $this->assertStringContainsString('Only in manifest (0)', $output);
        $this->assertStringContainsString('Only excluded (0)', $output);
    }

    public static function manifestFormats(): array
    {
        return ['csv with header' => [true], 'plain list' => [false]];
    }

    public function test_status_manifest_differences_exit_non_zero(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);

        $ids = array_merge($this->excludedIds(), $this->arrangeStatusRows());
        $missing = array_pop($ids);

        [$exit, $output] = $this->runStatus(['--manifest' => $this->manifest($ids)]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString("Only excluded (1): {$missing}", $output);

        $extra = PHP_INT_MAX;
        [$exit, $output] = $this->runStatus(['--manifest' => $this->manifest([...$ids, $missing, $extra])]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString("Only in manifest (1): {$extra}", $output);
    }

    public function test_status_rejects_an_unreadable_or_invalid_manifest_without_querying(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);
        $queries = $this->captureQueries();

        $this->assertSame(1, $this->runStatus(['--manifest' => '/nonexistent/manifest.csv'])[0]);

        $bad = $this->manifest(['12', 'abc']);
        $this->assertSame(1, $this->runStatus(['--manifest' => $bad])[0]);

        $this->assertSame([], $queries->all);
    }

    public function test_status_previews_a_floor_before_it_is_enabled(): void
    {
        $this->assertNull(config('tsms.intake.containment_floor'));

        $row = $this->intake(['received_at' => self::BEFORE_FLOOR]);

        [$exit, $output] = $this->runStatus(['--floor' => self::FLOOR]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Floor source: preview', $output);
        $this->assertContains($row->id, $this->listedIds($output));

        [$exit, $output] = $this->runStatus(['--floor' => '2026-10-01T00:00:00+00:00']);

        $this->assertSame(0, $exit);
        $this->assertNotContains($row->id, $this->listedIds($output));
    }

    #[DataProvider('unpreviewableStates')]
    public function test_status_exits_non_zero_when_not_armed_or_previewable(array $options, ?string $configured, string $environment, string $state): void
    {
        $this->setEnvironment($environment);
        config(['tsms.intake.containment_floor' => $configured]);
        Log::spy();
        $queries = $this->captureQueries();

        [$exit, $output] = $this->runStatus($options);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("Floor state: {$state}", $output);
        $this->assertSame([], $queries->all);
        Log::shouldNotHaveReceived('critical');
    }

    public static function unpreviewableStates(): array
    {
        return [
            'configured absent' => [[], null, 'testing', 'absent'],
            'configured malformed' => [[], 'garbage', 'testing', 'invalid'],
            'preview malformed' => [['--floor' => '2026-10-27T00:00:00'], null, 'testing', 'invalid'],
            'preview future' => [['--floor' => '2030-01-01T00:00:00+00:00'], null, 'testing', 'invalid'],
            'configured in production' => [[], self::FLOOR, 'production', 'ignored'],
            'preview in production' => [['--floor' => self::FLOOR], null, 'production', 'ignored'],
        ];
    }

    public function test_status_only_runs_one_bounded_id_select(): void
    {
        config(['tsms.intake.containment_floor' => self::FLOOR]);
        $this->arrangeStatusRows();
        $queries = $this->captureQueries();

        $this->assertSame(0, $this->runStatus()[0]);

        $this->assertCount(1, $queries->all);
        $this->assertStringStartsWith('select /*+ MAX_EXECUTION_TIME(30000) */ id from', $queries->all[0]);
        $this->assertStringNotContainsString('payload', $queries->all[0]);
    }

    // ----------------------------------------------------------------- helpers

    private function setEnvironment(string $environment): void
    {
        $this->app['env'] = $environment;
    }

    private function setOverlapConfig(mixed $release, mixed $expire, bool $missing): void
    {
        if ($missing) {
            $this->forgetIntakeConfig(['overlap_release_seconds', 'overlap_expire_seconds']);

            return;
        }

        config(['tsms.intake.overlap_release_seconds' => $release, 'tsms.intake.overlap_expire_seconds' => $expire]);
    }

    private function forgetIntakeConfig(array $keys): void
    {
        config(['tsms.intake' => array_diff_key(config('tsms.intake'), array_flip($keys))]);

        foreach ($keys as $key) {
            $this->assertArrayNotHasKey($key, config('tsms.intake'));
        }
    }

    private function setEnvValue(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }

    private function intake(array $attributes = []): TransactionIntake
    {
        $uuid = (string) Str::uuid();

        $intake = TransactionIntake::create($attributes + [
            'submission_uuid' => $uuid,
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'payload_checksum' => str_repeat('a', 64),
            'payload' => [
                'submission_uuid' => $uuid,
                'submission_timestamp' => '2026-10-26T08:00:00Z',
                'transaction' => ['transaction_id' => (string) Str::uuid(), 'receipt_no' => 'R-1'],
            ],
            'payload_size_bytes' => 128,
            'source_ip' => '127.0.0.1',
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => null,
            'trace_id' => (string) Str::uuid(),
        ]);

        $this->intakeIds[] = $intake->id;

        return $intake;
    }

    private function processedIntake(): TransactionIntake
    {
        return $this->intake([
            'received_at' => self::BEFORE_FLOOR,
            'queued_at' => self::BEFORE_FLOOR,
            'processed_at' => self::BEFORE_FLOOR,
            'processing_status' => TransactionIntake::PROCESSING_STATUS_PROCESSED,
        ]);
    }

    private function ageUpdatedAt(TransactionIntake ...$rows): void
    {
        DB::table('transaction_intake')
            ->whereIn('id', array_map(fn (TransactionIntake $row) => $row->id, $rows))
            ->update(['updated_at' => self::STALE]);
    }

    private function rawRow(TransactionIntake $row): object
    {
        return DB::table('transaction_intake')->where('id', $row->id)->first();
    }

    private function assertDispatched(TransactionIntake $row): void
    {
        Queue::assertPushed(ProcessTransactionIntakeJob::class, fn ($job) => $job->intakeId === $row->id);
    }

    private function assertNotDispatched(TransactionIntake $row): void
    {
        Queue::assertNotPushed(ProcessTransactionIntakeJob::class, fn ($job) => $job->intakeId === $row->id);
    }

    private function lockKey(TransactionIntake $row): string
    {
        return (new WithoutOverlapping((string) $row->id))->getLockKey(new ProcessTransactionIntakeJob($row->id));
    }

    private function holdLock(TransactionIntake $row): void
    {
        $this->assertTrue(Cache::lock($this->lockKey($row), 0)->get());
    }

    private function spyIngest(array $result = ['status' => 'duplicate', 'id' => null]): TransactionIngestService
    {
        $spy = new class(app(DeadlockRetryService::class), app(ProviderTimestampNormalizer::class)) extends TransactionIngestService
        {
            public int $calls = 0;

            public array $payloads = [];

            public array $result = [];

            public function ingest(array $payload, ?string $correlationId = null): array
            {
                $this->calls++;
                $this->payloads[] = $payload;

                return $this->result;
            }
        };
        $spy->result = $result;
        $this->app->instance(TransactionIngestService::class, $spy);

        return $spy;
    }

    /**
     * Runs the job through the real worker, CallQueuedHandler and middleware pipeline.
     */
    private function runPipeline(TransactionIntake|int $row): SyncJob
    {
        $id = $row instanceof TransactionIntake ? $row->id : $row;

        $payload = json_encode([
            'uuid' => (string) Str::uuid(),
            'displayName' => ProcessTransactionIntakeJob::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => null,
            'retryUntil' => null,
            'data' => [
                'commandName' => ProcessTransactionIntakeJob::class,
                'command' => serialize(new ProcessTransactionIntakeJob($id, 'trace-test')),
            ],
        ]);

        $job = new class($this->app, $payload, 'sync', 'test') extends SyncJob
        {
            public ?int $releaseDelay = null;

            public function release($delay = 0)
            {
                $this->releaseDelay = $delay;

                parent::release($delay);
            }
        };

        $this->app->make('queue.worker')->process('sync', $job, new WorkerOptions);

        return $job;
    }

    private function assertHeldByPipeline(TransactionIntake $row): void
    {
        $ingest = $this->spyIngest();
        $before = $this->rawRow($row);
        $failures = 0;
        Event::listen(JobFailed::class, function () use (&$failures): void {
            $failures++;
        });

        $job = $this->runPipeline($row);

        $this->assertTrue($job->isDeleted());
        $this->assertFalse($job->isReleased());
        $this->assertNull($job->releaseDelay);
        $this->assertFalse($job->hasFailed());
        $this->assertSame(0, $failures);
        $this->assertSame(0, $ingest->calls);
        $this->assertEquals($before, $this->rawRow($row));
    }

    private function assertProcessedByPipeline(TransactionIntake $row): void
    {
        $ingest = $this->spyIngest();

        $job = $this->runPipeline($row);

        $this->assertTrue($job->isDeleted());
        $this->assertFalse($job->hasFailed());
        $this->assertSame(1, $ingest->calls);
        $this->assertSame(TransactionIntake::PROCESSING_STATUS_DUPLICATE, $row->fresh()->processing_status);
    }

    private function assertHeldLogged(TransactionIntake $row, string $reason, int $tenantId, int $times = 1): void
    {
        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($row, $reason, $tenantId): bool {
                return $message === 'ProcessTransactionIntakeJob: held by containment floor'
                    && $context['intake_id'] === $row->id
                    && $context['tenant_id'] === $tenantId
                    && $context['reason'] === $reason
                    && ! array_key_exists('payload', $context);
            })
            ->times($times);
    }

    private function captureQueries(): object
    {
        $queries = new class
        {
            public array $all = [];
        };

        DB::listen(function ($query) use ($queries): void {
            $queries->all[] = $query->sql;
        });

        return $queries;
    }

    private function arrangeFreeze(?string $floor, string $environment, string $state): void
    {
        $this->setEnvironment($environment);
        config(['tsms.intake.containment_floor' => $floor, 'tsms.intake.retry_freeze' => true]);
        $this->assertSame($state, IntakeContainmentFloor::current()->state);
        $this->actingAsDlqAdmin();
    }

    private function actingAsDlqAdmin(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['admin:manage']);
    }

    private function actingAsReconciler(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'sanctum']);
        $user = User::factory()->create();
        $user->assignRole(\Spatie\Permission\Models\Role::findByName('admin', 'web'));
        Sanctum::actingAs($user, ['*']);
    }

    private function frozenPayloads(): array
    {
        return [
            'display name' => json_encode(['displayName' => ProcessTransactionIntakeJob::class]),
            'command name' => json_encode(['displayName' => 'Renamed', 'data' => ['commandName' => ProcessTransactionIntakeJob::class]]),
            'invalid json' => '{not json',
            'empty' => '',
            'not an object' => '"text"',
            'no job class' => json_encode(['uuid' => 'x']),
        ];
    }

    private function failedJob(string $payload): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'sync',
            'queue' => 'test',
            'payload' => $payload,
            'exception' => 'test',
            'failed_at' => self::NOW,
        ]);
        $this->failedJobUuids[] = $uuid;

        return $uuid;
    }

    /**
     * @return array<int, int> IDs of pre-floor rows in every status the reconciler considers
     */
    private function arrangeStatusRows(): array
    {
        $old = ['received_at' => self::BEFORE_FLOOR, 'queued_at' => self::STALE];
        $rows = [
            $this->intake($old),
            $this->intake(['received_at' => null, 'queued_at' => self::STALE]),
            $this->intake(['received_at' => self::BEFORE_FLOOR, 'intake_status' => TransactionIntake::INTAKE_STATUS_ACCEPTED]),
            $this->intake($old + ['processing_status' => TransactionIntake::PROCESSING_STATUS_PROCESSING]),
            $this->intake($old + ['processing_status' => TransactionIntake::PROCESSING_STATUS_FAILED_RETRYABLE]),
        ];
        $this->ageUpdatedAt(...$rows);

        return array_map(fn (TransactionIntake $row) => $row->id, $rows);
    }

    /**
     * IDs already excluded before a test adds rows, because of TestCase's isolation issue.
     *
     * @return array<int, int>
     */
    private function excludedIds(): array
    {
        return $this->listedIds($this->runStatus()[1]);
    }

    /**
     * @return array<int, int>
     */
    private function listedIds(string $output): array
    {
        preg_match('/^Excluded IDs: (.*)$/m', $output, $matches);

        return array_map('intval', array_filter(explode(',', trim($matches[1] ?? ''))));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runStatus(array $options = []): array
    {
        $exit = Artisan::call('tsms:intake-containment-status', $options);

        return [$exit, Artisan::output()];
    }

    private function manifest(array $ids, bool $withHeader = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'manifest');
        $lines = $withHeader
            ? array_merge(['intake_id,submission_uuid'], array_map(fn ($id) => "{$id},uuid", $ids))
            : array_map('strval', $ids);
        file_put_contents($path, implode("\n", $lines)."\n");
        $this->tempFiles[] = $path;

        return $path;
    }
}
