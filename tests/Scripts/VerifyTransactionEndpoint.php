<?php

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * This script verifies the existence of the transaction ingestion API components
 */

class EndpointVerifier
{
    protected $results = [];
    protected $controllerPath;

    public function verify()
    {
        $this->checkRouteDefinition();
        $this->checkControllerExists();
        $this->checkMethodImplementation();
        $this->printResults();
    }
    
    private function checkRouteDefinition()
    {
        // Check both main API routes and dedicated transaction routes
        $routesPaths = [
            __DIR__ . '/../../routes/api.php',
            __DIR__ . '/../../routes/transaction.php',
        ];
        
        $hasTransactionRoute = false;
        
        foreach ($routesPaths as $routesPath) {
            if (file_exists($routesPath)) {
                $routesContent = file_get_contents($routesPath);
                
                // Check for transaction route
                if (preg_match('/Route::post\([\'"].*?\/transactions[\'"]/', $routesContent) || 
                    preg_match('/->post\([\'"].*?\/transactions[\'"]/', $routesContent)) {
                    $hasTransactionRoute = true;
                    $this->addResult('Transaction route definition', 'Found in ' . basename($routesPath), 'PASS');
                    break;
                }
            }
        }
        
        if (!$hasTransactionRoute) {
            $this->addResult('Transaction route definition', 'Not found in expected locations', 'FAIL');
        }
    }
    
    private function checkControllerExists()
    {
        $controllerPaths = [
            __DIR__ . '/../../app/Http/Controllers/API/V1/TransactionController.php',
            __DIR__ . '/../../app/Http/Controllers/API/TransactionController.php',
            __DIR__ . '/../../app/Http/Controllers/TransactionController.php'
        ];
        
        $controllerFound = false;
        
        foreach ($controllerPaths as $path) {
            if (file_exists($path)) {
                $controllerFound = true;
                $this->addResult('Transaction controller', 'Found at ' . basename(dirname($path)) . '/' . basename($path), 'PASS');
                $this->controllerPath = $path;
                break;
            }
        }
        
        if (!$controllerFound) {
            $this->addResult('Transaction controller', 'Not found in expected locations', 'FAIL');
        }
    }
    
    private function checkMethodImplementation()
    {
        if (!isset($this->controllerPath) || !file_exists($this->controllerPath)) {
            $this->addResult('Store/create method', 'Cannot check - controller not found', 'FAIL');
            return;
        }
        
        $controllerContent = file_get_contents($this->controllerPath);
        
        // Check for store or create method
        $hasStoreMethod = preg_match('/function\s+store\s*\(/', $controllerContent);
        $hasCreateMethod = preg_match('/function\s+create\s*\(/', $controllerContent);
        
        if ($hasStoreMethod || $hasCreateMethod) {
            $this->addResult('Store/create method', 'Found', 'PASS');
            
            // Check for validation
            $hasValidation = preg_match('/validate\s*\(/', $controllerContent) || 
                            preg_match('/Validator::make\s*\(/', $controllerContent);
            
            if ($hasValidation) {
                $this->addResult('Request validation', 'Found', 'PASS');
            } else {
                $this->addResult('Request validation', 'Not found or using custom validation', 'WARNING');
            }
            
            // Check for database storage
            $hasDbStorage = preg_match('/::create\s*\(/', $controllerContent) || 
                           preg_match('/->save\s*\(/', $controllerContent) || 
                           preg_match('/->insert\s*\(/', $controllerContent);
            
            if ($hasDbStorage) {
                $this->addResult('Database storage', 'Found', 'PASS');
            } else {
                $this->addResult('Database storage', 'Not found or using custom storage mechanism', 'WARNING');
            }
        } else {
            $this->addResult('Store/create method', 'Not found', 'FAIL');
        }
    }
    
    private function addResult($component, $status, $result)
    {
        $this->results[] = [
            'component' => $component,
            'status' => $status,
            'result' => $result
        ];
    }
    
    private function printResults()
    {
        echo "\nTransaction Ingestion API Verification Results:\n";
        echo str_repeat('-', 80) . "\n";
        echo sprintf("%-30s %-35s %-10s\n", 'Component', 'Status', 'Result');
        echo str_repeat('-', 80) . "\n";
        
        foreach ($this->results as $result) {
            echo sprintf(
                "%-30s %-35s %-10s\n", 
                $result['component'], 
                $result['status'], 
                $this->colorResult($result['result'])
            );
        }
        
        echo str_repeat('-', 80) . "\n";
        
        // Overall assessment
        $passCount = count(array_filter($this->results, function($item) {
            return $item['result'] === 'PASS';
        }));
        
        $failCount = count(array_filter($this->results, function($item) {
            return $item['result'] === 'FAIL';
        }));
        
        if ($failCount === 0 && $passCount > 0) {
            echo "VERIFICATION RESULT: PASS - Transaction Ingestion API is implemented.\n";
        } elseif ($failCount > 0) {
            echo "VERIFICATION RESULT: FAIL - Transaction Ingestion API is missing key components.\n";
        } else {
            echo "VERIFICATION RESULT: INCONCLUSIVE - Could not fully verify implementation.\n";
        }
    }
    
    private function colorResult($result)
    {
        switch ($result) {
            case 'PASS':
                return "\033[32m$result\033[0m"; // Green
            case 'FAIL':
                return "\033[31m$result\033[0m"; // Red
            case 'WARNING':
                return "\033[33m$result\033[0m"; // Yellow
            default:
                return $result;
        }
    }
}

// Run the verification
$verifier = new EndpointVerifier();
$verifier->verify();