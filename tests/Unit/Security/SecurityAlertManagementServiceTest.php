<?php

namespace Tests\Unit\Security;

use Tests\TestCase;
use App\Models\SecurityAlert;
use App\Models\SecurityAlertResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Security\SecurityAlertManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

class SecurityAlertManagementServiceTest extends TestCase
{
    use RefreshDatabase;
    
    protected SecurityAlertManagementService $alertService;
    protected SecurityAlert $alert;
    protected User $user;
    protected Tenant $tenant;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Create the alert management service
        $this->alertService = new SecurityAlertManagementService();
        
        // Create a tenant
        $this->tenant = Tenant::factory()->create([
            'name' => 'Test Tenant'
        ]);
        
        // Create a user
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id
        ]);
        
        // Create a test security alert
        $this->alert = SecurityAlert::factory()->create([
            'title' => 'Test Security Alert',
            'description' => 'This is a test alert',
            'severity' => 'High',
            'status' => 'Open',
            'tenant_id' => $this->tenant->id,
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
    
    /** @test */
    public function it_can_acknowledge_an_alert()
    {
        // Acknowledge the alert
        $result = $this->alertService->acknowledgeAlert(
            $this->alert->id,
            $this->tenant->id,
            $this->user->id,
            'Acknowledging this alert for testing'
        );
        
        // Check the result
        $this->assertTrue($result);
        
        // Refresh the alert from the database
        $this->alert->refresh();
        
        // Check the alert status is now Acknowledged
        $this->assertEquals('Acknowledged', $this->alert->status);
        
        // Check that a response was created
        $response = SecurityAlertResponse::where('alert_id', $this->alert->id)
            ->where('user_id', $this->user->id)
            ->where('response_type', 'acknowledge')
            ->first();
        
        $this->assertNotNull($response);
        $this->assertEquals('Acknowledging this alert for testing', $response->notes);
    }
    
    /** @test */
    public function it_can_resolve_an_alert()
    {
        // Resolve the alert
        $result = $this->alertService->resolveAlert(
            $this->alert->id,
            $this->tenant->id,
            $this->user->id,
            'Resolved',
            'Resolving this alert for testing'
        );
        
        // Check the result
        $this->assertTrue($result);
        
        // Refresh the alert from the database
        $this->alert->refresh();
        
        // Check the alert status is now Resolved
        $this->assertEquals('Resolved', $this->alert->status);
        
        // Check that a response was created
        $response = SecurityAlertResponse::where('alert_id', $this->alert->id)
            ->where('user_id', $this->user->id)
            ->where('response_type', 'resolve')
            ->first();
        
        $this->assertNotNull($response);
        $this->assertEquals('Resolving this alert for testing', $response->notes);
    }
    
    /** @test */
    public function it_can_add_notes_to_an_alert()
    {
        // Add notes to the alert
        $result = $this->alertService->addAlertNotes(
            $this->alert->id,
            $this->tenant->id,
            $this->user->id,
            'These are test notes'
        );
        
        // Check the result
        $this->assertTrue($result);
        
        // Check that a response was created with the notes
        $response = SecurityAlertResponse::where('alert_id', $this->alert->id)
            ->where('user_id', $this->user->id)
            ->where('response_type', 'note')
            ->first();
        
        $this->assertNotNull($response);
        $this->assertEquals('These are test notes', $response->notes);
    }
    
    /** @test */
    public function it_returns_false_for_nonexistent_alert()
    {
        // Try to acknowledge a nonexistent alert
        $result = $this->alertService->acknowledgeAlert(
            9999,
            $this->tenant->id,
            $this->user->id,
            'This alert does not exist'
        );
        
        // Should return false
        $this->assertFalse($result);
    }

    /** @test */
    public function it_returns_alert_responses()
    {
        // Add notes to create responses
        $this->alertService->addAlertNotes(
            $this->alert->id,
            $this->tenant->id,
            $this->user->id,
            'Test note 1'
        );
        
        $this->alertService->addAlertNotes(
            $this->alert->id,
            $this->tenant->id,
            $this->user->id,
            'Test note 2'
        );
        
        // Get the responses
        $responses = $this->alertService->getAlertResponses($this->alert->id);
        
        // Check that we got the responses
        $this->assertNotNull($responses);
        $this->assertCount(2, $responses);
        $this->assertEquals('Test note 1', $responses[0]->notes);
        $this->assertEquals('Test note 2', $responses[1]->notes);
    }
}