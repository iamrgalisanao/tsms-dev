<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * T047: read-only, report-only verification of processing shard topology.
 *
 * This command NEVER writes to Redis and NEVER calls any Horizon control
 * command. It performs three kinds of checks:
 *
 *   1. App-vs-Horizon agreement: does `config('tsms.processing.shard_count')`
 *      match the number of `transaction-processing:s{N}` queues actually
 *      wired into the current environment's Horizon supervisor(s)
 *      (`config/horizon.php`)? A mismatch means Horizon hasn't been
 *      restarted since a shard-count config change (or vice versa).
 *   2. (Only with --to) Classifies a proposed shard-count change as
 *      unchanged / increase / decrease, and for decrease, computes the
 *      exact set of shard queues that would be retired.
 *   3. (Decrease only) Inspects each retired queue's real Redis depth
 *      (ready/reserved/delayed) so an operator can confirm it is safe to
 *      shrink the supervisor's queue array without orphaning jobs.
 *
 * Queue-naming convention mirrors App\Services\IngestionQueueRouter::
 * processingQueueForTenant() exactly: `transaction-processing:s{shardIndex}`
 * (see app/Services/IngestionQueueRouter.php:18-23). Processing has no VIP
 * lane (unlike intake), so this is the only queue family this command needs
 * to reason about.
 */
class VerifyShardTopology extends Command
{
    /**
     * Must match App\Services\IngestionQueueRouter::processingQueueForTenant()'s
     * queue-naming convention byte-for-byte.
     */
    private const QUEUE_PREFIX = 'transaction-processing:s';

    protected $signature = 'tsms:shard-topology-verify
        {--to= : Proposed new shard count to compare against the current configured count; if omitted, only verifies current app/Horizon agreement}';

    protected $description = 'Read-only report on processing shard topology: app/Horizon agreement, and (with --to) drain-safety verification for a proposed shard-count change';

