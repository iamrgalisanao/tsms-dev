<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Company;
use App\Notifications\TransactionResultNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use App\Services\PayloadChecksumService;

class DebugTerminalNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected $terminal;
    protected $tenant;
    protected $company;
    protected $callbackUrl;
    protected $checksumService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock notification facade
        Notification::fake();
        
        // Initialize checksum service
        $this->checksumService = new PayloadChecksumService();
        
        // Create test data
        $this->company = Company::factory()->create([
            'customer_code' => 'TESTCUST-001'
        ]);
        
        $this->tenant = Tenant::factory()->create([
            'company_id' => $this->company->id
        ]);
        
        $this->callbackUrl = "https://webhook-test.tsms.dev/" . Str::uuid();
        
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'serial_number' => 'TEST-TERMINAL-' . Str::random(8),
            'callback_url' => $this->callbackUrl,
            'notifications_enabled' => true,
            'notification_preferences' => json_encode([
                'receive_validation_results' => true,
                'receive_batch_results' => true,
                'include_details' => true
            ])
        ]);
        
        // Debug what terminal was created
        echo "Terminal created: ID=" . $this->terminal->id . 
             ", notifications_enabled=" . ($this->terminal->notifications_enabled ? 'true' : 'false') . 
             ", callback_url=" . $this->terminal->callback_url . "\n";
    }

    /**
     * Test with direct notification
     */
    public function test_direct_notification()
    {
        // Create simple transaction data
        $transactionData = [
            'transaction_id' => Str::uuid()->toString(),
            'terminal_id' => $this->terminal->id
        ];
        
        // Directly create and send a notification
        $notification = new TransactionResultNotification(
            $transactionData,
            'VALID',
            [],
            $this->terminal->callback_url
        );
        
        // Send notification
        Notification::route('webhook', $this->terminal->callback_url)
            ->notify($notification);
        
        // Check if notification was sent
        Notification::assertSentTo(
            (new \Illuminate\Notifications\AnonymousNotifiable)->route('webhook', $this->terminal->callback_url),
            TransactionResultNotification::class
        );
        
        // Success message if we got here
        echo "Direct notification test passed!\n";
    }
}
