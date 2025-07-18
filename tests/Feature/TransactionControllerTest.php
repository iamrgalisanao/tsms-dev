<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Company;
use App\Models\Transaction;

class TransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_transaction_success()
    {
        // Create company, tenant, and terminal
        $company = Company::factory()->create(['customer_code' => 'CUST123']);
        $tenant = Tenant::factory()->create(['company_id' => $company->id]);
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'serial_number' => 'SN123',
            'notifications_enabled' => true,
            'callback_url' => 'https://example.com/callback',
        ]);

        $payload = [
            'customer_code' => $company->customer_code,
            'terminal_id' => $terminal->id,
            'transaction_id' => 'TXN123',
            'base_amount' => 100.50,
            'transaction_timestamp' => now()->toISOString(),
            'items' => [
                [
                    'id' => 'ITEM1',
                    'name' => 'Test Item',
                    'price' => 100.50,
                    'quantity' => 1
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/transaction', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'transaction_id',
                    'status',
                    'timestamp'
                ]
            ]);

        $this->assertDatabaseHas('transactions', [
            'transaction_id' => 'TXN123',
            'terminal_id' => $terminal->id,
            'base_amount' => 100.50
        ]);
    }
}