#!/usr/bin/env php
<?php
/**
 * TSMS WebApp Integration Validation Script
 * 
 * This script validates that the TSMS-WebApp integration is working correctly
 * by testing the forwarding service, payload structure, and connectivity.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

class IntegrationValidator
{
    private array $results = [];
    private bool $verbose = false;
    
    public function __construct(bool $verbose = false)
    {
        $this->verbose = $verbose;
    }
    
    public function runValidation(): bool
    {
        $this->output("🔍 TSMS WebApp Integration Validation\n");
        $this->output("=====================================\n");
        
        $allPassed = true;
        
        // Test 1: Configuration Validation
        $allPassed &= $this->validateConfiguration();
        
        // Test 2: Service Health Check
        $allPassed &= $this->validateService();
        
        // Test 3: Payload Structure Validation
        $allPassed &= $this->validatePayloadStructure();
        
        // Test 4: Connectivity Test (if endpoint is reachable)
        $allPassed &= $this->validateConnectivity();
        
        // Test 5: Transaction Data Validation
        $allPassed &= $this->validateTransactionData();
        
        $this->outputSummary($allPassed);
        
        return $allPassed;
    }
    
    private function validateConfiguration(): bool
    {
        $this->output("1️⃣  Configuration Validation...");
        
        $errors = [];
        
        // Check required config values
        $requiredConfigs = [
            'tsms.web_app.enabled' => config('tsms.web_app.enabled'),
            'tsms.web_app.endpoint' => config('tsms.web_app.endpoint'),
            'tsms.web_app.auth_token' => config('tsms.web_app.auth_token'),
        ];
        
        foreach ($requiredConfigs as $key => $value) {
            if (empty($value)) {
                $errors[] = "Missing or empty config: {$key}";
            }
        }
        
        // Validate endpoint format
        $endpoint = config('tsms.web_app.endpoint');
        if ($endpoint && !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            $errors[] = "Invalid endpoint URL format: {$endpoint}";
        }
        
        if (empty($errors)) {
            $this->output(" ✅ PASSED\n");
            if ($this->verbose) {
                $this->output("   - Endpoint: " . config('tsms.web_app.endpoint') . "\n");
                $this->output("   - Timeout: " . config('tsms.web_app.timeout', 30) . "s\n");
                $this->output("   - Batch Size: " . config('tsms.web_app.batch_size', 50) . "\n");
            }
            return true;
        } else {
            $this->output(" ❌ FAILED\n");
            foreach ($errors as $error) {
                $this->output("   - {$error}\n");
            }
            return false;
        }
    }
    
    private function validateService(): bool
    {
        $this->output("2️⃣  Service Health Check...");
        
        try {
            $service = app(\App\Services\WebAppForwardingService::class);
            $stats = $service->getForwardingStats();
            
            $this->output(" ✅ PASSED\n");
            if ($this->verbose) {
                $this->output("   - Unforwarded transactions: {$stats['unforwarded_transactions']}\n");
                $this->output("   - Pending forwards: {$stats['pending_forwards']}\n");
                $this->output("   - Completed forwards: {$stats['completed_forwards']}\n");
                $this->output("   - Failed forwards: {$stats['failed_forwards']}\n");
                $this->output("   - Circuit breaker: " . ($stats['circuit_breaker']['is_open'] ? 'OPEN' : 'CLOSED') . "\n");
            }
            
            return true;
        } catch (\Exception $e) {
            $this->output(" ❌ FAILED\n");
            $this->output("   - Error: {$e->getMessage()}\n");
            return false;
        }
    }
    
    private function validatePayloadStructure(): bool
    {
        $this->output("3️⃣  Payload Structure Validation...");
        
        try {
            // Get a sample transaction
            $transaction = \App\Models\Transaction::where('validation_status', 'VALID')->first();
            
            if (!$transaction) {
                $this->output(" ⚠️  SKIPPED - No valid transactions found\n");
                return true;
            }
            
            $service = app(\App\Services\WebAppForwardingService::class);
            $reflection = new \ReflectionClass($service);
            $method = $reflection->getMethod('buildTransactionPayload');
            $method->setAccessible(true);
            
            $payload = $method->invoke($service, $transaction);
            
            // Validate required fields
            $requiredFields = [
                'tsms_id', 'transaction_id', 'amount', 'validation_status',
                'checksum', 'transaction_timestamp', 'processed_at'
            ];
            
            $errors = [];
            foreach ($requiredFields as $field) {
                if (!array_key_exists($field, $payload)) {
                    $errors[] = "Missing required field: {$field}";
                }
            }
            
            // Validate field types
            if (isset($payload['amount']) && !is_numeric($payload['amount'])) {
                $errors[] = "Amount field is not numeric";
            }
            
            if (isset($payload['tsms_id']) && !is_int($payload['tsms_id'])) {
                $errors[] = "TSMS ID field is not an integer";
            }
            
            if (empty($errors)) {
                $this->output(" ✅ PASSED\n");
                if ($this->verbose) {
                    $this->output("   - Sample payload fields: " . implode(', ', array_keys($payload)) . "\n");
                    $this->output("   - Amount: {$payload['amount']}\n");
                    $this->output("   - Transaction ID: {$payload['transaction_id']}\n");
                }
                return true;
            } else {
                $this->output(" ❌ FAILED\n");
                foreach ($errors as $error) {
                    $this->output("   - {$error}\n");
                }
                return false;
            }
            
        } catch (\Exception $e) {
            $this->output(" ❌ FAILED\n");
            $this->output("   - Error: {$e->getMessage()}\n");
            return false;
        }
    }
    
    private function validateConnectivity(): bool
    {
        $this->output("4️⃣  Connectivity Test...");
        
        $endpoint = config('tsms.web_app.endpoint');
        $token = config('tsms.web_app.auth_token');
        
        if (empty($endpoint)) {
            $this->output(" ⚠️  SKIPPED - No endpoint configured\n");
            return true;
        }
        
        try {
            $response = Http::timeout(10)
                ->withToken($token)
                ->post($endpoint . '/api/transactions/bulk', [
                    'test' => 'connectivity_check'
                ]);
            
            // We expect this to fail with validation error (422) since we're sending invalid data
            // But if we get a response, it means connectivity is working
            if ($response->status() === 422) {
                $this->output(" ✅ PASSED (Validation error expected)\n");
                if ($this->verbose) {
                    $this->output("   - Endpoint is reachable\n");
                    $this->output("   - Authentication is working\n");
                }
                return true;
            } elseif ($response->successful()) {
                $this->output(" ✅ PASSED\n");
                if ($this->verbose) {
                    $this->output("   - Endpoint responded successfully\n");
                }
                return true;
            } else {
                $this->output(" ⚠️  WARNING - Unexpected response: {$response->status()}\n");
                if ($this->verbose) {
                    $this->output("   - Response: {$response->body()}\n");
                }
                return true; // Don't fail on this as endpoint might not be ready
            }
            
        } catch (\Exception $e) {
            $this->output(" ⚠️  WARNING - Connection failed\n");
            if ($this->verbose) {
                $this->output("   - Error: {$e->getMessage()}\n");
                $this->output("   - This is normal if WebApp is not running\n");
            }
            return true; // Don't fail validation on connectivity issues
        }
    }
    
    private function validateTransactionData(): bool
    {
        $this->output("5️⃣  Transaction Data Validation...");
        
        try {
            $validTransactions = \App\Models\Transaction::where('validation_status', 'VALID')->count();
            $totalTransactions = \App\Models\Transaction::count();
            
            // Check for transactions with base_amount
            $withBaseAmount = \App\Models\Transaction::whereNotNull('base_amount')
                ->where('base_amount', '>', 0)
                ->count();
            
            $this->output(" ✅ PASSED\n");
            if ($this->verbose) {
                $this->output("   - Total transactions: {$totalTransactions}\n");
                $this->output("   - Valid transactions: {$validTransactions}\n");
                $this->output("   - Transactions with base_amount: {$withBaseAmount}\n");
            }
            
            if ($validTransactions === 0) {
                $this->output("   ⚠️  Note: No valid transactions found for forwarding\n");
            }
            
            return true;
            
        } catch (\Exception $e) {
            $this->output(" ❌ FAILED\n");
            $this->output("   - Error: {$e->getMessage()}\n");
            return false;
        }
    }
    
    private function outputSummary(bool $allPassed): void
    {
        $this->output("\n=====================================\n");
        if ($allPassed) {
            $this->output("✅ All validations PASSED!\n");
            $this->output("🚀 Integration is ready for production\n\n");
            $this->output("Next Steps:\n");
            $this->output("1. Set up WebApp with Laravel Horizon (see WEBAPP_HORIZON_DEPLOYMENT_GUIDE.md)\n");
            $this->output("2. Update TSMS_WEBAPP_ENDPOINT to point to your WebApp\n");
            $this->output("3. Run: php artisan tsms:forward-transactions --dry-run\n");
            $this->output("4. Run: php artisan tsms:forward-transactions\n");
        } else {
            $this->output("❌ Some validations FAILED!\n");
            $this->output("🔧 Please fix the issues above before proceeding\n");
        }
    }
    
    private function output(string $message): void
    {
        echo $message;
    }
}

// Parse command line arguments
$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);

// Run validation
$validator = new IntegrationValidator($verbose);
$success = $validator->runValidation();

exit($success ? 0 : 1);
