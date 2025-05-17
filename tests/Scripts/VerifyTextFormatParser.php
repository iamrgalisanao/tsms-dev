<?php

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * This script verifies the existence of the POS text format parser
 * which should be implemented as part of the middleware's validation
 * and transformation logic.
 */

class ParserVerifier
{
    protected $results = [];
    
    public function verify()
    {
        $this->checkTransformationService();
        $this->checkMiddleware();
        $this->checkValidationService();
        $this->checkUsageInControllers();
        $this->printResults();
    }
    
    private function checkTransformationService()
    {
        $servicePaths = [
            __DIR__ . '/../../app/Services/FormatTransformationService.php',
            __DIR__ . '/../../app/Services/PosFormatParser.php',
            __DIR__ . '/../../app/Services/TextFormatParser.php',
            __DIR__ . '/../../app/Services/TransactionTransformationService.php'
        ];
        
        $serviceFound = false;
        $serviceHasParseMethod = false;
        
        foreach ($servicePaths as $path) {
            if (file_exists($path)) {
                $serviceFound = true;
                $content = file_get_contents($path);
                
                // Check if the service has a parse method for text formats
                if (preg_match('/function\s+(?:parse|convertTextFormat|transformTextFormat|parseTextPayload)\s*\(/', $content)) {
                    $serviceHasParseMethod = true;
                    $this->addResult('Text format parser service', 'Found at ' . basename($path), 'PASS');
                    // Check for common text parsing patterns
                    if (preg_match('/explode|str_split|preg_split|substring/', $content)) {
                        $this->addResult('Text parsing functionality', 'String parsing methods found', 'PASS');
                    } else {
                        $this->addResult('Text parsing functionality', 'No common string parsing methods found', 'WARNING');
                    }
                    break;
                }
            }
        }
        
        if (!$serviceFound) {
            $this->addResult('Text format parser service', 'No dedicated parser service found', 'WARNING');
        } else if (!$serviceHasParseMethod) {
            $this->addResult('Text format parser service', 'Service exists but no parsing method found', 'WARNING');
        }
    }
    
    private function checkMiddleware()
    {
        $middlewarePaths = [
            __DIR__ . '/../../app/Http/Middleware/TransformPosFormat.php',
            __DIR__ . '/../../app/Http/Middleware/ParseTextFormat.php',
            __DIR__ . '/../../app/Http/Middleware/FormatTransformation.php'
        ];
        
        $middlewareFound = false;
        
        foreach ($middlewarePaths as $path) {
            if (file_exists($path)) {
                $middlewareFound = true;
                $content = file_get_contents($path);
                
                // Check if the middleware handles text format transformations
                if (strpos($content, 'text/plain') !== false || 
                    preg_match('/Content-Type.*?text/', $content) ||
                    preg_match('/format.*?text/', $content)) {
                    $this->addResult('Text format middleware', 'Found at ' . basename($path) . ' with text handling', 'PASS');
                    break;
                } else {
                    $this->addResult('Text format middleware', 'Found ' . basename($path) . ' but no text handling', 'WARNING');
                }
            }
        }
        
        if (!$middlewareFound) {
            $this->addResult('Text format middleware', 'No transformation middleware found', 'FAIL');
        }
        
        // Check if middleware is registered
        $kernelPath = __DIR__ . '/../../app/Http/Kernel.php';
        if (file_exists($kernelPath)) {
            $kernelContent = file_get_contents($kernelPath);
            $middlewareRegistered = false;
            
            foreach (['TransformPosFormat', 'ParseTextFormat', 'FormatTransformation'] as $middleware) {
                if (strpos($kernelContent, $middleware) !== false) {
                    $middlewareRegistered = true;
                    $this->addResult('Middleware registration', 'Format transformation middleware registered in Kernel', 'PASS');
                    break;
                }
            }
            
            if (!$middlewareRegistered) {
                $this->addResult('Middleware registration', 'No format transformation middleware registered in Kernel', 'WARNING');
            }
        }
    }
    
    private function checkValidationService()
    {
        // Look at TransactionValidationService for text format handling
        $validationPath = __DIR__ . '/../../app/Services/TransactionValidationService.php';
        
        if (file_exists($validationPath)) {
            $content = file_get_contents($validationPath);
            
            // Look for text format validation
            if (strpos($content, 'text/plain') !== false || 
                preg_match('/Content-Type.*?text/', $content) ||
                preg_match('/parseTextFormat|convertTextFormat|validateTextFormat/', $content)) {
                $this->addResult('Validation service text handling', 'TransactionValidationService handles text formats', 'PASS');
            } else {
                // Check if the service has parsing logic embedded
                if (preg_match('/explode|str_split|preg_split|substring/', $content)) {
                    $this->addResult('Validation service text handling', 'TransactionValidationService contains string parsing logic', 'PASS');
                } else {
                    $this->addResult('Validation service text handling', 'No explicit text format handling in TransactionValidationService', 'WARNING');
                }
            }
        } else {
            $this->addResult('Validation service', 'TransactionValidationService not found', 'WARNING');
        }
    }
    
    private function checkUsageInControllers()
    {
        $controllerPath = __DIR__ . '/../../app/Http/Controllers/API/V1/TransactionController.php';
        
        if (file_exists($controllerPath)) {
            $content = file_get_contents($controllerPath);
            
            // Check if controller handles text format
            if (strpos($content, 'text/plain') !== false || 
                preg_match('/Content-Type.*?text/', $content) ||
                preg_match('/parseTextFormat|convertTextFormat|formatParser/', $content)) {
                $this->addResult('Controller text format handling', 'TransactionController handles text format', 'PASS');
            } else {
                // If controller injects a service that might handle it
                if (preg_match('/FormatTransformation|TextFormatParser|PosFormatParser/', $content)) {
                    $this->addResult('Controller text format handling', 'TransactionController uses parser service', 'PASS');
                } else {
                    $this->addResult('Controller text format handling', 'No explicit text format handling in controller', 'WARNING');
                }
            }
        } else {
            $this->addResult('Controller', 'TransactionController not found', 'FAIL');
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
        echo "\nPOS Text Format Parser Verification Results:\n";
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
        
        $warningCount = count(array_filter($this->results, function($item) {
            return $item['result'] === 'WARNING';
        }));
        
        if ($failCount === 0 && $passCount > 0) {
            echo "VERIFICATION RESULT: PASS - POS text format parser is implemented.\n";
        } elseif ($failCount > 0) {
            echo "VERIFICATION RESULT: FAIL - POS text format parser is missing key components.\n";
        } else if ($warningCount > 0 && $passCount > 0) {
            echo "VERIFICATION RESULT: PARTIAL - Some text format parsing capabilities exist but may not be complete.\n";
        } else {
            echo "VERIFICATION RESULT: INCONCLUSIVE - Could not fully verify implementation.\n";
        }
        
        // Additional notes
        echo "\nNotes:\n";
        echo "- The POS text format parser should convert text-based POS formats to JSON\n";
        echo "- This functionality is typically implemented in middleware or a dedicated service\n";
        echo "- If using the TransactionValidationService, ensure it can handle both JSON and text formats\n";
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
$verifier = new ParserVerifier();
$verifier->verify();
