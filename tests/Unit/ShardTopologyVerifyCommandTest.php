<?php

namespace Tests\Unit;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Tests\Traits\FakesShardTopologyRedis;

/**
 * Unit tests for App\Console\Commands\VerifyShardTopology (T047): a
 * read-only, report-only command that (1) verifies the app-configured
 * processing shard count agrees with the current environment's Horizon
 * supervisor queue topology, and (2) with --to, classifies a proposed
 * shard-count change as unchanged/increase/decrease and, for decrease,
 * inspects each retired queue's real Redis ready/reserved/delayed depth.
 *
 * Config-array/fake-Redis-based only, per T047's explicit requirement — no
 * live Redis/Horizon process. Redis is faked via
 * tests/Traits/FakesShardTopologyRedis, mirroring FakesCircuitBreakerRedis/
 * FakesIngestionFairnessRedis's style but simplified to this command's
 * actual footprint (llen/zcard reads only).
 */
class ShardTopologyVerifyCommandTest extends TestCase
{
    use FakesShardTopologyRedis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeShardTopologyRedis('default');

        config()->set('app.env', 'testing');
        config()->set('queue.connections.redis.connection', 'default');
        config()->set('database.redis.options.prefix', 'tsms_test_');
    }

    private function setHorizonProcessingQueues(array $shardIndices, string $supervisorKey = 'processing-supervisor'): void
    {
        config()->set('horizon.environments.testing', [
            $supervisorKey => [
                'connection' => 'redis',
                'queue' => array_map(fn (int $i) => "transaction-processing:s{$i}", $shardIndices),
                'balance' => 'auto',
                'processes' => 2,
                'tries' => 2,
            ],
            'low-supervisor' => [
                'connection' => 'redis',
                'queue' => ['low'],
                'balance' => 'auto',
                'processes' => 1,
                'tries' => 2,
            ],
        ]);
    }

    public function test_matching_app_and_horizon_shard_counts_is_safe_with_exit_zero(): void
    {
        config()->set('tsms.processing.shard_count', 4);
        $this->setHorizonProcessingQueues([0, 1, 2, 3]);

        $exitCode = Artisan::call('tsms:shard-topology-verify');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertNoShardTopologyRedisMutations();
    }

    public function test_mismatched_app_and_horizon_shard_counts_is_unsafe_with_nonzero_exit(): void
    {
        config()->set('tsms.processing.shard_count', 4);
        // Horizon still only exposes 3 shard queues — stale, not yet restarted.
        $this->setHorizonProcessingQueues([0, 1, 2]);

        $exitCode = Artisan::call('tsms:shard-topology-verify');

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertNoShardTopologyRedisMutations();
    }

    public function test_mismatch_detected_regardless_of_which_supervisor_key_owns_processing(): void
    {
        // Mirrors production's real config/horizon.php, where the
        // processing queues live under 'high-supervisor', not
        // 'processing-supervisor' (that name is staging-only).
        config()->set('tsms.processing.shard_count', 2);
        $this->setHorizonProcessingQueues([0, 1], supervisorKey: 'high-supervisor');

        $exitCode = Artisan::call('tsms:shard-topology-verify');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertNoShardTopologyRedisMutations();
    }

    public function test_decrease_scenario_identifies_exact_retired_queue_set(): void
    {
        config()->set('tsms.processing.shard_count', 8);
        $this->setHorizonProcessingQueues(range(0, 7));

        $exitCode = Artisan::call('tsms:shard-topology-verify', ['--to' => 4]);
        $output = Artisan::output();

        // All retired queues (s4..s7) are drained (default fake depth 0), so
        // the topology overall remains safe.
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('decrease', $output);

        foreach (['transaction-processing:s4', 'transaction-processing:s5', 'transaction-processing:s6', 'transaction-processing:s7'] as $retiredQueue) {
            $this->assertStringContainsString($retiredQueue, $output);
        }

        // Surviving shards must NOT be reported as retired.
        foreach (['transaction-processing:s0', 'transaction-processing:s1', 'transaction-processing:s2', 'transaction-processing:s3'] as $activeQueue) {
            $this->assertStringNotContainsString("Retired queues (no consumer once Horizon is updated to the smaller count): {$activeQueue}", $output);
        }

        $this->assertNoShardTopologyRedisMutations();
    }

    public function test_increase_scenario_does_not_report_any_retired_queue(): void
    {
        config()->set('tsms.processing.shard_count', 4);
        $this->setHorizonProcessingQueues([0, 1, 2, 3]);

        $exitCode = Artisan::call('tsms:shard-topology-verify', ['--to' => 8]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('increase', $output);
        $this->assertStringNotContainsString('Retired queues', $output);
        $this->assertStringNotContainsString('UNSAFE', $output);

        // The command must never have needed to inspect Redis depth for an
        // increase (no retired queues exist to check).
        $this->assertSame([], $this->fakeShardTopologyReadCommands());
        $this->assertNoShardTopologyRedisMutations();
    }

    public function test_unchanged_classification_when_to_equals_current_count(): void
    {
        config()->set('tsms.processing.shard_count', 4);
        $this->setHorizonProcessingQueues([0, 1, 2, 3]);

        $exitCode = Artisan::call('tsms:shard-topology-verify', ['--to' => 4]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('unchanged', $output);
        $this->assertNoShardTopologyRedisMutations();
    }

    public function test_nonzero_exit_when_retired_queue_has_nonzero_ready_depth(): void
    {
        config()->set('tsms.processing.shard_count', 4);
        $this->setHorizonProcessingQueues([0, 1, 2, 3]);
        $this->seedShardTopologyReadyDepth('transaction-processing:s3', 5);

        $exitCode = Artisan::call('tsms:shard-topology-verify', ['--to' => 3]);
        $output = Artisan::output();

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('UNSAFE', $output);
        $this->assertStringContainsString('transaction-processing:s3', $output);
        $this->assertNoShardTopologyRedisMutations();
    }

    public function test_nonzero_exit_when_retired_queue_has_nonzero_reserved_depth(): void
    {
        config()->set('tsms.processing.shard_count', 4);
        $this->setHorizonProcessingQueues([0, 1, 2, 3]);
        $this->seedShardTopologyReservedDepth('transaction-processing:s3', 2);

        $exitCode = Artisan::call('tsms:shard-topology-verify', ['--to' => 3]);

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertNoShardTopologyRedisMutations();
    }

    public function test_nonzero_exit_when_retired_queue_has_nonzero_delayed_depth(): void
    {
        config()->set('tsms.processing.shard_count', 4);
        $this->setHorizonProcessingQueues([0, 1, 2, 3]);
        $this->seedShardTopologyDelayedDepth('transaction-processing:s3', 1);

        $exitCode = Artisan::call('tsms:shard-topology-verify', ['--to' => 3]);

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertNoShardTopologyRedisMutations();
    }

    public function test_safe_zero_exit_when_all_retired_queue_depths_are_zero_and_counts_agree(): void
    {
        config()->set('tsms.processing.shard_count', 8);
        $this->setHorizonProcessingQueues(range(0, 7));
        // All retired-queue depths default to zero in the fake store.

        $exitCode = Artisan::call('tsms:shard-topology-verify', ['--to' => 4]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringNotContainsString('UNSAFE', $output);
        $this->assertNoShardTopologyRedisMutations();
    }

    public function test_command_performs_zero_redis_mutation_calls_across_all_scenarios(): void
    {
        config()->set('tsms.processing.shard_count', 8);
        $this->setHorizonProcessingQueues(range(0, 7));
        $this->seedShardTopologyReadyDepth('transaction-processing:s6', 3);

        Artisan::call('tsms:shard-topology-verify');
        Artisan::call('tsms:shard-topology-verify', ['--to' => 4]);
        Artisan::call('tsms:shard-topology-verify', ['--to' => 12]);
        Artisan::call('tsms:shard-topology-verify', ['--to' => 8]);

        // Only read commands (llen/zcard) should ever have been issued.
        foreach ($this->fakeShardTopologyReadCommands() as $call) {
            $this->assertTrue(
                str_starts_with($call, 'llen:') || str_starts_with($call, 'zcard:'),
                "Unexpected non-read Redis call recorded: {$call}"
            );
        }

        $this->assertNoShardTopologyRedisMutations();
    }

    public function test_invalid_to_option_fails_without_touching_redis(): void
    {
        config()->set('tsms.processing.shard_count', 4);
        $this->setHorizonProcessingQueues([0, 1, 2, 3]);

        $exitCode = Artisan::call('tsms:shard-topology-verify', ['--to' => 'not-a-number']);

        $this->assertNotSame(Command::SUCCESS, $exitCode);
        $this->assertNoShardTopologyRedisMutations();
    }
}
