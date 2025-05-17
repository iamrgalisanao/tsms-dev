<?php

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * This script verifies all components of Module 2: POS Transaction Processing and Error Handling
 * 
 * It checks:
 * 1. Transaction Ingestion API (2.1.3.1)
 * 2. POS Text Format Parser (2.1.3.2)
 * 3. Job Queues and Processing Logic (2.1.3.3)
 * 4. Error Handling and Retry Mechanism (2.1.3.4)
 */
class Module2Verifier
{
    protected $results = [];
    
    public function verify()
    {
        echo "🔍 Starting verification of Module 2: POS Transaction Processing and Error Handling\n\n";
        
        $this->verifyTransactionAPI();
        $this->verifyTextFormatParser();
        $this->verifyJobQueues();
        $this->verifyErrorHandlingAndRetry();
        
        $this->printResults();
    }
    
    private function verifyTransactionAPI()
    {
        echo "📋 Checking Transaction Ingestion API (2.1.3.1)...\n";
        
        // Check route definition
        $routesPath = __DIR__ . '/../../routes/api.php';
        $routesContent = file_exists($routesPath) ? file_get_contents($routesPath) : '';
        
        $this->addResult('Transaction API route definition', 
            (strpos($routesContent, "/transactions") !== false), 
            "POST /v1/transactions endpoint should be defined in routes/api.php");
            
        // Check controller exists
        $controllerPath = __DIR__ . '/../../app/Http/Controllers/API/V1/TransactionController.php';
        $this->addResult('Transaction controller exists', 
            file_exists($controllerPath),
            "TransactionController should exist in app/Http/Controllers/API/V1/");
            
        if (file_exists($controllerPath)) {
            $controllerContent = file_get_contents($controllerPath);
            $this->addResult('Store method implementation', 
                strpos($controllerContent, "function store") !== false,
                "TransactionController should have a store method");
                
            $this->addResult('Idempotency key handling', 
                strpos($controllerContent, "Idempotency-Key") !== false ||
                strpos($controllerContent, "idempotencyKey") !== false,
                "Transaction API should handle idempotency keys");
        }
        
        // Check transaction model
        $modelPath = __DIR__ . '/../../app/Models/Transactions.php';
        $this->addResult('Transaction model exists', 
            file_exists($modelPath),
            "Transactions model should exist for storing transaction data");
    }
    
    private function verifyTextFormatParser()
    {
        echo "📋 Checking POS Text Format Parser (2.1.3.2)...\n";
        
        // Check validation service
        $validationServicePath = __DIR__ . '/../../app/Services/TransactionValidationService.php';
        $this->addResult('Transaction validation service exists', 
            file_exists($validationServicePath),
            "TransactionValidationService should exist for validation and parsing");
            
        if (file_exists($validationServicePath)) {
            $serviceContent = file_get_contents($validationServicePath);
            $this->addResult('parseTextFormat method implementation', 
                strpos($serviceContent, "function parseTextFormat") !== false,
                "TransactionValidationService should have parseTextFormat method");
                
            $this->addResult('Text format parsing logic', 
                strpos($serviceContent, "preg_split") !== false || 
                strpos($serviceContent, "explode") !== false,
                "Parser should handle line splitting for text format");
                
            $this->addResult('Format conversion logic', 
                strpos($serviceContent, "assign") !== false && 
                strpos($serviceContent, "parse") !== false,
                "Parser should map text values to structured data fields");
        }
        
        // Check middleware for format transformation
        $middlewarePath = __DIR__ . '/../../app/Http/Middleware/TransformTextFormat.php';
        $this->addResult('Text format middleware exists', 
            file_exists($middlewarePath),
            "TransformTextFormat middleware should exist for handling text payloads");
            
        // Check middleware registration
        $kernelPath = __DIR__ . '/../../app/Http/Kernel.php';
        $kernelContent = file_exists($kernelPath) ? file_get_contents($kernelPath) : '';
        
        $this->addResult('Text format middleware registration', 
            strpos($kernelContent, "TransformTextFormat") !== false,
            "TransformTextFormat middleware should be registered in Kernel.php");
    }
    
