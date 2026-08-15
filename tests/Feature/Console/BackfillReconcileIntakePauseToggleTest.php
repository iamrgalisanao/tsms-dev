<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Closes the containment-plan.md-documented gap: `tsms:reconcile-intake`
 * previously had no config toggle at all (unlike `transactions-prune`'s
 * `enable_pruning`/`TX_ENABLE_PRUNING`). Covers both scheduled entries
 * (`tsms-reconcile-intake`, every minute; `tsms-repair-missing-transactions`,
 * daily) via `Event::filtersPass()` directly, rather than waiting for the
 * actual cron time to elapse.
 *
 * Named with "Backfill" in the class for this feature's `--filter=Backfill`
 * convention, even though this toggle isn't backfill-pipeline code itself —
 * it was scoped and delivered as part of this feature's readiness work.
 */
class BackfillReconcileIntakePauseToggleTest extends TestCase
{
    private function event(string $name): \Illuminate\Console\Scheduling\Event
    {
        $events = app(Schedule::class)->events();

        foreach ($events as $event) {
            if ($event->description === $name) {
                return $event;
            }
        }

        $this->fail("No scheduled event named '{$name}' was found.");
    }

    public function test_both_scheduled_entries_run_by_default(): void
    {
        config(['tsms.transactions.enable_intake_reconcile' => true]);

        $this->assertTrue($this->event('tsms-reconcile-intake')->filtersPass($this->app));
        $this->assertTrue($this->event('tsms-repair-missing-transactions')->filtersPass($this->app));
    }

    public function test_both_scheduled_entries_are_skipped_when_disabled(): void
    {
        config(['tsms.transactions.enable_intake_reconcile' => false]);

        $this->assertFalse($this->event('tsms-reconcile-intake')->filtersPass($this->app));
        $this->assertFalse($this->event('tsms-repair-missing-transactions')->filtersPass($this->app));
    }

    public function test_missing_config_key_defaults_to_enabled(): void
    {
        // Simulates an environment that hasn't set TX_ENABLE_INTAKE_RECONCILE
        // at all -- the toggle must default to "on", never silently pause a
        // command nobody asked to pause.
        $transactions = config('tsms.transactions');
        unset($transactions['enable_intake_reconcile']);
        config(['tsms.transactions' => $transactions]);

        $this->assertTrue($this->event('tsms-reconcile-intake')->filtersPass($this->app));
        $this->assertTrue($this->event('tsms-repair-missing-transactions')->filtersPass($this->app));
    }
}
