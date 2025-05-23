<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_page_loads()
    {
        $response = $this->actingAs($this->createUser())
            ->get(route('dashboard.performance'));
        
        $response->assertStatus(200);
    }

    public function test_existing_routes_still_work()
    {
        $response = $this->actingAs($this->createUser())
            ->get(route('dashboard'));
        
        $response->assertStatus(200);
    }
}
