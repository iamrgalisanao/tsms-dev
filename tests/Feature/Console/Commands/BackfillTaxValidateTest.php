<?php

namespace Tests\Feature\Console\Commands;

use App\Models\TaxBackfillRecord;
use App\Models\Transaction;
use App\Models\TransactionTax;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 002-backfill-transaction-taxes, Slice 21 (T058-T061). See
 * specs/002-backfill-transaction-taxes/slice-21-post-run-validation-brief.md.
 *
 * Covers `transactions:tax-backfill-validate`: input validation, each of the
 * four checks' pass/warn/fail conditions per the brief's confirmed
 * thresholds (T058/T059 exact-match with the expected_zero exception, T060
 * +/-5pp/30-row-floor skew as WARN-only, T061 reusing FR-009a's PHP 500/1%
 * threshold), --tenant narrowing, option overrides, and zero writes.
 *
 * Named with "Backfill" in the class for this feature's `--filter=Backfill`
 * convention. Every test uses its own distinct (year, month) window for
 * --from/--to (and matching created_at values), all comfortably below 2038
 * (transactions.created_at is a MySQL TIMESTAMP column, capped at
 * 2038-01-19 — a far-future year like 2042 silently truncates to
 * 0000-00-00 rather than erroring), to stay correct under the known
 * tests/TestCase.php:38 RefreshDatabase leak between test methods —
 * matching BackfillTaxReadinessVerdictTest's own established convention.
 */
class BackfillTaxValidateTest extends TestCase
{
    private const COMMAND = 'transactions:tax-backfill-validate';

    private function decodeJson(string $output): array
    {
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "Command output was not valid JSON: {$output}");

