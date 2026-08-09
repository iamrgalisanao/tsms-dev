<?php

namespace Tests\Unit;

use App\Support\LogContext;
use Tests\TestCase;

/**
 * WU1: LogContext::ingestion() is the shared shape ingestion touchpoints
 * (TransactionIntakeService, CircuitBreakerMiddleware, and later the
 * already-correct payload-size/backpressure/fairness middleware) build
 * tenant_id/terminal_id/route/shard/correlation_id/decision/reason from,
 * instead of each call site inventing its own array shape.
 */
class LogContextTest extends TestCase
{
    public function test_ingestion_includes_only_allowed_keys_and_omits_nulls(): void
    {
        $ctx = LogContext::ingestion([
            'tenant_id' => 7,
            'terminal_id' => null,
            'route' => 'transactions.official',
            'shard' => 3,
            'correlation_id' => 'abc-123',
            'decision' => 'queued',
            'reason' => null,
            'not_a_canonical_key' => 'should be dropped',
        ]);

        $this->assertSame(7, $ctx['tenant_id']);
        $this->assertSame('transactions.official', $ctx['route']);
        $this->assertSame(3, $ctx['shard']);
        $this->assertSame('abc-123', $ctx['correlation_id']);
        $this->assertSame('queued', $ctx['decision']);
        $this->assertArrayNotHasKey('terminal_id', $ctx);
        $this->assertArrayNotHasKey('reason', $ctx);
        $this->assertArrayNotHasKey('not_a_canonical_key', $ctx);
    }

    public function test_ingestion_merges_extra_call_specific_fields_as_is(): void
    {
        $ctx = LogContext::ingestion(
            ['correlation_id' => 'abc-123'],
            ['submission_uuid' => 'sub-1', 'queued' => true]
        );

        $this->assertSame('abc-123', $ctx['correlation_id']);
        $this->assertSame('sub-1', $ctx['submission_uuid']);
        $this->assertTrue($ctx['queued']);
    }

    public function test_ingestion_always_includes_base_app_env_context(): void
    {
        $ctx = LogContext::ingestion();

        $this->assertArrayHasKey('app', $ctx);
        $this->assertArrayHasKey('env', $ctx);
    }
}
