<?php

/**
 * TSMS UUID Validation and Fix Utility
 * 
 * This utility validates and fixes malformed UUIDs in TSMS payloads.
 * Specifically handles cases where UUIDs contain invalid hex characters.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ramsey\Uuid\Uuid;

class UuidValidator
{
    /**
     * Validate and fix UUIDs in a payload
     */
    public function validateAndFixPayload(array $payload): array
    {
        $result = [
            'valid' => true,
            'errors' => [],
            'fixes_applied' => [],
            'corrected_payload' => $payload
        ];
        
        // Check submission_uuid
        if (isset($payload['submission_uuid'])) {
            $fix = $this->validateAndFixUuid($payload['submission_uuid'], 'submission_uuid');
            if ($fix['fixed']) {
                $result['corrected_payload']['submission_uuid'] = $fix['corrected_uuid'];
                $result['fixes_applied'][] = $fix;
                $result['valid'] = false;
            } elseif (!$fix['valid']) {
                $result['errors'][] = "Invalid submission_uuid: " . $fix['error'];
                $result['valid'] = false;
            }
        }
        
        // Check single transaction
        if (isset($payload['transaction']['transaction_id'])) {
            $fix = $this->validateAndFixUuid($payload['transaction']['transaction_id'], 'transaction.transaction_id');
            if ($fix['fixed']) {
                $result['corrected_payload']['transaction']['transaction_id'] = $fix['corrected_uuid'];
                $result['fixes_applied'][] = $fix;
                $result['valid'] = false;
            } elseif (!$fix['valid']) {
                $result['errors'][] = "Invalid transaction.transaction_id: " . $fix['error'];
                $result['valid'] = false;
            }
        }
        
        // Check batch transactions
        if (isset($payload['transactions'])) {
            foreach ($payload['transactions'] as $index => $transaction) {
                if (isset($transaction['transaction_id'])) {
                    $fix = $this->validateAndFixUuid($transaction['transaction_id'], "transactions[{$index}].transaction_id");
                    if ($fix['fixed']) {
                        $result['corrected_payload']['transactions'][$index]['transaction_id'] = $fix['corrected_uuid'];
                        $result['fixes_applied'][] = $fix;
                        $result['valid'] = false;
                    } elseif (!$fix['valid']) {
                        $result['errors'][] = "Invalid transactions[{$index}].transaction_id: " . $fix['error'];
                        $result['valid'] = false;
                    }
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Validate and attempt to fix a single UUID
     */
    private function validateAndFixUuid(string $uuid, string $fieldName): array
    {
        $result = [
            'field' => $fieldName,
            'original_uuid' => $uuid,
            'valid' => false,
            'fixed' => false,
            'corrected_uuid' => null,
            'error' => null
        ];
        
        // Check if already valid
        if (Uuid::isValid($uuid)) {
            $result['valid'] = true;
            return $result;
        }
        
        // Attempt common fixes
        $fixes = [
            // Replace common typos in hex characters
            'r' => 'f',  // r -> f (common typo)
            'g' => '6',  // g -> 6 (common typo)  
            'h' => 'b',  // h -> b (common typo)
            'i' => '1',  // i -> 1 (common typo)
            'l' => '1',  // l -> 1 (common typo)
            'o' => '0',  // o -> 0 (common typo)
            's' => '5',  // s -> 5 (common typo)
            't' => '7',  // t -> 7 (common typo)
            'z' => '2',  // z -> 2 (common typo)
        ];
        
        $corrected = strtolower($uuid);
        $fixesApplied = [];
        
        foreach ($fixes as $invalid => $valid) {
            if (strpos($corrected, $invalid) !== false) {
                $corrected = str_replace($invalid, $valid, $corrected);
                $fixesApplied[] = "$invalid -> $valid";
            }
        }
        
        // Check if the corrected version is valid
        if (Uuid::isValid($corrected)) {
            $result['valid'] = true;
            $result['fixed'] = true;
            $result['corrected_uuid'] = $corrected;
            $result['fixes_applied'] = $fixesApplied;
            return $result;
        }
        
        // If still invalid, provide error details
        $result['error'] = "UUID format is invalid even after attempted fixes: " . implode(', ', $fixesApplied);
        return $result;
    }
    
    /**
     * Interactive CLI validation
     */
    public function runInteractive()
    {
        echo "=== TSMS UUID Validation and Fix Utility ===\n\n";
        echo "This utility validates and fixes malformed UUIDs in TSMS payloads.\n";
        echo "Enter your payload JSON (end with empty line):\n\n";
        
        $input = '';
        while (($line = fgets(STDIN)) !== false) {
            if (trim($line) === '') break;
            $input .= $line;
        }
        
        try {
            $payload = json_decode($input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON: ' . json_last_error_msg());
            }
            
            $result = $this->validateAndFixPayload($payload);
            
            if ($result['valid'] && empty($result['fixes_applied'])) {
                echo "✅ SUCCESS: All UUIDs are valid!\n";
                return;
            }
            
            if (!empty($result['errors'])) {
                echo "❌ VALIDATION ERRORS:\n";
                foreach ($result['errors'] as $error) {
                    echo "  - " . $error . "\n";
                }
                echo "\n";
            }
            
            if (!empty($result['fixes_applied'])) {
                echo "🔧 FIXES APPLIED:\n";
                foreach ($result['fixes_applied'] as $fix) {
                    echo "  - {$fix['field']}: {$fix['original_uuid']} → {$fix['corrected_uuid']}\n";
                    echo "    Character fixes: " . implode(', ', $fix['fixes_applied']) . "\n";
                }
                echo "\n";
                
                echo "✅ CORRECTED PAYLOAD:\n";
                echo json_encode($result['corrected_payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                echo "\n\nCopy the corrected payload above for your API request.\n";
            }
            
        } catch (Exception $e) {
            echo "❌ ERROR: " . $e->getMessage() . "\n";
        }
    }
}

// Run the interactive validator if this script is executed directly
if (basename($_SERVER['PHP_SELF']) === 'fix-uuid.php') {
    $validator = new UuidValidator();
    $validator->runInteractive();
}