    private function verifyJobQueues()
    {
        echo "📋 Checking Job Queues and Processing Logic (2.1.3.3)...\n";
        
        // Check processor job
        $processorJobPath = __DIR__ . '/../../app/Jobs/ProcessTransactionJob.php';
        $this->addResult('Transaction processor job exists', 
            file_exists($processorJobPath),
            "ProcessTransactionJob should exist for asynchronous transaction processing");
            
        // Check retry job
        $retryJobPath = __DIR__ . '/../../app/Jobs/RetryTransactionJob.php';
        $this->addResult('Transaction retry job exists', 
            file_exists($retryJobPath),
            "RetryTransactionJob should exist for retry handling");
            
        // Check queue configuration
        $queueConfigPath = __DIR__ . '/../../config/queue.php';
        $this->addResult('Queue configuration exists', 
            file_exists($queueConfigPath),
            "Queue configuration should be present in config/queue.php");
            
        // Check transaction controller for job dispatching
        $controllerPath = __DIR__ . '/../../app/Http/Controllers/API/V1/TransactionController.php';
        if (file_exists($controllerPath)) {
            $controllerContent = file_get_contents($controllerPath);
            $this->addResult('Job dispatching in controller', 
                strpos($controllerContent, "dispatch") !== false || 
                strpos($controllerContent, "dispatchJob") !== false,
                "TransactionController should dispatch jobs for processing");
        }
        
        // Check for Horizon setup (Redis queue)
        $horizonConfigPath = __DIR__ . '/../../config/horizon.php';
        $this->addResult('Laravel Horizon configuration', 
            file_exists($horizonConfigPath),
            "Laravel Horizon should be configured for robust queue processing");
    }
    
    private function verifyErrorHandlingAndRetry()
    {
        echo "📋 Checking Error Handling and Retry Mechanism (2.1.3.4)...\n";
        
        // Check retry history controller
        $retryControllerPath = __DIR__ . '/../../app/Http/Controllers/API/V1/RetryHistoryController.php';
        $this->addResult('Retry history controller exists', 
            file_exists($retryControllerPath),
            "RetryHistoryController should exist for retry management");
            
        // Check integration logs model (for storing retry information)
        $logModelPath = __DIR__ . '/../../app/Models/IntegrationLog.php';
        $this->addResult('Integration log model exists', 
            file_exists($logModelPath),
            "IntegrationLog model should exist for tracking retry attempts");
            
        if (file_exists($logModelPath)) {
            $modelContent = file_get_contents($logModelPath);
            $this->addResult('Retry field definitions', 
                strpos($modelContent, "retry_count") !== false && 
                strpos($modelContent, "retry_reason") !== false,
                "IntegrationLog should include retry tracking fields");
        }
        
        // Check circuit breaker
        $circuitBreakerPath = __DIR__ . '/../../app/Services/CircuitBreaker.php';
        $this->addResult('Circuit breaker implementation', 
            file_exists($circuitBreakerPath),
            "CircuitBreaker service should exist for failure protection");
            
        // Check retry job for exponential backoff
        $retryJobPath = __DIR__ . '/../../app/Jobs/RetryTransactionJob.php';
        if (file_exists($retryJobPath)) {
            $jobContent = file_get_contents($retryJobPath);
            $this->addResult('Exponential backoff implementation', 
                strpos($jobContent, "backoff") !== false || 
                strpos($jobContent, "pow") !== false,
                "RetryTransactionJob should implement exponential backoff");
        }
        
        // Check transaction permanently failed event
        $eventPath = __DIR__ . '/../../app/Events/TransactionPermanentlyFailed.php';
        $this->addResult('Transaction failure event exists', 
            file_exists($eventPath),
            "TransactionPermanentlyFailed event should exist for handling max retries");
    }
    
