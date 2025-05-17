<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class TransactionValidationService
{
    /**
     * Parse the text format input and convert it to a standardized transaction payload
     * 
     * @param string $textInput The raw text input from POS terminal
     * @return array Structured transaction data
     */
    public function parseTextFormat($textInput)
    {
        try {
            Log::info('Parsing text format input', ['length' => strlen($textInput)]);
            
            // Split the text by newlines to get individual data fields
            $lines = preg_split('/\r\n|\r|\n/', $textInput);
            
            // Initialize the result array with default values
            $result = [
                'tenant_id' => null,
                'hardware_id' => null,
                'machine_number' => null,
                'transaction_id' => null,
                'store_name' => null,
                'transaction_timestamp' => now()->toIso8601String(),
                'gross_sales' => 0,
                'net_sales' => 0,
                'vatable_sales' => 0,
                'vat_exempt_sales' => 0,
                'promo_discount_amount' => 0,
                'promo_status' => 'NONE',
                'discount_total' => 0,
                'discount_details' => [],
                'other_tax' => 0,
                'management_service_charge' => 0,
                'employee_service_charge' => 0,
                'vat_amount' => 0,
                'transaction_count' => 1,
            ];
            
            // Parse each line to extract key-value pairs
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Handle different line formats
                if (strpos($line, ':') !== false) {
                    // Key-value format (KEY: VALUE)
                    list($key, $value) = array_map('trim', explode(':', $line, 2));
                    $this->assignKeyValue($result, $key, $value);
                } elseif (strpos($line, '=') !== false) {
                    // Key-value format (KEY=VALUE)
                    list($key, $value) = array_map('trim', explode('=', $line, 2));
                    $this->assignKeyValue($result, $key, $value);
                } elseif (preg_match('/^([A-Z_]+)\s+(.+)$/', $line, $matches)) {
                    // Space-separated format (KEY VALUE)
                    $this->assignKeyValue($result, $matches[1], $matches[2]);
                }
            }
            
            // Generate a computed checksum for verification
            $result['payload_checksum'] = $this->generateChecksum($result);
            
            Log::info('Text format parsed successfully', [
                'tenant_id' => $result['tenant_id'],
                'transaction_id' => $result['transaction_id']
            ]);
            
            return $result;
        } catch (\Exception $e) {
            Log::error('Failed to parse text format', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new \RuntimeException('Text format parsing failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Assign a key-value pair to the appropriate field in the result array
     * 
     * @param array &$result Reference to the result array
     * @param string $key The field key from the text input
     * @param string $value The value from the text input
     */
    private function assignKeyValue(&$result, $key, $value)
    {
        // Normalize the key to lowercase for case-insensitive matching
        $normalizedKey = strtolower(str_replace(['_', ' '], '', $key));
        
        switch ($normalizedKey) {
            case 'tenantid':
            case 'tenant':
                $result['tenant_id'] = $value;
                break;
                
            case 'hardwareid':
            case 'posid':
            case 'terminalid':
                $result['hardware_id'] = $value;
                break;
                
            case 'machinenumber':
            case 'machine':
            case 'machinenum':
                $result['machine_number'] = intval($value);
                break;
                
            case 'transactionid':
            case 'txid':
            case 'transid':
                $result['transaction_id'] = $value;
                break;
                
            case 'storename':
            case 'store':
                $result['store_name'] = $value;
                break;
                
            case 'timestamp':
            case 'date':
            case 'datetime':
            case 'transactiondate':
                $result['transaction_timestamp'] = $this->parseTimestamp($value);
                break;
                
            case 'grosssales':
            case 'gross':
                $result['gross_sales'] = $this->parseNumeric($value);
                break;
                
            case 'netsales':
            case 'net':
                $result['net_sales'] = $this->parseNumeric($value);
                break;
                
            case 'vatablesales':
            case 'vatable':
                $result['vatable_sales'] = $this->parseNumeric($value);
                break;
                
            case 'vatexemptsales':
            case 'vatexempt':
                $result['vat_exempt_sales'] = $this->parseNumeric($value);
                break;
                
            case 'vatamount':
            case 'vat':
                $result['vat_amount'] = $this->parseNumeric($value);
                break;
                
            case 'promodiscountamount':
            case 'promodiscount':
                $result['promo_discount_amount'] = $this->parseNumeric($value);
                break;
                
            case 'promostatus':
            case 'promo':
                $result['promo_status'] = $value;
                break;
                
            case 'discounttotal':
            case 'discount':
                $result['discount_total'] = $this->parseNumeric($value);
                break;
                
            case 'discountdetails':
            case 'discounts':
                $result['discount_details'] = $this->parseDiscountDetails($value);
                break;
                
            case 'othertax':
            case 'tax':
                $result['other_tax'] = $this->parseNumeric($value);
                break;
                
            case 'managementservicecharge':
            case 'mgmtsc':
                $result['management_service_charge'] = $this->parseNumeric($value);
                break;
                
            case 'employeeservicecharge':
            case 'empsc':
                $result['employee_service_charge'] = $this->parseNumeric($value);
                break;
                
            case 'transactioncount':
            case 'txcount':
                $result['transaction_count'] = intval($value);
                break;
        }
    }
    
    /**
     * Parse discount details string into structured format
     * 
     * @param string $value The discount details string
     * @return array Structured discount details
     */
    private function parseDiscountDetails($value)
    {
        $details = [];
        
        // Handle different possible formats
        if (strpos($value, ',') !== false) {
            // Comma-separated format: "Senior:10.00,Employee:5.00"
            $items = explode(',', $value);
            foreach ($items as $item) {
                if (strpos($item, ':') !== false) {
                    list($type, $amount) = explode(':', $item, 2);
                    $details[trim($type)] = $this->parseNumeric($amount);
                }
            }
        } elseif (strpos($value, ';') !== false) {
            // Semicolon-separated format: "Senior;10.00;Employee;5.00"
            $items = explode(';', $value);
            for ($i = 0; $i < count($items); $i += 2) {
                if (isset($items[$i+1])) {
                    $details[trim($items[$i])] = $this->parseNumeric($items[$i+1]);
                }
            }
        } else {
            // Try JSON format
            try {
                $jsonDetails = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($jsonDetails)) {
                    $details = $jsonDetails;
                }
            } catch (\Exception $e) {
                // Not valid JSON, keep empty details
            }
        }
        
        return $details;
    }
    
    /**
     * Parse numeric value from string
     * 
     * @param string $value The string value to parse
     * @return float The parsed numeric value
     */
    private function parseNumeric($value)
    {
        // Remove currency symbols, commas, and other non-numeric characters
        $cleaned = preg_replace('/[^\d.]/', '', $value);
        return floatval($cleaned);
    }
    
    /**
     * Parse timestamp from various formats
     * 
     * @param string $value The timestamp string
     * @return string ISO 8601 formatted timestamp
     */
    private function parseTimestamp($value)
    {
        try {
            // Try various date formats
            foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'm/d/Y H:i:s', 'd-m-Y H:i:s'] as $format) {
                $date = \DateTime::createFromFormat($format, $value);
                if ($date !== false) {
                    return $date->format('Y-m-d\TH:i:s\Z');
                }
            }
            
            // Fall back to strtotime
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d\TH:i:s\Z', $timestamp);
            }
        } catch (\Exception $e) {
            // Ignore parsing errors
        }
        
        // Default to current time if parsing fails
        return now()->toIso8601String();
    }
    
    /**
     * Generate a checksum for the payload
     * 
     * @param array $payload The transaction payload
     * @return string SHA-256 checksum
     */
    private function generateChecksum($payload)
    {
        // Create a copy and remove any existing checksum
        $payloadCopy = $payload;
        unset($payloadCopy['payload_checksum']);
        
        // Sort keys for consistency
        ksort($payloadCopy);
        
        // Generate SHA-256 hash
        return hash('sha256', json_encode($payloadCopy));
    }

    public function validate(array $payload): array
    {
        $requiredFields = [
            'tenant_id',
            'hardware_id',
            'machine_number',
            'transaction_id',
            'store_name',
            'transaction_timestamp',
            'gross_sales',
            'net_sales',
            'vatable_sales',
            'vat_exempt_sales',
            'promo_discount_amount',
            'promo_status',
            'discount_total',
            'discount_details',
            'other_tax',
            'management_service_charge',
            'employee_service_charge',
            'vat_amount',
            'transaction_count',
            'payload_checksum',
        ];

        $errors = [];

        // Check for missing fields
        foreach ($requiredFields as $field) {
            if (!isset($payload[$field])) {
                $errors[] = "Missing required field: $field";
            }
        }

        // Check checksum
        $originalChecksum = $payload['payload_checksum'] ?? null;
        $payloadCopy = $payload;
        unset($payloadCopy['payload_checksum']);

        $computedChecksum = hash('sha256', json_encode($payloadCopy));

        if ($originalChecksum !== $computedChecksum) {
            $errors[] = 'Checksum mismatch';
        }

        return [
            'validation_status' => empty($errors) ? 'VALID' : 'ERROR',
            'error_code' => empty($errors) ? null : 'ERR-102',
            'computed_checksum' => $computedChecksum,
            'errors' => $errors,
        ];
    }
}
