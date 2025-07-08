<?php

namespace App\Services;

use Exception;

class PayloadChecksumService
{
    /**
     * Compute SHA-256 checksum for a payload
     * 
     * @param array $payload The payload to compute checksum for
     * @param string $excludeField The field to exclude from checksum calculation
     * @return string The computed SHA-256 hash
     */
    public function computeChecksum(array $payload, string $excludeField = 'payload_checksum'): string
    {
        // Clone the payload to avoid mutating the original
        $clone = $this->deepClone($payload);
        
        // Remove the checksum field from calculation
        $this->removeField($clone, $excludeField);
        
        // For transaction arrays, remove checksum from each transaction
        if (isset($clone['transactions']) && is_array($clone['transactions'])) {
            $clone['transactions'] = array_map(function ($txn) use ($excludeField) {
                $txnClone = $this->deepClone($txn);
                $this->removeField($txnClone, $excludeField);
                return $txnClone;
            }, $clone['transactions']);
        }
        
        // For single transaction objects
        if (isset($clone['transaction']) && is_array($clone['transaction'])) {
            $this->removeField($clone['transaction'], $excludeField);
        }
        
        // Serialize to compact JSON (no spaces or line breaks)
        $jsonString = json_encode($clone, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        // Compute SHA-256 hash
        return hash('sha256', $jsonString);
    }
    
    /**
     * Validate that the provided checksum matches the computed checksum
     * 
     * @param array $payload The payload to validate
     * @param string $providedChecksum The checksum provided in the payload
     * @param string $checksumField The field name containing the checksum
     * @return bool True if checksums match, false otherwise
     */
    public function validateChecksum(array $payload, string $providedChecksum = null, string $checksumField = 'payload_checksum'): bool
    {
        if ($providedChecksum === null) {
            $providedChecksum = $payload[$checksumField] ?? null;
        }
        
        if (empty($providedChecksum)) {
            return false;
        }
        
        $computedChecksum = $this->computeChecksum($payload, $checksumField);
        
        return hash_equals($computedChecksum, $providedChecksum);
    }
    
    /**
     * Validate checksums for both submission and individual transactions
     * 
     * @param array $payload The full submission payload
     * @return array Array with validation results
     */
    public function validateSubmissionChecksums(array $payload): array
    {
        $results = [
            'valid' => true,
            'errors' => [],
            'submission_checksum_valid' => false,
            'transaction_checksums_valid' => []
        ];
        
        // Validate submission-level checksum
        try {
            $results['submission_checksum_valid'] = $this->validateChecksum($payload);
            if (!$results['submission_checksum_valid']) {
                $results['valid'] = false;
                $results['errors'][] = 'Invalid submission payload_checksum';
            }
        } catch (Exception $e) {
            $results['valid'] = false;
            $results['errors'][] = 'Error validating submission checksum: ' . $e->getMessage();
        }
        
        // Validate individual transaction checksums
        if (isset($payload['transactions']) && is_array($payload['transactions'])) {
            foreach ($payload['transactions'] as $index => $transaction) {
                try {
                    $valid = $this->validateChecksum($transaction);
                    $results['transaction_checksums_valid'][$index] = $valid;
                    if (!$valid) {
                        $results['valid'] = false;
                        $results['errors'][] = "Invalid payload_checksum for transaction at index {$index}";
                    }
                } catch (Exception $e) {
                    $results['valid'] = false;
                    $results['errors'][] = "Error validating transaction {$index} checksum: " . $e->getMessage();
                }
            }
        } elseif (isset($payload['transaction']) && is_array($payload['transaction'])) {
            try {
                $valid = $this->validateChecksum($payload['transaction']);
                $results['transaction_checksums_valid'][0] = $valid;
                if (!$valid) {
                    $results['valid'] = false;
                    $results['errors'][] = 'Invalid payload_checksum for transaction';
                }
            } catch (Exception $e) {
                $results['valid'] = false;
                $results['errors'][] = 'Error validating transaction checksum: ' . $e->getMessage();
            }
        }
        
        return $results;
    }
    
    /**
     * Deep clone an array
     */
    private function deepClone(array $array): array
    {
        return json_decode(json_encode($array), true);
    }
    
    /**
     * Remove a field from an array recursively
     */
    private function removeField(array &$array, string $field): void
    {
        if (array_key_exists($field, $array)) {
            unset($array[$field]);
        }
    }
}