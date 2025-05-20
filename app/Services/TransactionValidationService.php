<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\PosTerminal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

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
        // Basic validation
        $validator = Validator::make($data, [
            'tenant_id' => 'required|string',
            'terminal_id' => 'required|string',
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

        // Check for duplicate transaction
        if ($this->isDuplicate($data)) {
            return [
                'valid' => false,
                'errors' => ['Transaction ID has already been processed']
            ];
        }
        
        return [
            'valid' => true,
            'data' => $validator->validated()
        ];
    }

    /**
     * Check if the transaction is a duplicate.
     *
     * @param array $data
     * @return bool
     */
    protected function isDuplicate(array $data): bool
    {
        return Transaction::where('tenant_id', $data['tenant_id'])
            ->where('transaction_id', $data['transaction_id'])
            ->exists();
    }
    
    /**
     * Parse text format data into structured array.
     * 
     * This method handles multiple text formats:
     * - KEY: VALUE format
     * - KEY=VALUE format
     * - KEY VALUE format
     * - Mixed formats
     *
     * @param string $content
     * @return array
     */
    public function parseTextFormat(string $content)
    {
        try {
            $data = [];
            
            // Log the content for debugging
            Log::info('Parsing text content', ['content_length' => strlen($content)]);
            
            // Split content into lines
            $lines = preg_split('/\r\n|\r|\n/', $content);
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Try KEY: VALUE format
                if (preg_match('/^([^:]+):(.*)$/', $line, $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);
                    $data[$key] = $value;
                    continue;
                }
                
                // Try KEY=VALUE format
                if (preg_match('/^([^=]+)=(.*)$/', $line, $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);
                    $data[$key] = $value;
                    continue;
                }
                
                // Try KEY VALUE format
                if (preg_match('/^([^\s]+)\s+(.*)$/', $line, $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);
                    $data[$key] = $value;
                    continue;
                }
            }
            
            // Normalize field names
            $normalized = $this->normalizeFieldNames($data);
            
            // Log what was parsed
            Log::info('Parsed text format', [
                'original_fields' => array_keys($data),
                'normalized_fields' => array_keys($normalized),
                'field_count' => count($normalized)
            ]);
            
            return $normalized;
        } catch (\Exception $e) {
            // Log any errors
            Log::error('Text format parsing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return empty array on error
            return [];
        }
    }
    
    /**
     * Normalize field names to standard format
     *
     * @param array $data
     * @return array
     */
    private function normalizeFieldNames(array $data)
    {
        $normalized = [];
        $fieldMap = [
            'TENANT_ID' => 'tenant_id',
            'TENANTID' => 'tenant_id',
            'TENANT-ID' => 'tenant_id',
            
            'TRANSACTION_ID' => 'transaction_id',
            'TRANSACTIONID' => 'transaction_id',
            'TX_ID' => 'transaction_id',
            'TXID' => 'transaction_id',
            
            'TRANSACTION_TIMESTAMP' => 'transaction_timestamp',
            'TX_TIMESTAMP' => 'transaction_timestamp',
            'TX_TIME' => 'transaction_timestamp',
            'DATETIME' => 'transaction_timestamp',
            'DATE_TIME' => 'transaction_timestamp',
            
            'GROSS_SALES' => 'gross_sales',
            'GROSSSALES' => 'gross_sales',
            'GROSS' => 'gross_sales',
            
            'NET_SALES' => 'net_sales',
            'NETSALES' => 'net_sales',
            'NET' => 'net_sales',
            
            'VATABLE_SALES' => 'vatable_sales',
            'VATABLESALES' => 'vatable_sales',
            'VAT_SALES' => 'vatable_sales',
            
            'VAT_EXEMPT_SALES' => 'vat_exempt_sales',
            'VATEXEMPTSALES' => 'vat_exempt_sales',
            'EXEMPT_SALES' => 'vat_exempt_sales',
            
            'VAT_AMOUNT' => 'vat_amount',
            'VATAMOUNT' => 'vat_amount',
            'VAT' => 'vat_amount',
            
            'TRANSACTION_COUNT' => 'transaction_count',
            'TX_COUNT' => 'transaction_count',
            'TRANSACTIONCOUNT' => 'transaction_count',
            
            'PAYLOAD_CHECKSUM' => 'payload_checksum',
            'CHECKSUM' => 'payload_checksum',
            'HASH' => 'payload_checksum',
        ];
        
        foreach ($data as $key => $value) {
            $normalizedKey = strtoupper($key);
            if (isset($fieldMap[$normalizedKey])) {
                $normalized[$fieldMap[$normalizedKey]] = $value;
            } else {
                // If no mapping, just use the original key in lowercase
                $normalized[strtolower($key)] = $value;
            }
        }
        
        return $normalized;
    }
}