    public function handle(): int
    {
        $currentShardCount = max(1, (int) config('tsms.processing.shard_count', 8));
        $env = (string) config('app.env');

        $this->info("Environment: {$env}");
        $this->info("App-configured processing shard count: {$currentShardCount}");

        $unsafe = ! $this->verifyHorizonAgreement($currentShardCount, $env);

        $to = $this->option('to');

        if ($to === null) {
            $this->line('No --to supplied; skipping proposed-change comparison.');

            return $unsafe ? self::FAILURE : self::SUCCESS;
        }

        if (! is_numeric($to) || (int) $to < 1) {
            $this->error("--to must be a positive integer, got: {$to}");

            return self::FAILURE;
        }

        $proposed = (int) $to;

        if ($proposed === $currentShardCount) {
            $this->info("Classification: unchanged (--to={$proposed} equals current shard count).");

            return $unsafe ? self::FAILURE : self::SUCCESS;
        }

        if ($proposed > $currentShardCount) {
            // Increase never orphans/retires a queue by itself; it cannot
            // make an otherwise-safe topology unsafe.
            $this->reportIncrease($currentShardCount, $proposed);
        } else {
            $unsafe = $this->reportDecrease($currentShardCount, $proposed) || $unsafe;
        }

        return $unsafe ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Compares app-configured shard count against the processing shard
     * indices actually present in the current environment's Horizon
     * supervisor config. Returns true when they agree.
     */
    private function verifyHorizonAgreement(int $currentShardCount, string $env): bool
    {
        $horizonIndices = $this->horizonProcessingShardIndices($env);
        $expectedIndices = range(0, $currentShardCount - 1);

        if ($horizonIndices === $expectedIndices) {
            $this->info(sprintf(
                'Horizon topology agrees with app config (%d shard queue(s): %s).',
                count($horizonIndices),
                implode(', ', $this->queueNames($expectedIndices))
            ));

            return true;
        }

        $this->error(sprintf(
            "MISMATCH: app config expects shard indices [%s] (%d shard(s)) but Horizon's '%s' environment config exposes indices [%s] (%d shard(s)). Horizon may need a restart after a shard_count config change (or vice versa).",
            implode(',', $expectedIndices),
            count($expectedIndices),
            $env,
            implode(',', $horizonIndices),
            count($horizonIndices)
        ));

        return false;
    }

    /**
     * Scans every supervisor defined for the current Horizon environment
     * (regardless of supervisor name — production uses 'high-supervisor',
     * staging uses 'processing-supervisor', local folds everything into
     * 'default' per config/horizon.php) for queue names matching the
     * `transaction-processing:s{N}` convention, and returns the sorted,
     * de-duplicated list of shard indices found.
     *
     * @return list<int>
     */
    private function horizonProcessingShardIndices(string $env): array
    {
        $supervisors = config("horizon.environments.{$env}", []);

        if (! is_array($supervisors)) {
            return [];
        }

        $indices = [];
        $pattern = '/^'.preg_quote(self::QUEUE_PREFIX, '/').'(\d+)$/';

        foreach ($supervisors as $supervisor) {
            $queues = is_array($supervisor) ? ($supervisor['queue'] ?? []) : [];

            if (! is_array($queues)) {
                continue;
            }

            foreach ($queues as $queueName) {
                if (is_string($queueName) && preg_match($pattern, $queueName, $matches) === 1) {
                    $indices[(int) $matches[1]] = true;
                }
            }
        }

        $indices = array_keys($indices);
        sort($indices);

        return $indices;
    }

    /**
     * Reports an increase: no queues are retired, every old shard index
     * continues to exist and stay consumed. Still calls out that
     * crc32(tenantId) % shardCount changes the modulo result for some
     * tenants when the divisor changes, so this is a rebalance, not a
     * purely additive change.
     */
    private function reportIncrease(int $old, int $new): void
    {
        $this->info("Classification: increase (current={$old}, proposed={$new}).");
        $this->line(sprintf(
            'No shard queues are retired on increase — existing shard indices 0..%d continue to exist and remain consumed: %s',
            $old - 1,
            implode(', ', $this->queueNames(range(0, $old - 1)))
        ));
        $this->line(sprintf(
            'New shard indices %d..%d are newly introduced queues that must be added to Horizon\'s supervisor queue array: %s',
            $old,
            $new - 1,
            implode(', ', $this->queueNames(range($old, $new - 1)))
        ));
        $this->warn(sprintf(
            'Rebalance note: crc32(tenantId) %% shardCount changes the modulo result for some tenants when the divisor changes from %d to %d. Tenants currently routed to shards 0..%d may be remapped to a DIFFERENT existing shard under the new count. Every old shard index remains live and consumed — this is a rebalance blip, not orphaning — but it is a real behavior change, not a purely additive one. Exact tenant-level remap cannot be computed from config alone.',
            $old,
            $new,
            $old - 1
        ));
    }

    /**
     * Reports a decrease: computes and lists the exact set of retired shard
     * queues, then inspects each one's real Redis ready/reserved/delayed
     * depth. Returns true if any retired queue is unsafe (nonzero depth).
     */
    private function reportDecrease(int $old, int $new): bool
    {
        $retiredIndices = range($new, $old - 1);
        $retiredQueues = $this->queueNames($retiredIndices);
        $activeQueues = $this->queueNames(range(0, $new - 1));

        $this->info("Classification: decrease (current={$old}, proposed={$new}).");
        $this->line('Active queues after cutover: '.implode(', ', $activeQueues));
        $this->warn('Retired queues (no consumer once Horizon is updated to the smaller count): '.implode(', ', $retiredQueues));

        $connection = (string) config('queue.connections.redis.connection', 'default');
        $prefix = (string) config('database.redis.options.prefix', '');

        $rows = [];
        $unsafe = false;

        foreach ($retiredQueues as $queueName) {
            $depths = $this->retiredQueueDepths($queueName, $connection, $prefix);

            $rows[] = [
                $depths['queue'],
                $depths['redis_key'],
                $depths['ready'],
                $depths['reserved'],
                $depths['delayed'],
                $depths['safe'] ? 'safe' : 'UNSAFE',
            ];

            if (! $depths['safe']) {
                $unsafe = true;

                $nonzero = array_filter(
                    ['ready' => $depths['ready'], 'reserved' => $depths['reserved'], 'delayed' => $depths['delayed']],
                    static fn (int $v): bool => $v > 0
                );

                $this->error(sprintf(
                    "UNSAFE: retired queue '%s' has nonzero depth(s): %s.",
                    $queueName,
                    implode(', ', array_map(
                        static fn (string $k, int $v): string => "{$k}={$v}",
                        array_keys($nonzero),
                        $nonzero
                    ))
                ));
            }
        }

        $this->table(['Queue', 'Redis Key (ready)', 'Ready', 'Reserved', 'Delayed', 'Status'], $rows);

        if (! $unsafe) {
            $this->info('All retired queues are drained (ready/reserved/delayed all zero). Safe to proceed with the shard-count reduction.');
        }

        return $unsafe;
    }

    /**
     * Reads real ready/reserved/delayed depth for a single retired queue,
     * using Laravel's actual Redis key-naming convention for RedisQueue
     * (`queues:{name}`, `queues:{name}:reserved`, `queues:{name}:delayed` —
     * see vendor/laravel/framework/src/Illuminate/Queue/RedisQueue.php).
     *
     * The Redis calls themselves are issued through Laravel's Redis facade
     * against the app's configured connection, which applies the
     * configured key prefix automatically (see config/database.php's
     * `redis.options.prefix`, applied via PhpRedisConnector/PredisConnector
     * to every named connection). $prefix is used only to build the
     * human-readable `redis_key` reported to the operator (e.g. for
     * cross-checking with `redis-cli`), not to double-prefix the actual
     * call.
     *
     * @return array{queue: string, redis_key: string, ready: int, reserved: int, delayed: int, safe: bool}
     */
    private function retiredQueueDepths(string $queueName, string $connection, string $prefix): array
    {
        $redis = Redis::connection($connection);

        $ready = (int) $redis->llen("queues:{$queueName}");
        $reserved = (int) $redis->zcard("queues:{$queueName}:reserved");
        $delayed = (int) $redis->zcard("queues:{$queueName}:delayed");

        return [
            'queue' => $queueName,
            'redis_key' => $prefix."queues:{$queueName}",
            'ready' => $ready,
            'reserved' => $reserved,
            'delayed' => $delayed,
            'safe' => $ready === 0 && $reserved === 0 && $delayed === 0,
        ];
    }

    /**
     * @param  list<int>  $indices
     * @return list<string>
     */
    private function queueNames(array $indices): array
    {
        return array_map(static fn (int $i): string => self::QUEUE_PREFIX.$i, $indices);
    }
}
