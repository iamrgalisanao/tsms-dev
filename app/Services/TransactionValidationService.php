<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\App;

class TransactionValidationService
{
    /**
     * Validate transaction data.
     *
     * @param array $data
     * @return array
     */
    public function validate(array $data)
    {
        $validator = Validator::make($data, [
            'tenant_id' => 'required|string',
            'transaction_id' => 'required|string',
            'transaction_timestamp' => 'required|date',
            'gross_sales' => 'required|numeric',
            'net_sales' => 'required|numeric',
            'vatable_sales' => 'required|numeric',
            'vat_exempt_sales' => 'required|numeric',
            'vat_amount' => 'required|numeric',
            'transaction_count' => 'required|integer',
            'payload_checksum' => 'required|string',
        ]);
        
        if ($validator->fails()) {
            return [
                'valid' => false,
                'errors' => $validator->errors()->toArray()
            ];
        }
        
        return [
            'valid' => true,
            'data' => $validator->validated()
        ];
    }
    
    /**
     * Parse text format data into structured array.
     *
     * @param string $content
     * @return array
     */
    public function parseTextFormat(string $content)
    {
        try {
            $lines = preg_split('/\r\n|\r|\n/', $content);
            $data = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Try to detect format: KEY: VALUE
                if (preg_match('/^([^:]+):(.*)$/', $line, $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);
                    $data[$key] = $value;
                } 
                // Try to detect format: KEY=VALUE
                elseif (preg_match('/^([^=]+)=(.*)$/', $line, $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);
                    $data[$key] = $value;
                }
                // Try to detect format: KEY VALUE
                elseif (preg_match('/^([^\s]+)\s+(.*)$/', $line, $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);
                    $data[$key] = $value;
                }
            }
            
            // Normalize field names to match expected structure
            $normalized = [];
            $fieldMap = [
                'TENANT_ID' => 'tenant_id',
                'TRANSACTION_ID' => 'transaction_id',
                'TRANSACTION_TIMESTAMP' => 'transaction_timestamp',
                'GROSS_SALES' => 'gross_sales',
                'NET_SALES' => 'net_sales',
                'VATABLE_SALES' => 'vatable_sales',
                'VAT_EXEMPT_SALES' => 'vat_exempt_sales',
                'VAT_AMOUNT' => 'vat_amount',
                'TRANSACTION_COUNT' => 'transaction_count',
                'PAYLOAD_CHECKSUM' => 'payload_checksum',
                'HARDWARE_ID' => 'hardware_id',
                'MACHINE_NUMBER' => 'machine_number',
                'STORE_NAME' => 'store_name',
                'PROMO_DISCOUNT_AMOUNT' => 'promo_discount_amount',
                'PROMO_STATUS' => 'promo_status',
                'DISCOUNT_TOTAL' => 'discount_total',
                'OTHER_TAX' => 'other_tax',
                'MANAGEMENT_SERVICE_CHARGE' => 'management_service_charge',
                'EMPLOYEE_SERVICE_CHARGE' => 'employee_service_charge',
            ];
            
            foreach ($data as $key => $value) {
                $normalizedKey = strtoupper($key);
                if (isset($fieldMap[$normalizedKey])) {
                    $normalized[$fieldMap[$normalizedKey]] = $value;
                } else {
                    // If no mapping, use the original key in lowercase
                    $normalized[strtolower($key)] = $value;
                }
            }
            
            return $normalized;
        } catch (\Exception $e) {
            Log::error('Error parsing text format', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [];
        }
    }
}