<?php

namespace Tests\Feature;

use App\Services\IngestionFairnessService;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\FakesIngestionFairnessRedis;

/**
 * T038: Feature tests for App\Services\IngestionFairnessService (T044),
 * exercising the service as a whole unit with broader, multi-tenant/
 * multi-terminal scenarios rather than the narrower single-call-shape
 * assertions in tests/Unit/IngestionFairnessServiceTest.php. No middleware
 * or route exists yet (that is T045's job), so these are "feature" tests in
 * the sense of exercising the service's full behavioral contract — hot
 * tenant vs. cold tenant, sibling terminals, cross-scope independence,
 * exact-limit boundary crossing, retry-after/reset_at correctness, fail-open
 * on Redis failure, and store-level key isolation — not HTTP-level tests.
 *
 * Redis is faked via tests/Traits/FakesIngestionFairnessRedis, the same
 * Lua-simulation fake used by tests/Unit/IngestionFairnessServiceTest.php
 * (extracted during T038 so both test files share one simulation of
 * FAIRNESS_CHECK_SCRIPT instead of risking two independent fakes drifting
 * apart from each other or from the real script).
 *
 * These tests intentionally use the ACTUAL configured fairness limits from
 * config/tsms.php (global/tenant/terminal limits, window_seconds) rather
 * than synthetic overridden values, reading them dynamically via config()
 * at runtime so the scenarios stay correct if those defaults are ever
 * tuned. Carbon::setTestNow() pins "now" for every scenario so window-bucket
 * boundaries and retry-after/reset-at arithmetic are fully deterministic —
 * no sleep(), no real wall-clock timing.
 *
 * Batch vs. official HTTP parity (mentioned in T038's task text) is
 * explicitly NOT covered here: fairness is not wired into any middleware or
 * route yet, so there is no real HTTP-level batch-vs-official distinction
 * to exercise at this level. That parity check belongs to T045, once the
 * fairness middleware is actually applied to both
 * `/api/v1/transactions/official` and `/api/v1/transactions/batch`.
 */
