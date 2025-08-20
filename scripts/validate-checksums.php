<?php

/**
 * TSMS Checksum Validation Utility
 * 
 * This utility validates and fixes payload checksums for TSMS API submissions.
 * Usage: php scripts/validate-checksums.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\PayloadChecksumService;
use Ramsey\Uuid\Uuid;

class ChecksumValidator
{
    private PayloadChecksumService $service;
    
    public function __construct()
    {
        $this->service = new PayloadChecksumService();
    }
    
    /**
     * Validate and optionally fix checksums in a payload
     */
    public function validatePayload(array $payload, bool $fixErrors = false): array
    {
        $result = [
            'valid' => false,
            'errors' => [],
            'corrected_payload' => null,
            'checksum_info' => [],
            'uuid_fixes' => []
        ];
        
        // First, validate and fix UUIDs if needed
        $uuidValidation = $this->validateAndFixUUIDs($payload);
        if (!$uuidValidation['valid']) {
            $result['errors'] = array_merge($result['errors'], $uuidValidation['errors']);
            if ($fixErrors && !empty($uuidValidation['fixes_applied'])) {
                $payload = $uuidValidation['corrected_payload'];
                $result['uuid_fixes'] = $uuidValidation['fixes_applied'];
            }
        }
        
        // Validate existing checksums
        $validation = $this->service->validateSubmissionChecksums($payload);
        
        if ($validation['valid'] && $uuidValidation['valid']) {
            $result['valid'] = true;
            $result['corrected_payload'] = $payload;
            return $result;
        }
        
        $result['errors'] = array_merge($result['errors'], $validation['errors']);
        
        if ($fixErrors) {
            $correctedPayload = $this->fixChecksums($payload);
            $result['corrected_payload'] = $correctedPayload;
            
            // Re-validate the corrected payload
            $revalidation = $this->service->validateSubmissionChecksums($correctedPayload);
            $finalUuidCheck = $this->validateAndFixUUIDs($correctedPayload);
            $result['valid'] = $revalidation['valid'] && $finalUuidCheck['valid'];
        }
        
        return $result;
    }
    
    /**
     * Fix checksums in a payload
     */
    private function fixChecksums(array $payload): array
    {
        $fixed = $payload;
        
        // Handle single transaction
        if (isset($fixed['transaction'])) {
            $transaction = $fixed['transaction'];
            unset($transaction['payload_checksum']);
            $fixed['transaction']['payload_checksum'] = $this->service->computeChecksum($transaction);
        }
        
        // Handle batch transactions
        if (isset($fixed['transactions'])) {
            foreach ($fixed['transactions'] as $index => $transaction) {
                $txnCopy = $transaction;
                unset($txnCopy['payload_checksum']);
                $fixed['transactions'][$index]['payload_checksum'] = $this->service->computeChecksum($txnCopy);
            }
        }
        
        // Fix submission-level checksum
        $submission = $fixed;
        unset($submission['payload_checksum']);
        $fixed['payload_checksum'] = $this->service->computeChecksum($submission);
        
        return $fixed;
    }
    
    /**
     * Validate and fix UUIDs in a payload
     */
    private function validateAndFixUUIDs(array $payload): array
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
                $result['errors'][] = "Invalid submission_uuid format";
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
                $result['errors'][] = "Invalid transaction.transaction_id format";
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
                        $result['errors'][] = "Invalid transactions[{$index}].transaction_id format";
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
            'fixes_applied' => []
        ];
        
        // Check if already valid
        if (Uuid::isValid($uuid)) {
            $result['valid'] = true;
            return $result;
        }
        
        // Attempt common fixes for invalid hex characters
        $fixes = [
            'r' => 'f',  // r -> f (most common typo)
            'g' => '6',  // g -> 6 
            'h' => 'b',  // h -> b 
            'i' => '1',  // i -> 1 
            'l' => '1',  // l -> 1 
            'o' => '0',  // o -> 0 
            's' => '5',  // s -> 5 
            't' => '7',  // t -> 7 
            'z' => '2',  // z -> 2 
        ];
        
        $corrected = strtolower($uuid);
        $fixesApplied = [];
        
        foreach ($fixes as $invalid => $valid) {
            if (strpos($corrected, $invalid) !== false) {
                $corrected = str_replace($invalid, $valid, $corrected);
                $fixesApplied[] = "$invalid → $valid";
            }
        }
        
        // Check if the corrected version is valid
        if (Uuid::isValid($corrected)) {
            $result['valid'] = true;
            $result['fixed'] = true;
            $result['corrected_uuid'] = $corrected;
            $result['fixes_applied'] = $fixesApplied;
        }
        
        return $result;
    }
    
    /**
     * Interactive CLI validation
     */
    public function runInteractive()
    {
        echo "=== TSMS Payload Validation Utility ===\n\n";
        echo "This utility validates and fixes both UUIDs and checksums in TSMS API payloads.\n";
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
            
            $result = $this->validatePayload($payload, true);
            
            if ($result['valid'] && empty($result['corrected_payload'])) {
                echo "✅ SUCCESS: Payload is completely valid!\n";
                return;
            }
            
            if ($result['valid'] && !empty($result['uuid_fixes'])) {
                echo "✅ SUCCESS: Payload valid after UUID fixes!\n";
            } else {
                echo "❌ VALIDATION FAILED:\n";
                foreach ($result['errors'] as $error) {
                    echo "  - " . $error . "\n";
                }
            }
            
            // Show UUID fixes if any were applied
            if (!empty($result['uuid_fixes'])) {
                echo "\n🔧 UUID FIXES APPLIED:\n";
                foreach ($result['uuid_fixes'] as $fix) {
                    echo "  - {$fix['field']}: {$fix['original_uuid']} → {$fix['corrected_uuid']}\n";
                    if (!empty($fix['fixes_applied'])) {
                        echo "    Character fixes: " . implode(', ', $fix['fixes_applied']) . "\n";
                    }
                }
            }
            
            if ($result['corrected_payload']) {
                echo "\n✅ CORRECTED PAYLOAD:\n";
                echo json_encode($result['corrected_payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                echo "\n\nCopy the corrected payload above for your API request.\n";
            }
            
        } catch (Exception $e) {
            echo "❌ ERROR: " . $e->getMessage() . "\n";
        }
    }
}

// Run the interactive validator if this script is executed directly
if (basename($_SERVER['PHP_SELF']) === 'validate-checksums.php') {
    $validator = new ChecksumValidator();
    $validator->runInteractive();
}
