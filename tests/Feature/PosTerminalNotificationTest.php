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
use App\Http\Controllers\API\V1\TransactionController;

class PosTerminalNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected $terminal;
    protected $tenant;
    protected $company;
    protected $callbackUrl;
    protected $checksumService;
    protected $controller;

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
        
        // Create controller instance
        $this->controller = app(TransactionController::class);
    }

    /**
     * Test that a transaction notification is sent when enabled
     */
    public function test_terminal_receives_transaction_notification()
    {
        $transactionId = Str::uuid()->toString();
        
        // Directly call the notification method
        $this->controller->notifyTerminalOfValidationResult(
            [
                'transaction_id' => $transactionId,
                'terminal_id' => $this->terminal->id,
            ],
            'VALID',
            [],
            $this->terminal->callback_url
        );
        
        // Assert notification was sent to terminal
        Notification::assertSentTo(
            (new \Illuminate\Notifications\AnonymousNotifiable)->route('webhook', $this->terminal->callback_url),
            TransactionResultNotification::class,
            function ($notification, $channels) use ($transactionId) {
                // Verify transaction ID matches
                return $notification->transactionData['transaction_id'] === $transactionId;
            }
        );
    }
    
    /**
     * Test that batch notifications are sent to terminal
     */
    public function test_terminal_receives_batch_notification()
    {
        $batchId = Str::uuid()->toString();
        
        // Directly call the batch notification method
        $this->controller->notifyTerminalOfBatchResult(
            $batchId,
            $this->terminal,
            2, // processed count
            1, // failed count
            [
                ['transaction_id' => Str::uuid()->toString(), 'status' => 'success'],
                ['transaction_id' => Str::uuid()->toString(), 'status' => 'success']
            ],
            [
                ['transaction_id' => Str::uuid()->toString(), 'status' => 'failed']
            ]
        );
        
        // Assert batch notification was sent to terminal
        Notification::assertSentTo(
            (new \Illuminate\Notifications\AnonymousNotifiable)->route('webhook', $this->terminal->callback_url),
            TransactionResultNotification::class
        );
    }
    
    /**
     * Test that notifications are not sent when disabled
     */
    public function test_terminal_notification_respects_disabled_setting()
    {
        // Disable notifications for this terminal
        $this->terminal->update(['notifications_enabled' => false]);
        
        $transactionId = Str::uuid()->toString();
        
        // Directly call the notification method
        $this->controller->notifyTerminalOfValidationResult(
            [
                'transaction_id' => $transactionId,
                'terminal_id' => $this->terminal->id,
            ],
            'VALID',
            [],
            null // We'll let the controller try to find the URL
        );
        
        // Assert no notification was sent to the terminal
        Notification::assertNothingSentTo(
            (new \Illuminate\Notifications\AnonymousNotifiable)->route('webhook', $this->terminal->callback_url)
        );
    }
}