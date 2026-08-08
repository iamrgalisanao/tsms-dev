<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Redis;
use Mockery;

/**
 * Mocks the Redis facade for App\Console\Commands\VerifyShardTopology (T047),
 * whose entire Redis footprint is read-only LLEN/ZCARD depth checks against
 * retired `transaction-processing:s{N}` queues — no EVAL/Lua complexity, so
 * this fake is deliberately simpler than FakesCircuitBreakerRedis/
 * FakesIngestionFairnessRedis, which it otherwise mirrors the style of (a
 * Mockery double registered against Redis::shouldReceive('connection'),
 * backed by a plain in-memory array, with a call log for asserting no
 * mutation ever occurs).
 */
trait FakesShardTopologyRedis
{
    /** @var array<string, int> */
    protected array $fakeShardTopologyListStore = [];

    /** @var array<string, int> */
    protected array $fakeShardTopologyZsetStore = [];

    /** @var list<array{command: string, args: array}> */
    protected array $fakeShardTopologyCallLog = [];

    /**
     * Wire up a working in-memory Redis double for the given connection name
     * (defaults to 'default', matching queue.connections.redis.connection's
     * default). Only read commands (llen/zcard) are stubbed to return real
     * values from the fake store; every mutation-shaped command Redis
     * actually exposes is stubbed to fail loudly so a test can prove the
     * command never attempts one.
     */
    protected function fakeShardTopologyRedis(string $connection = 'default'): void
    {
        $double = Mockery::mock();

        $double->shouldReceive('llen')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $key) {
                $this->fakeShardTopologyCallLog[] = ['command' => 'llen', 'args' => [$key]];

                return $this->fakeShardTopologyListStore[$key] ?? 0;
            });

        $double->shouldReceive('zcard')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $key) {
                $this->fakeShardTopologyCallLog[] = ['command' => 'zcard', 'args' => [$key]];

                return $this->fakeShardTopologyZsetStore[$key] ?? 0;
            });

        // Mutation-shaped commands: never expected to be called by
        // VerifyShardTopology (it is strictly read-only), so any call is
        // recorded (for assertion) AND throws, guaranteeing a test would
        // fail loudly rather than silently succeed if the command ever
        // regresses into calling one of these.
        foreach (['del', 'lpush', 'rpush', 'set', 'expire', 'zadd', 'zrem', 'eval'] as $mutationCommand) {
            $double->shouldReceive($mutationCommand)
                ->zeroOrMoreTimes()
                ->andReturnUsing(function (...$args) use ($mutationCommand) {
                    $this->fakeShardTopologyCallLog[] = ['command' => $mutationCommand, 'args' => $args];

                    throw new \RuntimeException("FakesShardTopologyRedis: unexpected mutation call '{$mutationCommand}' — VerifyShardTopology must be strictly read-only.");
                });
        }

        Redis::shouldReceive('connection')
            ->zeroOrMoreTimes()
            ->with($connection)
            ->andReturn($double);
    }

    /**
     * Seeds a retired/active queue's ready-list depth (LLEN target).
     * $queueName is the bare queue name (e.g. 'transaction-processing:s6'),
     * matching VerifyShardTopology's own key construction of
     * "queues:{$queueName}".
     */
    protected function seedShardTopologyReadyDepth(string $queueName, int $depth): void
    {
        $this->fakeShardTopologyListStore["queues:{$queueName}"] = $depth;
    }

    protected function seedShardTopologyReservedDepth(string $queueName, int $depth): void
    {
        $this->fakeShardTopologyZsetStore["queues:{$queueName}:reserved"] = $depth;
    }

    protected function seedShardTopologyDelayedDepth(string $queueName, int $depth): void
    {
        $this->fakeShardTopologyZsetStore["queues:{$queueName}:delayed"] = $depth;
    }

    /**
     * Asserts no mutation-shaped Redis command was ever invoked through this
     * fake — the zero-mutation guarantee VerifyShardTopology must uphold.
     */
    protected function assertNoShardTopologyRedisMutations(): void
    {
        $mutationCommands = ['del', 'lpush', 'rpush', 'set', 'expire', 'zadd', 'zrem', 'eval'];

        $mutationCalls = array_values(array_filter(
            $this->fakeShardTopologyCallLog,
            static fn (array $call): bool => in_array($call['command'], $mutationCommands, true)
        ));

        $this->assertSame(
            [],
            $mutationCalls,
            'Expected zero Redis mutation calls from VerifyShardTopology, found: '.json_encode($mutationCalls)
        );
    }

    /**
     * @return list<string> the ordered list of read commands (llen/zcard)
     *                      issued through this fake, for tests that want to
     *                      assert exactly which reads happened.
     */
    protected function fakeShardTopologyReadCommands(): array
    {
        return array_map(
            static fn (array $call): string => $call['command'].':'.implode(',', $call['args']),
            $this->fakeShardTopologyCallLog
        );
    }

    protected function resetFakeShardTopologyRedisStore(): void
    {
        $this->fakeShardTopologyListStore = [];
        $this->fakeShardTopologyZsetStore = [];
        $this->fakeShardTopologyCallLog = [];
    }
}