        return $decoded;
    }

    private function makeTransaction(Carbon $createdAt, ?int $tenantId = null, ?float $vatAmount = null): Transaction
    {
        return Transaction::factory()->create(array_filter([
            'created_at' => $createdAt,
            'tenant_id' => $tenantId,
            'vat_amount' => $vatAmount,
        ], fn ($v) => $v !== null));
    }

    private function addTaxRow(Transaction $transaction, string $taxType, float $amount): TransactionTax
    {
        return TransactionTax::create([
            'transaction_pk' => $transaction->id,
            'tax_type' => $taxType,
            'amount' => $amount,
        ]);
    }

    private function quarantine(Transaction $transaction): TaxBackfillRecord
    {
        return TaxBackfillRecord::factory()->quarantined()->create([
            'transaction_pk' => $transaction->id,
            'tenant_id' => $transaction->tenant_id,
        ]);
    }

    // --- Validation ---------------------------------------------------

    public function test_missing_from_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--to' => '2027-02-01']);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('--from is required', Artisan::output());
    }

    public function test_missing_to_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--from' => '2027-01-01']);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('--to is required', Artisan::output());
    }

    public function test_invalid_date_format_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--from' => '2027/01/01', '--to' => '2027-02-01']);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('expected format Y-m-d', Artisan::output());
    }

    public function test_empty_window_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--from' => '2027-02-01', '--to' => '2027-01-01']);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('empty window', Artisan::output());
    }

    public function test_invalid_tenant_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, ['--from' => '2027-01-01', '--to' => '2027-02-01', '--tenant' => 'abc']);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('must be a positive integer', Artisan::output());
    }

    public function test_invalid_skew_band_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, [
            '--from' => '2027-01-01', '--to' => '2027-02-01', '--tax-type-skew-band' => '-1',
        ]);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('must be a positive number', Artisan::output());
    }

    public function test_invalid_skew_floor_is_rejected(): void
    {
        $exit = Artisan::call(self::COMMAND, [
            '--from' => '2027-01-01', '--to' => '2027-02-01', '--tax-type-skew-floor' => '0',
        ]);

        $this->assertNotEquals(0, $exit);
        $this->assertStringContainsString('must be a positive integer', Artisan::output());
    }

    // --- T058: coverage by date ----------------------------------------

    public function test_coverage_by_date_passes_with_full_exact_coverage(): void
    {
        $day = Carbon::parse('2027-03-05');
        $covered = $this->makeTransaction($day->copy()->addHours(2));
        $this->addTaxRow($covered, 'VAT', 100);
        $quarantined = $this->makeTransaction($day->copy()->addHours(3));
        $this->quarantine($quarantined);

        $result = $this->decodeJson($this->runJson('2027-03-01', '2027-04-01'));

        $this->assertSame('pass', $result['checks']['coverage_by_date']['status']);
        $this->assertEmpty($result['checks']['coverage_by_date']['failing_days']);
    }

    public function test_coverage_by_date_fails_when_non_quarantined_transaction_missing_rows(): void
    {
        $day = Carbon::parse('2027-04-05');
        $this->makeTransaction($day->copy()->addHours(2));
        // No tax row added, and never quarantined -- a genuine gap.

        $result = $this->decodeJson($this->runJson('2027-04-01', '2027-05-01'));

        $this->assertSame('fail', $result['checks']['coverage_by_date']['status']);
        $failingDays = $result['checks']['coverage_by_date']['failing_days'];
        $this->assertCount(1, $failingDays);
        $this->assertSame('2027-04-05', $failingDays[0]['day']);
        $this->assertSame(1, $failingDays[0]['total']);
        $this->assertSame(0, $failingDays[0]['with_tax']);
        $this->assertSame(0, $failingDays[0]['quarantined']);
    }

    public function test_coverage_by_date_fails_when_quarantined_transaction_unexpectedly_has_rows(): void
    {
        $day = Carbon::parse('2027-05-05');
        $transaction = $this->makeTransaction($day->copy()->addHours(2));
        $this->quarantine($transaction);
        $this->addTaxRow($transaction, 'VAT', 50);

        $result = $this->decodeJson($this->runJson('2027-05-01', '2027-06-01'));

        $this->assertSame('fail', $result['checks']['coverage_by_date']['status']);
        $failingDays = $result['checks']['coverage_by_date']['failing_days'];
        $this->assertSame(1, $failingDays[0]['with_tax']);
        $this->assertSame(0, $failingDays[0]['expected_with_tax']);
    }

    public function test_to_boundary_is_exclusive(): void
    {
        // A transaction dated exactly at --to's own midnight must be
        // excluded (resolveWindow() must resolve --to to one second before
        // midnight before querying, since whereBetween is inclusive on both
        // ends). Only a boundary transaction is seeded here -- if it were
        // incorrectly included, days_checked would be 1, not 0.
        $boundary = $this->makeTransaction(Carbon::parse('2029-02-01 00:00:00'));
        $this->addTaxRow($boundary, 'VAT', 10);

        $result = $this->decodeJson($this->runJson('2029-01-01', '2029-02-01'));

        $this->assertSame(0, $result['checks']['coverage_by_date']['days_checked']);
    }

    // --- T059: coverage by tenant ---------------------------------------

    public function test_coverage_by_tenant_passes_when_tenant_has_backfilled_rows(): void
    {
        $day = Carbon::parse('2027-06-05');
        $transaction = $this->makeTransaction($day);
        $this->addTaxRow($transaction, 'VAT', 20);

        $result = $this->decodeJson($this->runJson('2027-06-01', '2027-07-01'));

        $this->assertSame('pass', $result['checks']['coverage_by_tenant']['status']);
        $this->assertEmpty($result['checks']['coverage_by_tenant']['unexpected_zero']);
    }

    public function test_coverage_by_tenant_fails_on_unexpected_zero(): void
    {
        $day = Carbon::parse('2027-07-05');
        $transaction = $this->makeTransaction($day);
        // No tax row, not quarantined -- unexpected zero for this tenant.

        $result = $this->decodeJson($this->runJson('2027-07-01', '2027-08-01'));

        $this->assertSame('fail', $result['checks']['coverage_by_tenant']['status']);
        $unexpectedZero = $result['checks']['coverage_by_tenant']['unexpected_zero'];
        $this->assertCount(1, $unexpectedZero);
        $this->assertSame($transaction->tenant_id, $unexpectedZero[0]['tenant_id']);
    }

    public function test_coverage_by_tenant_treats_fully_quarantined_tenant_as_expected_zero_not_failure(): void
    {
        $day = Carbon::parse('2027-08-05');
        $transaction = $this->makeTransaction($day);
        $this->quarantine($transaction);
        // Entire tenant population is quarantined -- zero backfilled rows is
        // expected, must not be flagged as a failure.

        $result = $this->decodeJson($this->runJson('2027-08-01', '2027-09-01'));

        $this->assertSame('pass', $result['checks']['coverage_by_tenant']['status']);
        $this->assertEmpty($result['checks']['coverage_by_tenant']['unexpected_zero']);
        $expectedZero = $result['checks']['coverage_by_tenant']['expected_zero'];
        $this->assertCount(1, $expectedZero);
        $this->assertSame($transaction->tenant_id, $expectedZero[0]['tenant_id']);
    }

    // --- T060: tax-type distribution skew --------------------------------
    //
    // The reference period (TaxBackfillValidate::REFERENCE_FROM onward,
    // 2026-08-11+) is fixed per the approved brief -- not operator-
    // configurable -- so every T060 test shares the same physical reference
    // window. Isolation instead comes from --tenant= scoping: each test
    // creates its own fresh tenant (via makeTransaction's default factory
    // behavior) and narrows both the window and reference-period queries to
    // it, so other tests' reference-period fixtures (or leaked rows, per
    // the known tests/TestCase.php:38 RefreshDatabase issue) never mix in.

    public function test_tax_type_distribution_passes_within_band(): void
    {
        $windowDay = Carbon::parse('2027-09-05');
        $referenceMoment = Carbon::parse('2026-08-11 12:00:00');

        $anchor = $this->makeTransaction($windowDay);
        $tenantId = $anchor->tenant_id;
        $this->addTaxRow($anchor, 'VAT', 10);
        for ($i = 0; $i < 4; $i++) {
            $this->addTaxRow($this->makeTransaction($windowDay, $tenantId), 'VAT', 10);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->addTaxRow($this->makeTransaction($referenceMoment, $tenantId), 'VAT', 10);
        }

        $result = $this->decodeJson($this->runJson('2027-09-01', '2027-10-01', extra: [
            '--tenant' => (string) $tenantId,
            '--tax-type-skew-floor' => '1',
        ]));

        $this->assertSame('pass', $result['checks']['tax_type_distribution']['status']);
        $this->assertEmpty($result['checks']['tax_type_distribution']['flagged_types']);
    }

    public function test_tax_type_distribution_warns_on_skew_above_floor(): void
    {
        $windowDay = Carbon::parse('2027-10-05');
        $referenceMoment = Carbon::parse('2026-08-11 13:00:00');

        // Both tax types are present in BOTH periods with >= floor rows
        // (the brief's floor requires this on both sides, by design -- a
        // type absent from one period entirely never qualifies, since its
        // count there is always 0 < floor). Window: 90% VAT / 10%
        // VATABLE_SALES. Reference: 50% / 50%. A 40pp swing on each type,
        // far past the 5pp band.
        $anchor = $this->makeTransaction($windowDay);
        $tenantId = $anchor->tenant_id;
        $this->addTaxRow($anchor, 'VAT', 10);
        for ($i = 0; $i < 8; $i++) {
            $this->addTaxRow($this->makeTransaction($windowDay, $tenantId), 'VAT', 10);
        }
        $this->addTaxRow($this->makeTransaction($windowDay, $tenantId), 'VATABLE_SALES', 10);
        for ($i = 0; $i < 5; $i++) {
            $this->addTaxRow($this->makeTransaction($referenceMoment, $tenantId), 'VAT', 10);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->addTaxRow($this->makeTransaction($referenceMoment, $tenantId), 'VATABLE_SALES', 10);
        }

        $result = $this->decodeJson($this->runJson('2027-10-01', '2027-11-01', extra: [
            '--tenant' => (string) $tenantId,
            '--tax-type-skew-floor' => '1',
        ]));

        $this->assertSame('warn', $result['checks']['tax_type_distribution']['status']);
        $this->assertNotEmpty($result['checks']['tax_type_distribution']['flagged_types']);
        // A WARN on this check alone must not fail the overall verdict.
        $this->assertNotSame('fail', $result['overall_status']);
    }

    public function test_tax_type_distribution_ignores_skew_below_row_floor(): void
    {
        $windowDay = Carbon::parse('2027-11-05');
        $referenceMoment = Carbon::parse('2026-08-11 14:00:00');

        // Same 0%-to-100% swing as the warn test, but only 2 rows each --
        // under the default 30-row floor (left at default here).
        $anchor = $this->makeTransaction($windowDay);
        $tenantId = $anchor->tenant_id;
        $this->addTaxRow($anchor, 'VAT', 10);
        $this->addTaxRow($this->makeTransaction($windowDay, $tenantId), 'VAT', 10);
        $this->addTaxRow($this->makeTransaction($referenceMoment, $tenantId), 'VATABLE_SALES', 10);
        $this->addTaxRow($this->makeTransaction($referenceMoment, $tenantId), 'VATABLE_SALES', 10);

        $result = $this->decodeJson($this->runJson('2027-11-01', '2027-12-01', extra: [
            '--tenant' => (string) $tenantId,
        ]));

        $this->assertSame('pass', $result['checks']['tax_type_distribution']['status']);
        $this->assertEmpty($result['checks']['tax_type_distribution']['flagged_types']);
    }

    // --- T061: VAT reconciliation ----------------------------------------

    public function test_vat_reconciliation_passes_within_500(): void
    {
        $day = Carbon::parse('2027-12-05');
        $transaction = $this->makeTransaction($day, vatAmount: 10000.00);
        $this->addTaxRow($transaction, 'VAT', 10400.00);

        $result = $this->decodeJson($this->runJson('2027-12-01', '2028-01-01'));

        $this->assertSame('pass', $result['checks']['vat_reconciliation']['status']);
    }

    public function test_vat_reconciliation_passes_within_one_percent_over_500(): void
    {
        $day = Carbon::parse('2028-01-05');
        // vat_amount 100000, tax_sum 100800 -> diff 800 (over PHP 500 flat,
        // but under 1% of 100000 = 1000) -- proves the "whichever is looser"
        // logic, not a flat PHP 500 cutoff.
        $transaction = $this->makeTransaction($day, vatAmount: 100000.00);
        $this->addTaxRow($transaction, 'VAT', 100800.00);

        $result = $this->decodeJson($this->runJson('2028-01-01', '2028-02-01'));

        $this->assertSame('pass', $result['checks']['vat_reconciliation']['status']);
    }

    public function test_vat_reconciliation_fails_when_exceeding_both_thresholds(): void
    {
        $day = Carbon::parse('2028-02-05');
        $transaction = $this->makeTransaction($day, vatAmount: 10000.00);
        $this->addTaxRow($transaction, 'VAT', 12000.00);

        $result = $this->decodeJson($this->runJson('2028-02-01', '2028-03-01'));

        $this->assertSame('fail', $result['checks']['vat_reconciliation']['status']);
        $this->assertNotEmpty($result['checks']['vat_reconciliation']['failing_pairs']);
        $this->assertSame('fail', $result['overall_status']);
    }

    public function test_vat_reconciliation_recognizes_vat_amount_alias(): void
    {
        $day = Carbon::parse('2028-03-05');
        $transaction = $this->makeTransaction($day, vatAmount: 5000.00);
        $this->addTaxRow($transaction, 'vat_amount', 5000.00);

        $result = $this->decodeJson($this->runJson('2028-03-01', '2028-04-01'));

        $this->assertSame('pass', $result['checks']['vat_reconciliation']['status']);
    }

    // --- --tenant narrowing ------------------------------------------------

    public function test_tenant_option_narrows_all_checks(): void
    {
        $day = Carbon::parse('2028-04-05');
        $ok = $this->makeTransaction($day);
        $this->addTaxRow($ok, 'VAT', 10);
        $this->makeTransaction($day);
        // The second tenant's transaction has no tax row and is not
        // quarantined -- would fail coverage checks if included.

        $result = $this->decodeJson($this->runJson('2028-04-01', '2028-05-01', extra: [
            '--tenant' => (string) $ok->tenant_id,
        ]));

        $this->assertSame($ok->tenant_id, $result['tenant_id']);
        $this->assertSame('pass', $result['checks']['coverage_by_date']['status']);
        $this->assertSame('pass', $result['checks']['coverage_by_tenant']['status']);
    }

    // --- T080: tenant isolation ---------------------------------------------
    //
    // T059 checks completeness (does a tenant have backfilled data); this is
    // a distinct property -- that per-tenant/per-pair figures never mix
    // across tenants (Architect Q6/F10c). Both tests below run WITHOUT
    // --tenant= (so both tenants are visible in one call) and assert each
    // tenant's own entry carries exactly its own numbers, not a merged total
    // -- the only way that could pass is if the underlying queries
    // genuinely partition by tenant_id rather than aggregating across tenants.

    public function test_coverage_by_tenant_partitions_exactly_across_tenants(): void
    {
        $day = Carbon::parse('2028-06-05');

        $tenantA = $this->makeTransaction($day);
        for ($i = 0; $i < 2; $i++) {
            $this->makeTransaction($day, $tenantA->tenant_id);
        }
        // Tenant A: 3 non-quarantined transactions, all missing tax rows.

        $tenantB = $this->makeTransaction($day);
        for ($i = 0; $i < 4; $i++) {
            $this->makeTransaction($day, $tenantB->tenant_id);
        }
        // Tenant B: 5 non-quarantined transactions, all missing tax rows.

        $result = $this->decodeJson($this->runJson('2028-06-01', '2028-07-01'));

        $unexpectedZero = collect($result['checks']['coverage_by_tenant']['unexpected_zero'])->keyBy('tenant_id');

        $this->assertCount(2, $unexpectedZero);
        $this->assertSame(3, $unexpectedZero[$tenantA->tenant_id]['total']);
        $this->assertSame(5, $unexpectedZero[$tenantB->tenant_id]['total']);
    }

    public function test_vat_reconciliation_partitions_exactly_across_tenants(): void
    {
        $day = Carbon::parse('2028-07-05');

        $tenantA = $this->makeTransaction($day, vatAmount: 1000.00);
        $tenantB = $this->makeTransaction($day, vatAmount: 2000.00);
        // Neither tenant has any VAT tax rows, so both fully fail
        // reconciliation -- the diff itself is what proves isolation.

        $result = $this->decodeJson($this->runJson('2028-07-01', '2028-08-01'));

        $failingPairs = collect($result['checks']['vat_reconciliation']['failing_pairs'])->keyBy('tenant_id');

        $this->assertCount(2, $failingPairs);
        // assertEquals, not assertSame: json_decode reads a whole-number
        // float like 1000.0 back as an int (1000), since json_encode omits
        // the trailing .0 -- a JSON round-trip quirk, not a type this test
        // is meant to pin down.
        $this->assertEquals(1000.0, $failingPairs[$tenantA->tenant_id]['vat_amount_sum']);
        $this->assertEquals(2000.0, $failingPairs[$tenantB->tenant_id]['vat_amount_sum']);
    }

    // --- Zero writes -------------------------------------------------------

    public function test_command_never_writes_anything(): void
    {
        $day = Carbon::parse('2028-05-05');
        $transaction = $this->makeTransaction($day, vatAmount: 100.00);
        $this->addTaxRow($transaction, 'VAT', 90.00);
        $this->quarantine($this->makeTransaction($day));

        $tablesToWatch = ['transactions', 'transaction_taxes', 'tax_backfill_records', 'tax_backfill_runs'];
        $watermarks = [];
        foreach ($tablesToWatch as $table) {
            $watermarks[$table] = DB::table($table)->count();
        }

        Artisan::call(self::COMMAND, ['--from' => '2028-05-01', '--to' => '2028-06-01']);

        foreach ($tablesToWatch as $table) {
            $this->assertSame($watermarks[$table], DB::table($table)->count(), "Table {$table} row count changed -- this command must never write anything.");
        }
    }

    // --- helper ------------------------------------------------------------

    private function runJson(string $from, string $to, array $extra = []): string
    {
        Artisan::call(self::COMMAND, array_merge([
            '--from' => $from,
            '--to' => $to,
            '--json' => true,
        ], $extra));

        return Artisan::output();
    }
}
