<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\SubmissionEvent;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class ChecksumSubmissionEventTest extends TestCase
{
    use RefreshDatabase;

    protected $terminal;
    protected $tenant;
    protected $token;
    protected $checksumService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->checksumService = new PayloadChecksumService();
        
        // Find existing tenant or create one
        $this->tenant = Tenant::first();
        if (!$this->tenant) {
            $this->markTestSkipped('No tenants found for testing. Run seeders first.');
        }
        
        // Create a simple terminal status if needed
        $terminalStatus = \App\Models\TerminalStatus::first();
        if (!$terminalStatus) {
            $terminalStatus = \App\Models\TerminalStatus::create([
                'status' => 'ACTIVE',
                'description' => 'Active Terminal'
            ]);
        }
        
        // Create test terminal
        $this->terminal = PosTerminal::create([
            'tenant_id' => $this->tenant->id,
            'serial_number' => 'TEST-CHECKSUM-' . uniqid(),
            'status_id' => $terminalStatus->id,
            'notifications_enabled' => true,
        ]);
        
        // Create auth token
        $this->token = $this->terminal->createToken('test-checksum')->plainTextToken;
    }

    public function test_checksum_failure_creates_submission_event()
    {
        // Count events before test
        $eventsBefore = SubmissionEvent::count();
        
        // Create payload with invalid checksum but valid structure
        $payload = [
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'submission_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => 1,
            'payload_checksum' => 'a234567890123456789012345678901234567890123456789012345678901234',
            'transaction' => [
                'transaction_id' => (string) Str::uuid(),
                'transaction_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
                'gross_sales' => 100.00,
                'net_sales' => 88.00,
                'promo_status' => 'WITH_APPROVAL',
                'customer_code' => 'C-TEST001',
                'payload_checksum' => 'b234567890123456789012345678901234567890123456789012345678901234',
                'adjustments' => [
                    ['adjustment_type' => 'promo_discount', 'amount' => 0],
                    ['adjustment_type' => 'senior_discount', 'amount' => 12.00],
                    ['adjustment_type' => 'pwd_discount', 'amount' => 0],
                    ['adjustment_type' => 'vip_card_discount', 'amount' => 0],
                    ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0],
                    ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0],
                    ['adjustment_type' => 'employee_discount', 'amount' => 0],
                ],
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => 10.00],
                    ['tax_type' => 'VATABLE_SALES', 'amount' => 88.00],
                    ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 0],
                    ['tax_type' => 'OTHER_TAX', 'amount' => 0],
                ]
            ]
        ];

        // Make request with invalid checksum
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ])->postJson('/api/v1/transactions/official', $payload);

        // Assert response is 422 (validation failed)
        $response->assertStatus(422);
        
        // Debug: Check what the actual response contains
        $responseData = $response->json();
        echo "Response: " . json_encode($responseData, JSON_PRETTY_PRINT) . "\n";
        
        // Check that SubmissionEvent was created
        $eventsAfter = SubmissionEvent::count();
        echo "Events before: $eventsBefore, Events after: $eventsAfter\n";
        
        if ($eventsAfter > $eventsBefore) {
            $event = SubmissionEvent::latest()->first();
            echo "Latest event: " . json_encode($event->toArray(), JSON_PRETTY_PRINT) . "\n";
        }
        
        $this->assertEquals($eventsBefore + 1, $eventsAfter, 'SubmissionEvent should be created for checksum failure');

        // Verify the SubmissionEvent details
        $event = SubmissionEvent::latest()->first();
        $this->assertNotNull($event);
        $this->assertEquals('REJECTED', $event->status);
        $this->assertEquals('CHECKSUM_MISMATCH', $event->reason_code);
        $this->assertEquals($payload['submission_uuid'], $event->submission_uuid);
        $this->assertEquals($this->terminal->tenant_id, $event->tenant_id);
        $this->assertEquals($this->terminal->id, $event->terminal_id);
        $this->assertEquals(1, $event->transaction_count);
        
        // Verify reason_details contains checksum errors
        $this->assertIsArray($event->reason_details);
        $this->assertArrayHasKey('errors', $event->reason_details);
        $this->assertNotEmpty($event->reason_details['errors']);
    }

    public function test_valid_checksum_does_not_create_rejection_event()
    {
        // Create valid payload
        $transaction = [
            'transaction_id' => (string) Str::uuid(),
            'transaction_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
            'gross_sales' => 100.00,
            'net_sales' => 88.00,
            'promo_status' => 'WITH_APPROVAL',
            'customer_code' => 'C-TEST001',
            'adjustments' => [
                ['adjustment_type' => 'promo_discount', 'amount' => 0],
                ['adjustment_type' => 'senior_discount', 'amount' => 12.00],
                ['adjustment_type' => 'pwd_discount', 'amount' => 0],
                ['adjustment_type' => 'vip_card_discount', 'amount' => 0],
                ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0],
                ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0],
                ['adjustment_type' => 'employee_discount', 'amount' => 0],
            ],
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => 10.00],
                ['tax_type' => 'VATABLE_SALES', 'amount' => 88.00],
                ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 0],
                ['tax_type' => 'OTHER_TAX', 'amount' => 0],
            ]
        ];
        
        // Calculate correct transaction checksum
        $transaction['payload_checksum'] = $this->checksumService->computeChecksum($transaction);

        $payload = [
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'submission_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction,
        ];
        
        // Calculate correct submission checksum
        $payload['payload_checksum'] = $this->checksumService->computeChecksum($payload);

        // Count REJECTED events before test
        $rejectedEventsBefore = SubmissionEvent::where('status', 'REJECTED')->count();

        // Make request with valid checksum
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ])->postJson('/api/v1/transactions/official', $payload);

        // Assert response is successful (200)
        $response->assertStatus(200);

        // Verify no new REJECTED events were created
        $rejectedEventsAfter = SubmissionEvent::where('status', 'REJECTED')->count();
        $this->assertEquals($rejectedEventsBefore, $rejectedEventsAfter, 'No REJECTED events should be created for valid checksums');
    }
}
