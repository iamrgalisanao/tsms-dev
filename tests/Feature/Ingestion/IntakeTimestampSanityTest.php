<?php

namespace Tests\Feature\Ingestion;

use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TransactionIntake;
use App\Services\PayloadChecksumService;
use App\Services\ProviderTimestampNormalizer;
use App\Support\Metrics;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * US5 (T027): contracts/intake-timestamp-rejections.md in both
 * ingestion.timestamps.mode values (research A7).
 */
class IntakeTimestampSanityTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_MODES = ['true_utc', 'local_time_with_z'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00', 'UTC'));
        config()->set('ingestion.timestamps.earliest', '2020-01-01');
        config()->set('ingestion.timestamps.future_tolerance_minutes', 1440);
        config()->set('ingestion.timestamps.late_threshold_minutes', 1440);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function invalidTimestamps(): array
    {
        return [
            'zero date' => ['0000-00-00T00:00:00Z'],
            'impossible date' => ['2026-02-30T10:00:00Z'],
            'before earliest' => ['2019-12-31T23:59:59Z'],
        ];
    }

    #[DataProvider('invalidTimestamps')]
    public function test_enforce_rejects_invalid_timestamp_with_specific_code(string $timestamp): void
    {
        config()->set('ingestion.timestamps.mode', 'enforce');

        foreach (self::PROVIDER_MODES as $mode) {
            [$tenant, $terminal] = $this->seedTerminal($mode);
            $payload = $this->payload($tenant, $terminal, $timestamp);

            $response = $this->postOfficial($terminal, $payload);

            $this->assertTimestampRejection($response, $payload, 'INVALID_TRANSACTION_TIMESTAMP', $mode);
        }
    }

    #[DataProvider('invalidTimestamps')]
    public function test_observe_accepts_invalid_timestamp_and_counts_would_reject(string $timestamp): void
    {
        config()->set('ingestion.timestamps.mode', 'observe');

        foreach (self::PROVIDER_MODES as $mode) {
            [$tenant, $terminal] = $this->seedTerminal($mode);
            $before = $this->metric('intake.timestamp_would_reject.INVALID_TRANSACTION_TIMESTAMP');

            $response = $this->postOfficial($terminal, $this->payload($tenant, $terminal, $timestamp));

            $this->assertAccepted($response, $mode);
            $this->assertSame($before + 1, $this->metric('intake.timestamp_would_reject.INVALID_TRANSACTION_TIMESTAMP'), $mode);
        }
    }

    public function test_observe_is_the_default_mode(): void
    {
        $this->assertSame('observe', config('ingestion.timestamps.mode'));
    }

    public function test_observe_would_reject_log_carries_references_only(): void
    {
        config()->set('ingestion.timestamps.mode', 'observe');
        [$tenant, $terminal] = $this->seedTerminal('true_utc');
        $payload = $this->payload($tenant, $terminal, '2026-02-30T10:00:00Z');
        Log::spy();

        $response = $this->postOfficial($terminal, $payload);

        $this->assertAccepted($response, 'true_utc');
        $correlationId = $response->json('correlation_id');
        Log::shouldHaveReceived('notice')
            ->withArgs(function ($message, $context = []) use ($tenant, $terminal, $payload, $correlationId) {
                return str_contains($message, 'would be rejected')
                    && $context['tenant_id'] === $tenant->id
                    && $context['terminal_id'] === $terminal->id
                    && $context['transaction_id'] === $payload['transaction']['transaction_id']
                    && $context['correlation_id'] === $correlationId
                    && $context['code'] === 'INVALID_TRANSACTION_TIMESTAMP'
                    && ! array_key_exists('payload', $context)
                    && ! array_key_exists('transaction', $context);
            })
            ->once();
    }

    public function test_future_beyond_tolerance_in_both_modes_and_provider_modes(): void
    {
        foreach (self::PROVIDER_MODES as $mode) {
            $timestamp = $this->wireTimestamp(now()->addHours(25), $mode);

            config()->set('ingestion.timestamps.mode', 'enforce');
            [$tenant, $terminal] = $this->seedTerminal($mode);
            $payload = $this->payload($tenant, $terminal, $timestamp);
            $this->assertTimestampRejection($this->postOfficial($terminal, $payload), $payload, 'FUTURE_TRANSACTION_TIMESTAMP', $mode);

            config()->set('ingestion.timestamps.mode', 'observe');
            [$tenant, $terminal] = $this->seedTerminal($mode);
            $before = $this->metric('intake.timestamp_would_reject.FUTURE_TRANSACTION_TIMESTAMP');
            $this->assertAccepted($this->postOfficial($terminal, $this->payload($tenant, $terminal, $timestamp)), $mode);
            $this->assertSame($before + 1, $this->metric('intake.timestamp_would_reject.FUTURE_TRANSACTION_TIMESTAMP'), $mode);
        }
    }

    public function test_local_time_with_z_is_normalized_before_the_future_check(): void
    {
        // now+25h written as a UTC wall clock is only now+17h once a Manila
        // provider's local_time_with_z value is normalized, so it is accepted.
        config()->set('ingestion.timestamps.mode', 'enforce');
        [$tenant, $terminal] = $this->seedTerminal('local_time_with_z');

        $response = $this->postOfficial($terminal, $this->payload($tenant, $terminal, $this->wireTimestamp(now()->addHours(25), 'true_utc')));

        $this->assertAccepted($response, 'local_time_with_z');
    }

    public function test_within_tolerance_current_and_late_timestamps_are_accepted_in_both_modes(): void
    {
        foreach (['observe', 'enforce'] as $tsMode) {
            config()->set('ingestion.timestamps.mode', $tsMode);

            foreach (self::PROVIDER_MODES as $mode) {
                $label = "{$tsMode}/{$mode}";

                // now + 23h: inside the 24h tolerance.
                [$tenant, $terminal] = $this->seedTerminal($mode);
                $wouldReject = $this->metric('intake.timestamp_would_reject.FUTURE_TRANSACTION_TIMESTAMP');
                $this->assertAccepted($this->postOfficial($terminal, $this->payload($tenant, $terminal, $this->wireTimestamp(now()->addHours(23), $mode))), $label);
                $this->assertSame($wouldReject, $this->metric('intake.timestamp_would_reject.FUTURE_TRANSACTION_TIMESTAMP'), $label);

                // now - 30 days: accepted and counted late.
                [$tenant, $terminal] = $this->seedTerminal($mode);
                $late = $this->metric('intake.late_count');
                $this->assertAccepted($this->postOfficial($terminal, $this->payload($tenant, $terminal, $this->wireTimestamp(now()->subDays(30), $mode))), $label);
                $this->assertSame($late + 1, $this->metric('intake.late_count'), $label);
                $this->assertSame(30 * 86400, (int) Metrics::get('intake.arrival_lag_seconds.last_bucket'), $label);

                // now: accepted, not late.
                [$tenant, $terminal] = $this->seedTerminal($mode);
                $late = $this->metric('intake.late_count');
                $this->assertAccepted($this->postOfficial($terminal, $this->payload($tenant, $terminal, $this->wireTimestamp(now(), $mode))), $label);
                $this->assertSame($late, $this->metric('intake.late_count'), $label);
            }
        }
    }

    public function test_late_flag_and_lag_are_on_the_accept_log_line(): void
    {
        [$tenant, $terminal] = $this->seedTerminal('true_utc');
        Log::spy();

        $this->assertAccepted($this->postOfficial($terminal, $this->payload($tenant, $terminal, $this->wireTimestamp(now()->subDays(2), 'true_utc'))), 'late');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'Intake queued')
                && ($context['late'] ?? null) === true
                && ($context['lag_seconds'] ?? null) === 2 * 86400)
            ->once();
    }

    public function test_current_timestamp_accept_log_has_no_late_fields(): void
    {
        [$tenant, $terminal] = $this->seedTerminal('true_utc');
        Log::spy();

        $this->assertAccepted($this->postOfficial($terminal, $this->payload($tenant, $terminal, $this->wireTimestamp(now(), 'true_utc'))), 'current');

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'Intake queued')
                && ! array_key_exists('late', $context)
                && ! array_key_exists('lag_seconds', $context))
            ->once();
    }

    public function test_bad_timestamp_with_another_structural_error_keeps_structural_code(): void
    {
        config()->set('ingestion.timestamps.mode', 'enforce');
        [$tenant, $terminal] = $this->seedTerminal('true_utc');
        $payload = $this->payload($tenant, $terminal, '0000-00-00T00:00:00Z', function (array $tx) {
            unset($tx['gross_sales']);

            return $tx;
        });

        $response = $this->postOfficial($terminal, $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'STRUCTURAL_VALIDATION_FAILURE')
            ->assertJsonValidationErrors(['transaction.gross_sales', 'transaction.transaction_timestamp']);
        $intake = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();
        $this->assertSame('STRUCTURAL_VALIDATION_FAILURE', $intake->last_error_code);
    }

    public function test_hardware_mismatch_keeps_precedence_over_enforced_timestamp_error(): void
    {
        config()->set('ingestion.timestamps.mode', 'enforce');
        [$tenant, $terminal] = $this->seedTerminal('true_utc');
        $payload = $this->payload($tenant, $terminal, '2026-02-30T10:00:00Z', function (array $tx) {
            $tx['hardware_id'] = 'FOREIGN-HARDWARE';

            return $tx;
        });

        $this->postOfficial($terminal, $payload)
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'HARDWARE_ID_MISMATCH')
            ->assertJsonMissingPath('errors.transaction\.transaction_timestamp');
    }

    public function test_value_failing_the_format_regex_is_not_parsed_and_keeps_structural_behavior(): void
    {
        $normalizer = \Mockery::mock(ProviderTimestampNormalizer::class);
        $normalizer->shouldNotReceive('normalize');
        $this->app->instance(ProviderTimestampNormalizer::class, $normalizer);

        foreach (['observe', 'enforce'] as $tsMode) {
            config()->set('ingestion.timestamps.mode', $tsMode);
            [$tenant, $terminal] = $this->seedTerminal('true_utc');
            $payload = $this->payload($tenant, $terminal, '26-10-2026 10:00');

            $this->postOfficial($terminal, $payload)
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'STRUCTURAL_VALIDATION_FAILURE')
                ->assertJsonValidationErrors(['transaction.transaction_timestamp']);
        }
    }

    public function test_infrastructure_failure_in_timestamp_check_fails_open_in_enforce_mode(): void
    {
        config()->set('ingestion.timestamps.mode', 'enforce');
        $normalizer = \Mockery::mock(ProviderTimestampNormalizer::class);
        $normalizer->shouldReceive('normalize')->andThrow(
            new QueryException('mysql', 'select * from pos_providers', [], new \PDOException('SQLSTATE[HY000]: 2006 MySQL server has gone away'))
        );
        $this->app->instance(ProviderTimestampNormalizer::class, $normalizer);
        [$tenant, $terminal] = $this->seedTerminal('true_utc');
        $before = $this->metric('intake.timestamp_check_error');
        $wouldReject = $this->metric('intake.timestamp_would_reject.INVALID_TRANSACTION_TIMESTAMP');
        Log::spy();

        $this->assertAccepted($this->postOfficial($terminal, $this->payload($tenant, $terminal, $this->wireTimestamp(now(), 'true_utc'))), 'infra');

        $this->assertSame($before + 1, $this->metric('intake.timestamp_check_error'));
        $this->assertSame($wouldReject, $this->metric('intake.timestamp_would_reject.INVALID_TRANSACTION_TIMESTAMP'));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => str_contains($message, 'failed open')
                && $context['exception_class'] === QueryException::class
                && $context['terminal_id'] === $terminal->id
                && ! str_contains(json_encode($context), 'gone away'))
            ->once();
    }

    public function test_empty_earliest_config_disables_the_earliest_bound_in_enforce_mode(): void
    {
        config()->set('ingestion.timestamps.mode', 'enforce');
        config()->set('ingestion.timestamps.earliest', '  ');
        [$tenant, $terminal] = $this->seedTerminal('true_utc');

        $this->assertAccepted($this->postOfficial($terminal, $this->payload($tenant, $terminal, '2019-12-31T23:59:59Z')), 'empty earliest');
    }

    public function test_batch_reports_invalid_over_future_and_uses_item_path(): void
    {
        config()->set('ingestion.timestamps.mode', 'enforce');
        [$tenant, $terminal] = $this->seedTerminal('true_utc');
        $payload = $this->batchPayload($tenant, $terminal, [
            $this->wireTimestamp(now()->addHours(30), 'true_utc'),
            '2026-02-30T10:00:00Z',
        ]);

        $this->postOfficial($terminal, $payload)
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_TRANSACTION_TIMESTAMP')
            ->assertJsonValidationErrors(['transactions.0.transaction_timestamp', 'transactions.1.transaction_timestamp']);
    }

    private function assertTimestampRejection(TestResponse $response, array $payload, string $code, string $label): void
    {
        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', $code)
            ->assertJsonValidationErrors(['transaction.transaction_timestamp']);
        $keys = array_keys($response->json());
        sort($keys);
        $this->assertSame(['correlation_id', 'error_code', 'errors', 'message', 'success'], $keys, $label);

        $intake = TransactionIntake::where('submission_uuid', $payload['submission_uuid'])->firstOrFail();
        $this->assertSame(TransactionIntake::INTAKE_STATUS_REJECTED, $intake->intake_status, $label);
        $this->assertSame($code, $intake->last_error_code, $label);
        $this->assertSame($response->json('correlation_id'), $intake->trace_id, $label);
    }

    private function assertAccepted(TestResponse $response, string $label): void
    {
        $response->assertStatus(200);
        $this->assertSame('PENDING', $response->json('status'), $label);
        $this->assertSame('ACCEPTED', $response->json('code'), $label);
        $keys = array_keys($response->json());
        sort($keys);
        $this->assertSame(['code', 'correlation_id', 'data', 'intake_id', 'message', 'retryable', 'status', 'submission_uuid', 'success'], $keys, $label);
    }

    private function metric(string $name): int
    {
        return (int) Metrics::get($name, 0);
    }

    /** Wire format for an absolute instant as the given provider mode would send it. */
    private function wireTimestamp(Carbon $instant, string $providerMode): string
    {
        $tz = $providerMode === 'local_time_with_z' ? 'Asia/Manila' : 'UTC';

        return $instant->copy()->setTimezone($tz)->format('Y-m-d\TH:i:s\Z');
    }

    private function seedTerminal(string $providerMode): array
    {
        $tenant = Tenant::factory()->create();
        $provider = PosProvider::factory()->create([
            'timestamp_mode' => $providerMode,
            'timezone' => 'Asia/Manila',
        ]);
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'provider_id' => $provider->id,
        ]);

        return [$tenant, $terminal];
    }

    private function postOfficial(PosTerminal $terminal, array $payload): TestResponse
    {
        // Several requests per test use different terminals; drop the guard's
        // cached user so each bearer token is resolved afresh.
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$terminal->generateAccessToken(),
            'Content-Type' => 'application/json',
        ])->postJson('/api/v1/transactions/official', $payload);
    }

    private function transaction(PosTerminal $terminal, string $timestamp, ?callable $mutate = null): array
    {
        $tx = [
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $terminal->serial_number,
            'receipt_no' => 'TS-'.Str::upper(Str::random(8)),
            'transaction_timestamp' => $timestamp,
            'gross_sales' => '100.00',
            'net_sales' => '89.29',
            'promo_status' => 'NONE',
            'customer_code' => 'C-TS',
            'adjustments' => array_map(fn ($type) => ['adjustment_type' => $type, 'amount' => '0.00'], [
                'promo_discount', 'senior_discount', 'pwd_discount', 'vip_card_discount',
                'service_charge_distributed_to_employees', 'service_charge_retained_by_management', 'employee_discount',
            ]),
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => '10.71'],
                ['tax_type' => 'VATABLE_SALES', 'amount' => '89.29'],
                ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => '0.00'],
                ['tax_type' => 'OTHER_TAX', 'amount' => '0.00'],
            ],
        ];
        if ($mutate) {
            $tx = $mutate($tx);
        }
        $tx['payload_checksum'] = (new PayloadChecksumService)->computeChecksum($tx);

        return $tx;
    }

    private function payload(Tenant $tenant, PosTerminal $terminal, string $timestamp, ?callable $mutate = null): array
    {
        return $this->wrap($tenant, $terminal, ['transaction_count' => 1, 'transaction' => $this->transaction($terminal, $timestamp, $mutate)]);
    }

    private function batchPayload(Tenant $tenant, PosTerminal $terminal, array $timestamps): array
    {
        return $this->wrap($tenant, $terminal, [
            'transaction_count' => count($timestamps),
            'transactions' => array_map(fn ($ts) => $this->transaction($terminal, $ts), $timestamps),
        ]);
    }

    private function wrap(Tenant $tenant, PosTerminal $terminal, array $body): array
    {
        $payload = array_merge([
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'submission_timestamp' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
        ], $body);
        $payload['payload_checksum'] = (new PayloadChecksumService)->computeChecksum($payload);

        return $payload;
    }
}
