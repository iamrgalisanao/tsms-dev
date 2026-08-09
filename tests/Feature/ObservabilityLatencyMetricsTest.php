<?php

namespace Tests\Feature;

use App\Http\Controllers\API\V1\ObservabilityController;
use App\Support\Metrics;
use Tests\TestCase;

/**
 * Regression coverage for the WU2 (T053 foundation) confirmed-dead-key fix
 * in ObservabilityController::index(): the 'latencies' block used to read
 * Metrics::get('intake.dispatch_latency:avg', ...) and two similarly
 * ':avg'-suffixed keys that nothing ever wrote, so it silently always
 * returned the 0 default. It now reads the keys Metrics::timing() actually
 * writes. Field names and the 0 default are intentionally unchanged from
 * before the fix — resources/js/Components/IntakeHealth/PipelineHealthPanel.jsx
 * and resources/js/Pages/Observability/IntakeHealthPage.jsx are live
 * consumers keyed on these exact field names.
 *
 * Calls the controller method directly rather than through the HTTP route
 * (which sits behind 'abilities:admin:manage' auth) since index() has no
 * request-dependent behavior worth exercising through the full HTTP stack
 * for this regression.
 */
class ObservabilityLatencyMetricsTest extends TestCase
{
    public function test_index_reads_real_latency_values_not_dead_avg_keys(): void
    {
        Metrics::timing('intake.dispatch_latency', 42.5);
        Metrics::timing('intake.processing_lag', 3.2);
        Metrics::timing('intake.worker_time', 88.0);

        $response = (new ObservabilityController)->index();
        $data = json_decode($response->getContent(), true);

        // assertEquals (not assertSame): json_encode() renders a whole-number
        // float like 88.0 as "88", so json_decode() reads it back as int(88)
        // — a PHP JSON round-trip quirk unrelated to the fix under test.
        $this->assertEquals(42.5, $data['latencies']['dispatch_avg_ms']);
        $this->assertEquals(3.2, $data['latencies']['processing_lag_avg_s']);
        $this->assertEquals(88.0, $data['latencies']['worker_time_avg_ms']);
    }

    public function test_index_latencies_default_to_zero_when_nothing_sampled_yet(): void
    {
        $response = (new ObservabilityController)->index();
        $data = json_decode($response->getContent(), true);

        $this->assertSame(0, $data['latencies']['dispatch_avg_ms']);
        $this->assertSame(0, $data['latencies']['processing_lag_avg_s']);
        $this->assertSame(0, $data['latencies']['worker_time_avg_ms']);
    }
}