class IngestionFairnessTest extends TestCase
{
    use FakesIngestionFairnessRedis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetFakeFairnessRedisStore();
    }

    protected function tearDown(): void
    {
        // Review finding F2: verify script identity ONCE per test (if this
        // test actually exercised a simulated eval() call), rather than the
        // old per-call assertion inside the fake's eval() closure.
        if ($this->fakeFairnessLastScriptSeen !== null) {
            $this->assertFairnessScriptWasUsed();
        }

        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function tenantLimit(): int
    {
        return (int) config('tsms.fairness.tenant.limit');
    }

    private function terminalLimit(): int
    {
        return (int) config('tsms.fairness.terminal.limit');
    }

    private function globalLimit(): int
    {
        return (int) config('tsms.fairness.global.limit');
    }

    private function windowSeconds(): int
    {
        return (int) config('tsms.fairness.window_seconds');
    }

    private function keyPrefix(): string
    {
        return (string) config('tsms.fairness.key_prefix');
    }

    private function windowBucketFor(Carbon $now): int
    {
        return intdiv($now->timestamp, $this->windowSeconds());
    }

    /**
     * Builds the exact Redis key IngestionFairnessService::check() will
     * read/increment for a given scope/scope_id at the CURRENT
     * Carbon::setTestNow()-controlled "now" — the same
     * `{key_prefix}{scope}:{scope_id}:{window}` format the service
     * documents, with the same window-bucket arithmetic reconstructed here
     * (windowBucketFor()) — so seedFairnessCount() can write directly into
     * the fake's store under the SAME key the service will compute (review
     * finding F1). Boundary/exact-limit scenarios use this to jump straight
     * to "one below the configured limit" instead of looping a real
     * simulated check() call up to the real configured limit (up to 10,000
     * for the global scope).
     */
    private function fairnessKey(string $scope, int $scopeId): string
    {
        $windowBucket = $this->windowBucketFor(Carbon::now());

        return "{$this->keyPrefix()}{$scope}:{$scopeId}:{$windowBucket}";
    }

    /**
     * Scenario 1: a hot tenant exceeding its configured limit is rejected
     * while a different, cold tenant remains allowed — in the same test, to
     * prove isolation under a "hot tenant" narrative rather than two
     * unrelated assertions.
     */
    public function test_hot_tenant_exceeds_limit_while_another_tenant_remains_allowed(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;
        $hotTenantId = 9001;
        $coldTenantId = 9002;

        $this->seedFairnessCount($this->fairnessKey('tenant', $hotTenantId), $this->tenantLimit() - 1);

        $atLimit = $service->checkTenant($hotTenantId);
        $this->assertTrue($atLimit['allowed'], 'the request that lands exactly at the tenant limit must still be allowed');
        $this->assertSame($this->tenantLimit(), $atLimit['count']);

        $overLimit = $service->checkTenant($hotTenantId);
        $this->assertFalse($overLimit['allowed'], 'the hot tenant must be rejected the moment it exceeds its own limit');
        $this->assertSame('tenant', $overLimit['scope']);
        $this->assertSame($this->tenantLimit(), $overLimit['limit']);
        $this->assertSame($this->tenantLimit() + 1, $overLimit['count']);

        $coldTenantResult = $service->checkTenant($coldTenantId);
        $this->assertTrue($coldTenantResult['allowed'], 'a different, cold tenant must remain allowed while the hot tenant is rejected');
        $this->assertSame('tenant', $coldTenantResult['scope']);
        $this->assertSame($this->tenantLimit(), $coldTenantResult['limit']);
        $this->assertSame(1, $coldTenantResult['count']);
    }

    /**
     * Scenario 2: a terminal exceeding its configured limit is rejected
     * while a sibling terminal remains allowed. Per the service's
     * documented key format (`fairness:{scope}:{scope_id}:{window}`),
     * terminal-scope keys carry only the terminal ID — no tenant
     * qualifier — so two terminals are isolated purely by their own IDs
     * regardless of which tenant they conceptually belong to.
     */
    public function test_one_terminal_exceeds_limit_while_sibling_terminal_remains_allowed(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;
        $hotTerminalId = 8101;
        $siblingTerminalId = 8102;

        $this->seedFairnessCount($this->fairnessKey('terminal', $hotTerminalId), $this->terminalLimit() - 1);

        $atLimit = $service->checkTerminal($hotTerminalId);
        $this->assertTrue($atLimit['allowed']);
        $this->assertSame($this->terminalLimit(), $atLimit['count']);

        $overLimit = $service->checkTerminal($hotTerminalId);
        $this->assertFalse($overLimit['allowed'], 'the hot terminal must be rejected the moment it exceeds its own limit');
        $this->assertSame('terminal', $overLimit['scope']);
        $this->assertSame($this->terminalLimit(), $overLimit['limit']);
        $this->assertSame($this->terminalLimit() + 1, $overLimit['count']);

        $siblingResult = $service->checkTerminal($siblingTerminalId);
        $this->assertTrue($siblingResult['allowed'], 'a sibling terminal must remain allowed while the hot terminal is rejected');
        $this->assertSame('terminal', $siblingResult['scope']);
        $this->assertSame(1, $siblingResult['count']);
    }

    /**
     * Scenario 3: the global scope throttles independently of tenant and
     * terminal scopes. T044 does not compose the three checks into one
     * verdict, so a fresh, unexhausted tenant/terminal check must still
     * return allowed=true even while the global counter is exhausted.
     */
    public function test_global_scope_throttles_independently_of_tenant_and_terminal_scopes(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;

        $this->seedFairnessCount($this->fairnessKey('global', 0), $this->globalLimit() - 1);

        $atLimit = $service->checkGlobal();
        $this->assertTrue($atLimit['allowed']);
        $this->assertSame($this->globalLimit(), $atLimit['count']);

        $overLimit = $service->checkGlobal();
        $this->assertFalse($overLimit['allowed'], 'the global scope must be rejected once its own limit is exceeded');
        $this->assertSame('global', $overLimit['scope']);
        $this->assertSame($this->globalLimit(), $overLimit['limit']);

        $freshTenantResult = $service->checkTenant(9501);
        $this->assertTrue($freshTenantResult['allowed'], 'a fresh tenant must remain allowed even while the global counter is exhausted');
        $this->assertSame(1, $freshTenantResult['count']);

        $freshTerminalResult = $service->checkTerminal(9502);
        $this->assertTrue($freshTerminalResult['allowed'], 'a fresh terminal must remain allowed even while the global counter is exhausted');
        $this->assertSame(1, $freshTerminalResult['count']);
    }

    /**
     * Scenario 4: a precise boundary-crossing sequence for one scope
     * (terminal, the smallest realistic limit) — the exact-limit request is
     * allowed, and the very next request in the same sequence is rejected.
     */
    public function test_exact_limit_request_is_allowed_then_the_next_request_is_rejected(): void
    {
        $this->fakeFairnessRedis();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;
        $terminalId = 8201;

        $this->seedFairnessCount($this->fairnessKey('terminal', $terminalId), $this->terminalLimit() - 1);

        $atLimit = $service->checkTerminal($terminalId);
        $this->assertTrue($atLimit['allowed'], 'a count exactly equal to the configured limit must be allowed');
        $this->assertSame($this->terminalLimit(), $atLimit['count']);
        $this->assertSame($this->terminalLimit(), $atLimit['limit']);

        $nextRequest = $service->checkTerminal($terminalId);
        $this->assertFalse($nextRequest['allowed'], 'the very next request after the exact limit must be rejected');
        $this->assertSame($this->terminalLimit() + 1, $nextRequest['count']);
    }

    /**
     * Scenario 5: a rejected result's retry_after_seconds and reset_at are
     * concretely related — reset_at minus "now" equals retry_after_seconds
     * — using Carbon::setTestNow() so "now" is deterministic rather than
     * relying on real wall-clock timing.
     */
    public function test_rejected_result_retry_after_seconds_matches_reset_at(): void
    {
        $this->fakeFairnessRedis();
        $now = Carbon::parse('2026-01-01 00:00:00')->addSeconds(intdiv($this->windowSeconds(), 3));
        Carbon::setTestNow($now);

        $service = new IngestionFairnessService;
        $terminalId = 8301;

        $this->seedFairnessCount($this->fairnessKey('terminal', $terminalId), $this->terminalLimit() - 1);

        $service->checkTerminal($terminalId); // lands exactly at the limit; still allowed
        $rejected = $service->checkTerminal($terminalId);

        $this->assertFalse($rejected['allowed']);

        $windowBucket = $this->windowBucketFor($now);
        $expectedResetAt = Carbon::createFromTimestamp(($windowBucket + 1) * $this->windowSeconds());

        $actualResetAt = Carbon::parse($rejected['reset_at']);
        $this->assertSame($expectedResetAt->timestamp, $actualResetAt->timestamp);
        $this->assertSame(
            $actualResetAt->timestamp - $now->timestamp,
            $rejected['retry_after_seconds'],
            'retry_after_seconds must equal reset_at minus now, exactly'
        );
    }

    /**
     * Scenario 6: Redis failure fails open across a call sequence — not
     * just an isolated single call. Simulates FAIRNESS_CHECK_SCRIPT's eval()
     * itself throwing (the same technique as the unit test's
     * test_eval_script_failure_fails_open_with_a_well_formed_result) and
     * asserts every call in a mixed-scope sequence returns a well-formed,
     * allowed=true result.
     */
    public function test_redis_failure_fails_open_across_a_call_sequence(): void
    {
        $this->fakeFairnessRedisWithFailingEval();
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $service = new IngestionFairnessService;

        $sequence = [
            fn () => $service->checkGlobal(),
            fn () => $service->checkTenant(9601),
            fn () => $service->checkTerminal(9602),
            fn () => $service->checkTenant(9601),
            fn () => $service->checkGlobal(),
        ];

        foreach ($sequence as $call) {
            $result = $call();

            $this->assertSame(
                ['allowed', 'scope', 'limit', 'count', 'retry_after_seconds', 'reset_at'],
                array_keys($result),
                'a well-formed result shape must be returned even when Redis is failing'
            );
            $this->assertTrue($result['allowed'], 'every call in the sequence must fail open while Redis is failing');
            $this->assertSame(0, $result['count']);
            $this->assertIsInt($result['retry_after_seconds']);
            $this->assertGreaterThanOrEqual(1, $result['retry_after_seconds']);
            $this->assertIsString($result['reset_at']);
        }
    }

    /**
     * Scenario 7: tenant, terminal, and global keys remain isolated in the
     * underlying store — inspecting the fake's internal state directly
     * (rather than only inferring isolation from checkX() return values,
     * as scenarios 1-3 already do) to prove there is no cross-key
     * contamination between scopes at the storage level.
     */
    public function test_tenant_terminal_and_global_keys_remain_isolated_in_the_underlying_store(): void
    {
        $this->fakeFairnessRedis();
        $now = Carbon::parse('2026-01-01 00:00:00');
        Carbon::setTestNow($now);

        $service = new IngestionFairnessService;
        $windowBucket = $this->windowBucketFor($now);
        $prefix = $this->keyPrefix();

        $tenantAId = 9701;
        $tenantBId = 9702;
        $terminalId = 9701; // deliberately reuses tenant A's numeric ID in a different scope

        // Tenant A: 3 calls. Tenant B: 1 call. Terminal (numeric ID 9701,
        // same as tenant A's ID but a different scope): 2 calls. Global: 1
        // call. Each scope's key must reflect only its own call count.
        $service->checkTenant($tenantAId);
        $service->checkTenant($tenantAId);
        $service->checkTenant($tenantAId);
        $service->checkTenant($tenantBId);
        $service->checkTerminal($terminalId);
        $service->checkTerminal($terminalId);
        $service->checkGlobal();

        $tenantAKey = "{$prefix}tenant:{$tenantAId}:{$windowBucket}";
        $tenantBKey = "{$prefix}tenant:{$tenantBId}:{$windowBucket}";
        $terminalKey = "{$prefix}terminal:{$terminalId}:{$windowBucket}";
        $globalKey = "{$prefix}global:0:{$windowBucket}";

        $this->assertSame(3, $this->fakeFairnessRedisCount($tenantAKey), 'tenant A key must reflect exactly its own 3 calls');
        $this->assertSame(1, $this->fakeFairnessRedisCount($tenantBKey), 'tenant B key must be unaffected by tenant A');
        $this->assertSame(2, $this->fakeFairnessRedisCount($terminalKey), 'the terminal key must be a distinct counter from the tenant key sharing the same numeric ID');
        $this->assertSame(1, $this->fakeFairnessRedisCount($globalKey), 'the global key must be unaffected by tenant/terminal calls');

        // No key accidentally aliases another: four distinct keys, four
        // distinct stored counts.
        $this->assertCount(4, array_unique([$tenantAKey, $tenantBKey, $terminalKey, $globalKey]));
    }

    /**
     * Scenario 8: structural guard proving IngestionFairnessService does
     * not call or depend on IngestionQueueRouter, keeping the two
     * abstractions decoupled as designed ("the router stays a pure, I/O-
     * free function answering 'which queue'; this service owns I/O and
     * answers 'allow or throttle now'"). Lightweight source-text inspection,
     * consistent with TenantShardModuloStaticAnalysisTest's PhpToken-based
     * static-scan approach, rather than a deep architecture test.
     *
     * A raw substring grep would produce a false positive here: the
     * service's own doc-comment explicitly documents the design rule
     * ("must never reference IngestionQueueRouter or compute/return a
     * queue name"), so the literal string legitimately appears inside a
     * comment. Tokenizing the file and dropping T_COMMENT/T_DOC_COMMENT
     * tokens before searching distinguishes that documentation-only mention
     * from a genuine code-level reference (an import, `new
     * IngestionQueueRouter`, a static call, a type-hint, etc.) — the actual
     * coupling this guard exists to catch.
     */
    public function test_fairness_service_does_not_reference_ingestion_queue_router(): void
    {
        $path = app_path('Services/IngestionFairnessService.php');
        $source = file_get_contents($path);
        $this->assertIsString($source, 'IngestionFairnessService.php must exist and be readable');

        $codeOnly = '';
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->id === T_COMMENT || $token->id === T_DOC_COMMENT) {
                continue;
            }

            $codeOnly .= $token->text;
        }

        $this->assertStringNotContainsString(
            'IngestionQueueRouter',
            $codeOnly,
            'IngestionFairnessService must not have any code-level reference to IngestionQueueRouter (imports, instantiation, static calls, or type-hints)'
        );
    }
}
