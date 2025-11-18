<?php

namespace Tests\Feature\WebappApi;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_endpoint_returns_aggregates()
    {
        // Seed summary table on reporting connection
        DB::connection('reporting')->table('transactions_daily')->insert([
            [
                'tenant_id' => 1,
                'terminal_id' => 0,
                'date' => now()->toDateString(),
                'tx_count' => 10,
                'total_amount' => 1000.50,
                'avg_amount' => 100.05,
                'issues_count' => 0,
                'issues_amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        $user = User::factory()->create(['email' => 'webapp@example.test']);
        $token = $user->createToken('webapp-test-token', ['webapp:read'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json'
        ])->getJson('/api/v1/webapp/reports/sales?period=daily&start=' . now()->toDateString() . '&end=' . now()->toDateString() . '&tenant_id=1');

        $response->assertStatus(200)->assertJsonStructure(['period', 'start', 'end', 'data', 'meta']);
        $json = $response->json();
        $this->assertEquals('daily', $json['period']);
        $this->assertNotEmpty($json['data']);
    }

    public function test_summary_endpoint_returns_today_totals()
    {
        DB::connection('reporting')->table('transactions_daily')->insert([
            [
                'tenant_id' => 2,
                'terminal_id' => 0,
                'date' => now()->toDateString(),
                'tx_count' => 5,
                'total_amount' => 500.00,
                'avg_amount' => 100.00,
                'issues_count' => 0,
                'issues_amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        $user = User::factory()->create(['email' => 'webapp2@example.test']);
        $token = $user->createToken('webapp-test-token', ['webapp:read'])->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json'
        ])->getJson('/api/v1/webapp/reports/summary?tenant_id=2');

        $response->assertStatus(200)->assertJsonStructure(['today' , 'generated_at']);
        $json = $response->json();
        $this->assertEquals(500.00, (float) $json['today']['gross_sales']);
    }
}
