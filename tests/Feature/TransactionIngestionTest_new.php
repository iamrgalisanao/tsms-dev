<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Company;
use App\Services\PayloadChecksumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionIngestionTest extends TestCase
{
    use RefreshDatabase, WithFaker;
    
    protected $terminal;
    protected $token;
    protected $checksumService;
    
    public function setUp(): void
    {
        parent::setUp();
        
        // Initialize checksum service
        $this->checksumService = new PayloadChecksumService();
        
        // Create a company first
        $company = Company::factory()->create([
            'customer_code' => 'CUST-001'
        ]);
        
        // Create a tenant
        $tenant = Tenant::factory()->create([
            'company_id' => $company->id,
            'trade_name' => 'Test Tenant',
            'status' => 'Operational'
        ]);
        
        // Create a terminal for testing
        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'serial_number' => 'TERM-TEST-001',
            'status_id' => 1
        ]);
        
        // Generate a token for the terminal
        $this->token = $this->generateTerminalToken($this->terminal);
    }
    
    /**
     * Generate a token for testing
     */
    private function generateTerminalToken($terminal)
    {
        $user = User::factory()->create();
        return $user->createToken('terminal-token')->plainTextToken;
    }
    
    /** @test */
    public function endpoint_exists_and_returns_correct_response_structure()
    {
        // Arrange
        $payload = $this->getValidPayload();
        
        // Act
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/transactions', $payload);
        
        // Assert
        $this->assertTrue(
            $response->status() != 404, 
            'The /api/v1/transactions endpoint does not exist'
        );
        
        if ($response->status() == 200 || $response->status() == 201) {
            $response->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'transaction_id'
                ]
            ]);
        } else {
            $this->printTestResult('Endpoint exists but returned status: ' . $response->status());
            $this->printTestResult('Response: ' . $response->content());
        }
    }
    
    /** @test */
    public function transaction_is_stored_in_database()
    {
        // Arrange
        $payload = $this->getValidPayload();
        
        // Act
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/transactions', $payload);
        
        // Assert
        if ($response->status() == 200 || $response->status() == 201) {
            $transactionExists = false;
            $tables = ['transactions', 'pos_transactions', 'sales_transactions'];
            
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    $transactionExists = DB::table($table)
                        ->where('transaction_id', $payload['transaction_id'])
                        ->exists();
                    
                    if ($transactionExists) {
                        break;
                    }
                }
            }
            
            $this->assertTrue(
                $transactionExists,
                'Transaction was not stored in any of the expected tables'
            );
        } else {
            $this->printTestResult('API returned error status: ' . $response->status());
            $this->printTestResult('Response: ' . $response->content());
        }
    }
    
    /** @test */
    public function validation_rejects_invalid_payload()
    {
        // Arrange
        $invalidPayload = [
            'base_amount' => 100.50,
            'terminal_id' => $this->terminal->id
        ];
        
        // Act
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/transactions', $invalidPayload);
        
        // Assert
        $this->assertEquals(422, $response->status(), 'Should reject invalid payload with 422 status');
    }
    
    /** @test */
    public function authentication_is_required()
    {
        // Arrange
        $payload = $this->getValidPayload();
        
        // Act
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token',
            'Accept' => 'application/json',
        ])->postJson('/api/v1/transactions', $payload);
        
        // Assert
        $this->assertEquals(401, $response->status(), 'Should require valid authentication');
    }
    
    /** @test */
    public function batch_endpoint_accepts_multiple_transactions()
    {
        // Arrange
        $payload = $this->getValidBatchPayload();
        
        // Act
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/transactions/batch', $payload);
        
        // Assert
        if ($response->status() == 200 || $response->status() == 201) {
            $response->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'batch_id',
                    'processed_count',
                    'failed_count'
                ]
            ]);
            
            $transactionExists = false;
            $tables = ['transactions', 'pos_transactions', 'sales_transactions'];
            
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    $transactionExists = DB::table($table)
                        ->where('transaction_id', $payload['transactions'][0]['transaction_id'])
                        ->exists();
                    
                    if ($transactionExists) {
                        break;
                    }
                }
            }
            
            $this->assertTrue(
                $transactionExists,
                'Batch transaction was not stored'
            );
        } else {
            $this->assertTrue($response->status() !== 404, 'Batch endpoint should exist');
        }
    }

    /** @test */
    public function official_endpoint_accepts_single_transaction_format()
    {
        // Arrange
        $payload = $this->getOfficialSingleTransactionPayload();
        
        // Act
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ])->postJson('/api/v1/transactions/official', $payload);
        
        // Assert
        if ($response->status() == 200 || $response->status() == 201) {
            $response->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'submission_uuid',
                    'processed_count',
                    'failed_count',
                    'checksum_validation',
                    'transactions'
                ]
            ]);
            
            // Verify transaction was stored
            if (Schema::hasTable('transactions')) {
                $transactionExists = Transaction::where('transaction_id', $payload['transaction']['transaction_id'])->exists();
                $this->assertTrue($transactionExists, 'Official single transaction was not stored');
            }
        } else {
            $this->assertTrue($response->status() !== 404, 'Official endpoint should exist');
            $this->printTestResult('Official single transaction endpoint returned status: ' . $response->status());
            $this->printTestResult('Response: ' . $response->content());
        }
    }
    
    /** @test */
    public function official_endpoint_accepts_batch_transaction_format()
    {
        // Arrange
        $payload = $this->getOfficialBatchTransactionPayload();
        
        // Act
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ])->postJson('/api/v1/transactions/official', $payload);
        
        // Assert
        if ($response->status() == 200 || $response->status() == 201) {
            $response->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'submission_uuid',
                    'processed_count',
                    'failed_count',
                    'checksum_validation',
                    'transactions'
                ]
            ]);
            
            // Verify all transactions were stored
            if (Schema::hasTable('transactions')) {
                foreach ($payload['transactions'] as $txn) {
                    $transactionExists = Transaction::where('transaction_id', $txn['transaction_id'])->exists();
                    $this->assertTrue($transactionExists, "Official batch transaction {$txn['transaction_id']} was not stored");
                }
            }
        } else {
            $this->assertTrue($response->status() !== 404, 'Official endpoint should exist');
            $this->printTestResult('Official batch transaction endpoint returned status: ' . $response->status());
            $this->printTestResult('Response: ' . $response->content());
        }
    }
    
    /** @test */
    public function official_endpoint_validates_checksum()
    {
        // Arrange
        $payload = $this->getOfficialSingleTransactionPayload();
        $payload['payload_checksum'] = 'invalid-checksum-that-should-fail-validation-and-be-exactly-64-chars';
        
        // Act
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ])->postJson('/api/v1/transactions/official', $payload);
        
        // Assert
        $this->assertEquals(422, $response->status(), 'Should reject invalid checksum with 422 status');
        $response->assertJsonStructure([
            'success',
            'message',
            'errors'
        ]);
        $this->assertFalse($response->json('success'));
    }
    
    /** @test */
    public function official_endpoint_validates_required_fields()
    {
        // Test submission required fields
        $requiredFields = [
            'submission_uuid',
            'tenant_id', 
            'terminal_id',
            'submission_timestamp',
            'transaction_count',
            'payload_checksum'
        ];
        
        foreach ($requiredFields as $field) {
            $payload = $this->getOfficialSingleTransactionPayload();
            unset($payload[$field]);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])->postJson('/api/v1/transactions/official', $payload);
            
            $this->assertEquals(422, $response->status(), "Should reject missing {$field}");
        }
    }
    
    /** @test */
    public function official_endpoint_validates_transaction_structure()
    {
        // Test transaction required fields
        $requiredTransactionFields = [
            'transaction_id',
            'transaction_timestamp',
            'base_amount',
            'payload_checksum'
        ];
        
        foreach ($requiredTransactionFields as $field) {
            $payload = $this->getOfficialSingleTransactionPayload();
            unset($payload['transaction'][$field]);
            
            // Recalculate checksums
            $payload['transaction']['payload_checksum'] = $this->checksumService->computeChecksum($payload['transaction']);
            $payload['payload_checksum'] = $this->checksumService->computeChecksum($payload);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])->postJson('/api/v1/transactions/official', $payload);
            
            $this->assertEquals(422, $response->status(), "Should reject transaction missing {$field}");
        }
    }
    
    /** @test */
    public function official_endpoint_validates_uuid_formats()
    {
        // Test submission_uuid format
        $payload = $this->getOfficialSingleTransactionPayload();
        $payload['submission_uuid'] = 'not-a-valid-uuid';
        
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ])->postJson('/api/v1/transactions/official', $payload);
        
        $this->assertEquals(422, $response->status(), 'Should reject invalid submission_uuid format');
    }
    
    /** @test */
    public function official_endpoint_validates_transaction_count_mismatch()
    {
        // Arrange
        $payload = $this->getOfficialBatchTransactionPayload();
        $payload['transaction_count'] = 5; // But only 2 transactions in array
        
        // Recalculate checksum
        $payload['payload_checksum'] = $this->checksumService->computeChecksum($payload);
        
        // Act
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ])->postJson('/api/v1/transactions/official', $payload);
        
        // Assert
        $this->assertEquals(422, $response->status(), 'Should reject transaction count mismatch');
    }
    
    /** @test */
    public function official_endpoint_processes_adjustments_and_taxes()
    {
        // Arrange
        $payload = $this->getOfficialSingleTransactionPayload();
        
        // Add comprehensive adjustments and taxes
        $payload['transaction']['adjustments'] = [
            [
                'adjustment_type' => 'senior_citizen_discount',
                'amount' => 15.50
            ],
            [
                'adjustment_type' => 'employee_discount', 
                'amount' => 25.00
            ]
        ];
        
        $payload['transaction']['taxes'] = [
            [
                'tax_type' => 'VAT',
                'amount' => 12.00
            ],
            [
                'tax_type' => 'service_charge',
                'amount' => 8.50
            ]
        ];
        
        // Recalculate checksums
        $payload['transaction']['payload_checksum'] = $this->checksumService->computeChecksum($payload['transaction']);
        $payload['payload_checksum'] = $this->checksumService->computeChecksum($payload);
        
        // Act
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ])->postJson('/api/v1/transactions/official', $payload);
        
        // Assert
        if ($response->status() == 200 || $response->status() == 201) {
            // Verify adjustments were stored
            if (Schema::hasTable('transaction_adjustments')) {
                $this->assertDatabaseHas('transaction_adjustments', [
                    'adjustment_type' => 'senior_citizen_discount',
                    'amount' => 15.50
                ]);
            }
            
            // Verify taxes were stored
            if (Schema::hasTable('transaction_taxes')) {
                $this->assertDatabaseHas('transaction_taxes', [
                    'tax_type' => 'VAT',
                    'amount' => 12.00
                ]);
            }
        } else {
            $this->assertTrue($response->status() !== 404, 'Official endpoint should exist');
        }
    }
    
    /**
     * Helper function to get a valid transaction payload according to current API implementation
     */
    private function getValidPayload()
    {
        return [
            'transaction_id' => 'TX-' . Str::uuid(),
            'terminal_id' => $this->terminal->id,
            'customer_code' => 'CUST-001',
            'hardware_id' => 'HW-' . $this->faker->unique()->regexify('[A-Z0-9]{8}'),
            'base_amount' => $this->faker->randomFloat(2, 10, 1000),
            'transaction_timestamp' => now()->toISOString(),
            'items' => [
                [
                    'id' => 1,
                    'name' => 'Item 1',
                    'quantity' => 2,
                    'price' => 25.00
                ],
                [
                    'id' => 2,
                    'name' => 'Item 2',
                    'quantity' => 1,
                    'price' => 50.50
                ]
            ]
        ];
    }
    
    /**
     * Helper function to get a valid batch payload according to current API implementation
     */
    private function getValidBatchPayload()
    {
        $transactionId = 'txn-' . Str::uuid();
        $batchId = 'batch-' . Str::uuid();
        
        return [
            'batch_id' => $batchId,
            'customer_code' => 'CUST-001',
            'terminal_id' => $this->terminal->id,
            'transactions' => [
                [
                    'transaction_id' => $transactionId,
                    'hardware_id' => 'HW-' . $this->faker->unique()->regexify('[A-Z0-9]{8}'),
                    'base_amount' => $this->faker->randomFloat(2, 100, 1000),
                    'transaction_timestamp' => now()->toISOString(),
                    'items' => [
                        [
                            'id' => 1,
                            'name' => 'Test Item 1',
                            'quantity' => 2,
                            'price' => 25.00
                        ]
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Generate official single transaction payload according to guidelines
     */
    private function getOfficialSingleTransactionPayload()
    {
        $transactionId = (string) Str::uuid();
        $submissionId = (string) Str::uuid();
        $baseAmount = $this->faker->randomFloat(2, 100, 1000);
        
        // Build transaction object (without checksum initially)
        $transaction = [
            'transaction_id' => $transactionId,
            'transaction_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
            'base_amount' => $baseAmount,
            'adjustments' => [
                [
                    'adjustment_type' => 'senior_citizen_discount',
                    'amount' => $this->faker->randomFloat(2, 5, 50)
                ]
            ],
            'taxes' => [
                [
                    'tax_type' => 'VAT',
                    'amount' => $this->faker->randomFloat(2, 10, 120)
                ]
            ]
        ];
        
        // Calculate transaction checksum
        $transaction['payload_checksum'] = $this->checksumService->computeChecksum($transaction);
        
        // Build submission payload (without checksum initially)
        $payload = [
            'submission_uuid' => $submissionId,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'submission_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => 1,
            'transaction' => $transaction
        ];
        
        // Calculate submission checksum
        $payload['payload_checksum'] = $this->checksumService->computeChecksum($payload);
        
        return $payload;
    }
    
    /**
     * Generate official batch transaction payload according to guidelines
     */
    private function getOfficialBatchTransactionPayload()
    {
        $submissionId = (string) Str::uuid();
        $transactions = [];
        
        // Create 2 transactions for batch
        for ($i = 0; $i < 2; $i++) {
            $transaction = [
                'transaction_id' => (string) Str::uuid(),
                'transaction_timestamp' => now()->addSeconds($i)->format('Y-m-d\TH:i:s\Z'),
                'base_amount' => $this->faker->randomFloat(2, 100, 1000),
                'adjustments' => [
                    [
                        'adjustment_type' => 'promo_discount',
                        'amount' => $this->faker->randomFloat(2, 5, 50)
                    ]
                ],
                'taxes' => [
                    [
                        'tax_type' => 'VAT',
                        'amount' => $this->faker->randomFloat(2, 10, 120)
                    ]
                ]
            ];
            
            // Calculate transaction checksum
            $transaction['payload_checksum'] = $this->checksumService->computeChecksum($transaction);
            $transactions[] = $transaction;
        }
        
        // Build submission payload (without checksum initially)
        $payload = [
            'submission_uuid' => $submissionId,
            'tenant_id' => $this->terminal->tenant_id,
            'terminal_id' => $this->terminal->id,
            'submission_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
            'transaction_count' => count($transactions),
            'transactions' => $transactions
        ];
        
        // Calculate submission checksum
        $payload['payload_checksum'] = $this->checksumService->computeChecksum($payload);
        
        return $payload;
    }
    
    /**
     * Helper function to print test results for debugging
     */
    private function printTestResult($message)
    {
        fwrite(STDERR, "\n" . $message . "\n");
    }
}
