<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * T039: Horizon config characterization test.
 *
 * This test reads `config('horizon.environments.staging')` as a plain PHP
 * array (structural checks against real config, not fragile raw-text/regex
 * matching against config/horizon.php's file contents) and characterizes the
 * TARGET topology that T046 must produce: four concerns — transaction
 * processing, low-priority work, notifications, reporting — each owned by
 * its own dedicated, pairwise-distinct supervisor key within the staging
 * environment.
 *
 * Today (pre-T046) staging's `default` supervisor hosts processing + low +
 * notifications together (see config/horizon.php's `staging` environment
 * key), so this test is EXPECTED TO FAIL until T046 performs the split. That
 * is the point of a characterization test: it gives T046 a concrete,
 * automatable definition of "done" instead of relying on manual inspection
 * of config/horizon.php.
 *
 * Do NOT modify config/horizon.php to make this test pass — that is T046's
 * job. This test must only ever start passing as a side effect of T046's
 * real supervisor split landing.
 */
class HorizonSupervisorSeparationTest extends TestCase
{
    /**
     * Real processing shard queue names, derived the same way
     * IngestionQueueRouter::processingQueueForTenant() names them
     * ("transaction-processing:s{N}"), using the real configured shard
     * count so this test tracks config/tsms.php's shard_count instead of
     * hardcoding a guessed shard count.
     *
     * @return array<int, string>
     */
    private function processingQueueNames(): array
    {
        $shardCount = max(1, (int) config('tsms.processing.shard_count', 8));

        return array_map(
            fn (int $i) => "transaction-processing:s{$i}",
            range(0, $shardCount - 1)
        );
    }

    /**
     * Concern => set of queue names that concern owns, using the real,
     * currently-established naming conventions in this codebase (not
     * invented placeholder names):
     * - processing: transaction-processing:s0..s{N-1} (IngestionQueueRouter)
     * - low: 'low' (see config/horizon.php production low-supervisor)
     * - notifications: 'notifications' (production notifications-supervisor)
     * - reporting: 'reporting' (production reporting-supervisor; already
     *   split in staging today too)
     *
     * @return array<string, array<int, string>>
     */
    private function concernQueueMap(): array
    {
        return [
            'processing' => $this->processingQueueNames(),
            'low' => ['low'],
            'notifications' => ['notifications'],
            'reporting' => ['reporting'],
        ];
    }

    /**
     * T046a: `webhook-callbacks` is a fifth, later-added concern (isolating
     * blocking outbound webhook I/O — DispatchWebhookRetryJob and
     * TransactionResultNotification's webhook channel — from the shared
     * low/notifications worker pools). Kept as a separate map from
     * concernQueueMap() above so the original T046/T039 four-concern
     * assertions are untouched and this addition is purely additive.
     *
     * @return array<string, array<int, string>>
     */
    private function webhookConcernQueueMap(): array
    {
        return [
            'webhook-callbacks' => ['webhook-callbacks'],
        ];
    }

    /**
     * Resolve the single supervisor key within staging whose `queue` array
     * contains every queue name in the given concern's set.
     *
     * Returns null if no supervisor contains the full set, or if more than
     * one supervisor contains the full set (both are failure conditions the
     * caller must report distinctly, so this returns a discriminating
     * result rather than silently collapsing both cases to "not found").
     *
     * @param  array<string, array<string, mixed>>  $staging
     * @param  array<int, string>  $concernQueues
     * @return array{key: string|null, matches: array<int, string>}
     */
    private function resolveSupervisorForConcern(array $staging, array $concernQueues): array
    {
        $matches = [];

        foreach ($staging as $supervisorKey => $supervisorConfig) {
            $supervisorQueues = $supervisorConfig['queue'] ?? [];

            $containsAll = count(array_intersect($concernQueues, $supervisorQueues)) === count($concernQueues);

            if ($containsAll) {
                $matches[] = $supervisorKey;
            }
        }

        return [
            'key' => count($matches) === 1 ? $matches[0] : null,
            'matches' => $matches,
        ];
    }

    public function test_transaction_processing_has_a_dedicated_staging_supervisor(): void
    {
        $staging = config('horizon.environments.staging');
        $this->assertIsArray($staging, 'Expected config(horizon.environments.staging) to be an array.');

        $result = $this->resolveSupervisorForConcern($staging, $this->concernQueueMap()['processing']);

        $this->assertNotNull(
            $result['key'],
            'Expected exactly one staging supervisor to own all transaction-processing shard queues '
                .'('.implode(', ', $this->concernQueueMap()['processing']).'). '
                .'Supervisors containing at least one matching queue: '.implode(', ', $result['matches'] ?: ['<none>'])
        );
    }

    public function test_low_priority_work_has_a_dedicated_staging_supervisor(): void
    {
        $staging = config('horizon.environments.staging');
        $this->assertIsArray($staging, 'Expected config(horizon.environments.staging) to be an array.');

        $result = $this->resolveSupervisorForConcern($staging, $this->concernQueueMap()['low']);

        $this->assertNotNull(
            $result['key'],
            "Expected exactly one staging supervisor to own the 'low' queue. "
                .'Supervisors containing it: '.implode(', ', $result['matches'] ?: ['<none>'])
        );
    }

    public function test_notifications_has_a_dedicated_staging_supervisor(): void
    {
        $staging = config('horizon.environments.staging');
        $this->assertIsArray($staging, 'Expected config(horizon.environments.staging) to be an array.');

        $result = $this->resolveSupervisorForConcern($staging, $this->concernQueueMap()['notifications']);

        $this->assertNotNull(
            $result['key'],
            "Expected exactly one staging supervisor to own the 'notifications' queue. "
                .'Supervisors containing it: '.implode(', ', $result['matches'] ?: ['<none>'])
        );
    }

    public function test_reporting_has_a_dedicated_staging_supervisor(): void
    {
        $staging = config('horizon.environments.staging');
        $this->assertIsArray($staging, 'Expected config(horizon.environments.staging) to be an array.');

        $result = $this->resolveSupervisorForConcern($staging, $this->concernQueueMap()['reporting']);

        $this->assertNotNull(
            $result['key'],
            "Expected exactly one staging supervisor to own the 'reporting' queue. "
                .'Supervisors containing it: '.implode(', ', $result['matches'] ?: ['<none>'])
        );
    }

    public function test_the_four_concern_supervisor_keys_are_pairwise_distinct(): void
    {
        $staging = config('horizon.environments.staging');
        $this->assertIsArray($staging, 'Expected config(horizon.environments.staging) to be an array.');

        $resolved = [];
        foreach ($this->concernQueueMap() as $concern => $queues) {
            $resolved[$concern] = $this->resolveSupervisorForConcern($staging, $queues)['key'];
        }

        // Only compare concerns that actually resolved to a supervisor;
        // an unresolved concern is already reported as a distinct failure
        // by its own dedicated test above and must not be conflated with
        // this pairwise-distinctness assertion.
        $resolvedKeys = array_filter($resolved, fn ($key) => $key !== null);

        $this->assertSame(
            count($this->concernQueueMap()),
            count(array_unique($resolvedKeys)),
            'Expected the four concerns (processing, low, notifications, reporting) to resolve to four '
                .'pairwise-distinct staging supervisor keys, proving they no longer share one worker pool. '
                .'Resolved mapping: '.json_encode($resolved)
        );
    }

    public function test_staging_default_supervisor_does_not_span_more_than_one_concern(): void
    {
        $staging = config('horizon.environments.staging');
        $this->assertIsArray($staging, 'Expected config(horizon.environments.staging) to be an array.');

        if (! array_key_exists('default', $staging)) {
            // No generic/shared 'default' supervisor exists at all — the
            // concern this assertion guards against (a catch-all supervisor
            // silently absorbing more than one concern) cannot occur.
            $this->assertTrue(true);

            return;
        }

        $defaultQueues = $staging['default']['queue'] ?? [];

        $concernsPresentInDefault = [];
        foreach ($this->concernQueueMap() as $concern => $queues) {
            $overlap = array_intersect($queues, $defaultQueues);
            if (! empty($overlap)) {
                $concernsPresentInDefault[$concern] = $overlap;
            }
        }

        $this->assertLessThanOrEqual(
            1,
            count($concernsPresentInDefault),
            "Expected staging's generic 'default' supervisor to contain queues from at most one of the four "
                .'distinct concerns (processing, low, notifications, reporting), not have them commingled. '
                .'Concerns found in default: '.json_encode($concernsPresentInDefault)
        );
    }

    /**
     * T046a: `webhook-callbacks` is a fifth concern (blocking outbound
     * webhook I/O — DispatchWebhookRetryJob and TransactionResultNotification's
     * webhook channel, up to ~93s per call with retries) isolated onto a
     * dedicated `webhook-supervisor`, added alongside — not in place of —
     * T046's four-concern staging split. This test characterizes the target
     * topology the same way T039 characterized T046's split: reading plain
     * config arrays, no live Horizon/Redis process, deterministic.
     */
    public function test_webhook_callbacks_has_a_dedicated_staging_supervisor_with_one_process(): void
    {
        $staging = config('horizon.environments.staging');
        $this->assertIsArray($staging, 'Expected config(horizon.environments.staging) to be an array.');

        $result = $this->resolveSupervisorForConcern($staging, $this->webhookConcernQueueMap()['webhook-callbacks']);

        $this->assertNotNull(
            $result['key'],
            "Expected exactly one staging supervisor to own the 'webhook-callbacks' queue. "
                .'Supervisors containing it: '.implode(', ', $result['matches'] ?: ['<none>'])
        );

        $this->assertSame(
            1,
            $staging[$result['key']]['processes'] ?? null,
            "Expected staging's webhook-callbacks supervisor ('{$result['key']}') to run exactly 1 process."
        );
    }

    public function test_webhook_callbacks_has_a_dedicated_production_supervisor_with_two_processes(): void
    {
        $production = config('horizon.environments.production');
        $this->assertIsArray($production, 'Expected config(horizon.environments.production) to be an array.');

        $result = $this->resolveSupervisorForConcern($production, $this->webhookConcernQueueMap()['webhook-callbacks']);

        $this->assertNotNull(
            $result['key'],
            "Expected exactly one production supervisor to own the 'webhook-callbacks' queue. "
                .'Supervisors containing it: '.implode(', ', $result['matches'] ?: ['<none>'])
        );

        $this->assertSame(
            2,
            $production[$result['key']]['processes'] ?? null,
            "Expected production's webhook-callbacks supervisor ('{$result['key']}') to run exactly 2 processes."
        );
    }

    /**
     * Regression guard: adding the new webhook-callbacks/webhook-supervisor
     * pair must not disturb T046's staging split. Re-resolves the original
     * four concerns AND confirms each still resolves to the exact same
     * supervisor key it did before this addition.
     */
    public function test_staging_original_four_concerns_resolve_to_unchanged_supervisor_keys_after_webhook_addition(): void
    {
        $staging = config('horizon.environments.staging');
        $this->assertIsArray($staging, 'Expected config(horizon.environments.staging) to be an array.');

        $resolved = [];
        foreach ($this->concernQueueMap() as $concern => $queues) {
            $resolved[$concern] = $this->resolveSupervisorForConcern($staging, $queues)['key'];
        }

        $this->assertSame(
            [
                'processing' => 'processing-supervisor',
                'low' => 'low-supervisor',
                'notifications' => 'notifications-supervisor',
                'reporting' => 'reporting-supervisor',
            ],
            $resolved,
            'T046a must be purely additive — the original T046 staging supervisor keys must resolve unchanged '
                .'after adding webhook-supervisor. Resolved mapping: '.json_encode($resolved)
        );
    }

    /**
     * Same regression guard for production's pre-existing per-concern
     * supervisors (high-supervisor/low-supervisor/notifications-supervisor/
     * reporting-supervisor), which predate T046 but must equally be
     * undisturbed by this purely-additive change.
     */
    public function test_production_original_four_concerns_resolve_to_unchanged_supervisor_keys_after_webhook_addition(): void
    {
        $production = config('horizon.environments.production');
        $this->assertIsArray($production, 'Expected config(horizon.environments.production) to be an array.');

        $resolved = [];
        foreach ($this->concernQueueMap() as $concern => $queues) {
            $resolved[$concern] = $this->resolveSupervisorForConcern($production, $queues)['key'];
        }

        $this->assertSame(
            [
                'processing' => 'high-supervisor',
                'low' => 'low-supervisor',
                'notifications' => 'notifications-supervisor',
                'reporting' => 'reporting-supervisor',
            ],
            $resolved,
            'T046a must be purely additive — production\'s pre-existing per-concern supervisor keys must resolve '
                .'unchanged after adding webhook-supervisor. Resolved mapping: '.json_encode($resolved)
        );
    }

    /**
     * Confirms `webhook-callbacks` doesn't collide with any of the four
     * original concerns' supervisor keys, in either environment — i.e. no
     * queue is owned by more than one supervisor as a side effect of this
     * addition.
     */
    public function test_webhook_callbacks_supervisor_is_pairwise_distinct_from_the_four_original_concerns(): void
    {
        foreach (['staging', 'production'] as $environment) {
            $config = config("horizon.environments.{$environment}");
            $this->assertIsArray($config, "Expected config(horizon.environments.{$environment}) to be an array.");

            $resolved = [];
            foreach ($this->concernQueueMap() as $concern => $queues) {
                $resolved[$concern] = $this->resolveSupervisorForConcern($config, $queues)['key'];
            }
            $resolved['webhook-callbacks'] = $this->resolveSupervisorForConcern(
                $config,
                $this->webhookConcernQueueMap()['webhook-callbacks']
            )['key'];

            $resolvedKeys = array_filter($resolved, fn ($key) => $key !== null);

            $this->assertSame(
                5,
                count(array_unique($resolvedKeys)),
                'Expected all five concerns (processing, low, notifications, reporting, webhook-callbacks) to '
                    ."resolve to five pairwise-distinct {$environment} supervisor keys. "
                    .'Resolved mapping: '.json_encode($resolved)
            );
        }
    }
}