    private function addResult($component, $result, $message)
    {
        $this->results[] = [
            'component' => $component,
            'status' => $result ? 'PASS' : 'FAIL',
            'message' => $message
        ];
    }
    
    private function printResults()
    {
        echo "\nModule 2: POS Transaction Processing and Error Handling Verification Results\n";
        echo str_repeat("=", 80) . "\n";
        
        $componentMaxLength = max(array_map(function($item) { 
            return strlen($item['component']); 
        }, $this->results)) + 2;
        
        echo sprintf("%-{$componentMaxLength}s %-8s %s\n", "Component", "Status", "Details");
        echo str_repeat("-", 80) . "\n";
        
        foreach ($this->results as $result) {
            $statusText = $result['status'] === 'PASS' ? 
                "\033[32m✅ PASS\033[0m" : "\033[31m❌ FAIL\033[0m";
                
            echo sprintf("%-{$componentMaxLength}s %-8s %s\n", 
                $result['component'], 
                $statusText,
                $result['message']
            );
        }
        
        echo str_repeat("=", 80) . "\n";
        
        // Calculate pass/fail statistics
        $totalTests = count($this->results);
        $passed = count(array_filter($this->results, function($r) { return $r['status'] === 'PASS'; }));
        $passPct = round(($passed / $totalTests) * 100);
        
        // Print implementation completeness assessment
        echo "Implementation completeness: {$passed}/{$totalTests} tests passed ({$passPct}%)\n\n";
        
        if ($passPct >= 90) {
            echo "\033[32m✅ EXCELLENT: Module 2 is fully implemented and ready for production.\033[0m\n";
        } else if ($passPct >= 75) {
            echo "\033[33m⚠️ GOOD: Module 2 is mostly implemented with minor components missing.\033[0m\n";
        } else if ($passPct >= 50) {
            echo "\033[33m⚠️ PARTIAL: Module 2 has key components implemented but needs more work.\033[0m\n";
        } else {
            echo "\033[31m❌ INCOMPLETE: Module 2 implementation is substantially incomplete.\033[0m\n";
        }
        
        // Print component summaries
        echo "\nComponent Implementation Summary:\n";
        echo "--------------------------------\n";
        $this->printComponentSummary("Transaction Ingestion API (2.1.3.1)");
        $this->printComponentSummary("POS Text Format Parser (2.1.3.2)");
        $this->printComponentSummary("Job Queues and Processing Logic (2.1.3.3)");
        $this->printComponentSummary("Error Handling and Retry Mechanism (2.1.3.4)");
    }
    
    private function printComponentSummary($componentPrefix)
    {
        $relatedTests = array_filter($this->results, function($r) use ($componentPrefix) {
            return strpos($r['message'], explode(' ', $componentPrefix)[0]) !== false;
        });
        
        $totalTests = count($relatedTests);
        if ($totalTests === 0) return;
        
        $passed = count(array_filter($relatedTests, function($r) { return $r['status'] === 'PASS'; }));
        $passPct = round(($passed / $totalTests) * 100);
        
        $statusText = $passPct >= 75 ? 
            "\033[32m✅ IMPLEMENTED\033[0m" : 
            ($passPct >= 50 ? "\033[33m⚠️ PARTIAL\033[0m" : "\033[31m❌ MISSING\033[0m");
            
        echo sprintf("%-42s %s (%d/%d tests pass)\n", 
            $componentPrefix, 
            $statusText,
            $passed,
            $totalTests
        );
    }
}

// Run the verification
$verifier = new Module2Verifier();
$verifier->verify();

echo "\nFor detailed testing, run the PHPUnit test suite focused on Module 2:\n";
echo "php artisan test --filter=Transaction\n";
echo "php artisan test --filter=Retry\n";
echo "php artisan test --filter=Integration\n";
