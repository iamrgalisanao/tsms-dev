<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Models\Store;
use Illuminate\Support\Facades\{Log, Validator};
use Carbon\Carbon;
use InvalidArgumentException;

class TransactionValidationService
{
    protected const MAX_VAT_DIFFERENCE = 0.02;
    protected const MAX_ROUNDING_DIFFERENCE = 0.05;
    protected const MAX_SERVICE_CHARGE_PERCENTAGE = 0.15;
    protected const MAX_DISCOUNT_PERCENTAGE = 0.50;
    protected const MAX_TRANSACTION_AGE_DAYS = 30;
    
    // Move to config or database
    protected array $validPromoCodes = [
        'SUMMER2023',
        'HOLIDAY25',
        'LOYAL10',
        'WELCOME15'
    ];

    /**
     * Validate transaction data.
     *
     * @param array|Transaction $data
     * @return array
     */
    public function validate($data): array
    {
        try {
            if (is_array($data)) {
                return $this->validateSubmission($data);
            }
            
            if ($data instanceof Transaction) {
                return $this->validateTransaction($data);
            }
            
            throw new InvalidArgumentException('Invalid input type');
            
        } catch (\Exception $e) {
            Log::error('Validation error', [
                'message' => $e->getMessage(),
                'data' => $data
            ]);
            
            return [
                'valid' => false,
                'errors' => [$e->getMessage()]
            ];
        }
    }

    protected function validateSubmission(array $data)
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
     * This method handles multiple text formats:
     * - KEY: VALUE format
     * - KEY=VALUE formatt
     * - KEY VALUE format
     * - Mixed formats
     * @param string $content
     * @return array
     */
    public function parseTextFormat(string $content): array
    {
        try {
            $lines = preg_split('/\r\n|\r|\n/', $content);
            $data = [];
            
            Log::info('Parsing text content', ['content_length' => strlen($content)]);
            
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
            
            $normalized = $this->normalizeFieldNames($data);
            
            Log::info('Parsed text format', [
                'original_fields' => array_keys($data),
                'normalized_fields' => array_keys($normalized),
                'field_count' => count($normalized)
            ]);
            
            return $normalized;
        } catch (\Exception $e) {
            Log::error('Text format parsing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }
    /**
     * Normalize field names to standard format
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
    /**
     * Validate transaction.
     * @param Transaction $transaction
     * @return array
     */
    public function validateTransaction(Transaction $transaction)
    {
        Log::info('Starting transaction validation', ['transaction_id' => $transaction->id]);
        $errors = [];

        // Store and operating hours validation
        $storeErrors = $this->validateStore($transaction);
        if (!empty($storeErrors)) {
            $errors = array_merge($errors, $storeErrors);
        }

        // Terminal validation
        $terminalErrors = $this->validateTerminal($transaction);
        if (!empty($terminalErrors)) {
            $errors = array_merge($errors, $terminalErrors);
        }

        // Amount validations
        $amountErrors = $this->validateAmounts($transaction);
        if (!empty($amountErrors)) {
            $errors = array_merge($errors, $amountErrors);
        }

        // Discount validations
        $discountErrors = $this->validateDiscounts($transaction);
        if (!empty($discountErrors)) {
            $errors = array_merge($errors, $discountErrors);
        }

        // Transaction integrity
        $integrityErrors = $this->validateTransactionIntegrity($transaction);
        if (!empty($integrityErrors)) {
            $errors = array_merge($errors, $integrityErrors);
        }

        // Business rules
        $businessRuleErrors = $this->validateBusinessRules($transaction);
        if (!empty($businessRuleErrors)) {
            $errors = array_merge($errors, $businessRuleErrors);
        }

        if (empty($errors)) {
            return [
                'valid' => true,
                'errors' => [],
            ];
        }

        Log::warning('Transaction validation failed', [
            'transaction_id' => $transaction->id,
            'errors' => $errors
        ]);

        return [
            'valid' => false,
            'errors' => $errors
        ];
    }

    /**
     * Internal implementation of text format parsing
     * @param string $content
     * @return array
     */
    private function parseTextFormatInternal($content)
    {
        $result = [
            'status' => 'error',
            'message' => 'Failed to parse content',
            'data' => null,
        ];
        try {
            $lines = explode("\n", $content);
            $data = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                // Try KEY: VALUE format
                if (preg_match('/^([^:]+):(.+)$/', $line, $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);
                    $data[$key] = $value;
                    continue;
                }
                // Try KEY=VALUE format
                if (preg_match('/^([^=]+)=(.+)$/', $line, $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);
                    $data[$key] = $value;
                    continue;
                }
                // Try KEY VALUE format
                if (preg_match('/^(\w+)\s+(.+)$/', $line, $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);
                    $data[$key] = $value;
                    continue;
                }
            }
            // Convert numeric values
            foreach ($data as $key => $value) {
                if (is_numeric($value)) {
                    $data[$key] = strpos($value, '.') !== false ? 
                                   (float)$value : (int)$value;
                }
            }
            $result = [
                'status' => 'success',
                'message' => 'Content parsed successfully',
                'data' => $data,
            ];
        } catch (\Exception $e) {
            $result['message'] = 'Failed to parse content: ' . $e->getMessage();
        }
        return $result;
    }
    /**
     * Validate store information
     * @param Transaction $transaction
     * @return array
     */
    protected function validateStore(Transaction $transaction)
    {
        $errors = [];
        // Get terminal to find associated store
        $terminal = PosTerminal::find($transaction->terminal_id);
        if (!$terminal) {
            return ['Terminal not found'];
        }
        // Find store
        $store = $terminal->store;
        if (!$store) {
            return ['Store not found for this terminal'];
        }
        // Validate operating hours
        $transactionTime = Carbon::parse($transaction->transaction_timestamp);
        $dayOfWeek = strtolower($transactionTime->format('l'));
        // Get operating hours for that day
        $operatingHours = $store->operating_hours[$dayOfWeek] ?? null;
        if (!$operatingHours) {
            $errors[] = 'Store operating hours not defined for ' . ucfirst($dayOfWeek);
        } else {
            $openTime = Carbon::parse($operatingHours['open']);
            $closeTime = Carbon::parse($operatingHours['close']);
            if ($transactionTime->lt($openTime) || $transactionTime->gt($closeTime)) {
                $errors[] = 'Transaction occurred outside of store operating hours';
            }
        }
        return $errors;
    }
    /**
     * Validate terminal information
     * @param Transaction $transaction
     * @return array
     */
    protected function validateTerminal(Transaction $transaction)
    {
        $errors = [];
        $terminal = PosTerminal::find($transaction->terminal_id);
        if (!$terminal) {
            return ['Terminal not found'];
        }
        if ($terminal->status !== 'active') {
            $errors[] = 'Terminal is not active. Current status: ' . $terminal->status;
        }
        return $errors;
    }
    /**
     * Validate transaction amounts
     * @param Transaction $transaction
     * @return array
     */
    private function validateAmounts(Transaction $transaction): array 
    {
        $errors = [];

        // Basic amount validations
        if ($transaction->gross_sales <= 0) {
            $errors[] = 'Gross sales amount must be positive';
        }

        if ($transaction->net_sales <= 0) {
            $errors[] = 'Net sales amount must be positive';
        }

        if ($transaction->vatable_sales < 0) {
            $errors[] = 'Vatable sales cannot be negative';
        }

        // VAT calculations
        if (!$transaction->tax_exempt && $transaction->vatable_sales > 0) {
            $expectedVat = round($transaction->vatable_sales * 0.12, 2);
            $actualVat = round($transaction->vat_amount, 2);
            
            if (abs($expectedVat - $actualVat) > self::MAX_VAT_DIFFERENCE) {
                $errors[] = sprintf(
                    'VAT amount %.2f does not match expected 12%% (%.2f)',
                    $actualVat,
                    $expectedVat
                );
            }
        }

        // Service charge validation
        $this->validateServiceCharges($transaction, $errors);

        // Amount reconciliation 
        $this->validateAmountReconciliation($transaction, $errors);

        return $errors;
    }

    private function validateServiceCharges(Transaction $transaction, array &$errors): void
    {
        $totalCharges = ($transaction->service_charge ?? 0) + 
                       ($transaction->management_service_charge ?? 0);

        if ($totalCharges > $transaction->gross_sales * self::MAX_SERVICE_CHARGE_PERCENTAGE) {
            $errors[] = sprintf(
                'Service charges (%.2f) exceed maximum allowed percentage (%.0f%%)',
                $totalCharges,
                self::MAX_SERVICE_CHARGE_PERCENTAGE * 100
            );
        }
    }

    private function validateAmountReconciliation(Transaction $transaction, array &$errors): void
    {
        $expectedNet = $transaction->gross_sales - $transaction->vat_amount;
        $expectedNet += $this->calculateAdjustments($transaction);

        if (abs($expectedNet - $transaction->net_sales) > self::MAX_ROUNDING_DIFFERENCE) {
            $errors[] = 'Net sales amount does not reconcile with calculations';
        }
    }

    private function calculateAdjustments(Transaction $transaction): float
    {
        $adjustments = 0;
        
        // Add service charges
        $adjustments += $transaction->service_charge ?? 0;
        $adjustments += $transaction->management_service_charge ?? 0;
        
        // Subtract discounts
        $adjustments -= $transaction->discount_amount ?? 0;
        $adjustments -= $transaction->discount_total ?? 0;
        
        return $adjustments;
    }
    /**
     * Validate transaction integrity
     * @param Transaction $transaction
     * @return array
     */
    protected function validateTransactionIntegrity(Transaction $transaction)
    {
        $errors = [];
        // Enhanced transaction sequence validation
        // Check for duplicate transactions
        $duplicate = Transaction::where('transaction_id', $transaction->transaction_id)
            ->where('terminal_id', $transaction->terminal_id)
            ->where('id', '!=', $transaction->id)
            ->first();
        if ($duplicate) {
            $errors[] = sprintf(
                'Duplicate transaction detected (ID: %s, created at %s)',
                $duplicate->id,
                $duplicate->created_at->format('Y-m-d H:i:s')
            );
        }
        // Enhanced transaction sequence validation
        if (property_exists($transaction, 'sequence_number') && $transaction->sequence_number !== null) {
            $lastTransaction = Transaction::where('terminal_id', $transaction->terminal_id)
                ->where('id', '!=', $transaction->id)
                ->whereNotNull('sequence_number')
                ->orderBy('sequence_number', 'desc')
                ->first();
            if ($lastTransaction) {
                $expectedSequence = $lastTransaction->sequence_number + 1;
                if ($transaction->sequence_number > $expectedSequence) {
                    $gap = $transaction->sequence_number - $expectedSequence;
                    // Gap of 1 or 2 is likely just missed transactions, but larger gaps are suspicious
                    if ($gap > 2) {
                        $errors[] = sprintf(
                            'Sequence gap detected: expected %d but received %d (gap of %d transactions)',
                            $expectedSequence,
                            $transaction->sequence_number,
                            $gap
                        );
                    }
                } elseif ($transaction->sequence_number < $expectedSequence) {
                    // Out of order transactions are always a problem
                    $errors[] = sprintf(
                        'Out of sequence transaction: expected %d or higher but received %d',
                        $expectedSequence,
                        $transaction->sequence_number
                    );
                }
            }
        }
        // Transaction should not be too old (e.g., more than 30 days)
        $now = Carbon::now();
        $transactionTime = Carbon::parse($transaction->transaction_timestamp);
        if ($transactionTime->lt($now->copy()->subDays(30))) {
            $errors[] = sprintf(
                'Transaction is too old (%s). Transactions older than 30 days are not allowed.',
                $transactionTime->format('Y-m-d H:i:s')
            );
        }
        // Transaction should not be in the future
        if ($transactionTime->gt($now)) {
            $errors[] = sprintf(
                'Transaction timestamp (%s) cannot be in the future (current time: %s)',
                $transactionTime->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s')
            );
        }
        // Transaction ID format validation (optional)
        if (property_exists($transaction, 'transaction_id')) {
            // Common POS system patterns
            $validPatterns = [
                '/^[A-Za-z0-9]{6,32}$/', // Basic alphanumeric
                '/^TX\-[A-Za-z0-9-]{4,}$/i', // TX-format
                '/^[A-Z0-9]{2,4}\-\d{6,10}$/', // PREFIX-NUMBERS format
                '/^\d{4}\-\d{2}\-\d{2}\-\d{4,}$/' // DATE-NUMBER format
            ];
            $isValidFormat = false;
            foreach ($validPatterns as $pattern) {
                if (preg_match($pattern, $transaction->transaction_id)) {
                    $isValidFormat = true;
                    break;
                }
            }
            if (!$isValidFormat) {
                $errors[] = sprintf(
                    'Transaction ID format is not recognized: %s',
                    $transaction->transaction_id
                );
            }
        }
        return $errors;
    }
    /**
     * Validate business rules
     * @param Transaction $transaction
     * @return array
     */
    protected function validateBusinessRules(Transaction $transaction)
    {
        // Complies with Section 7 Validation & Retry Logic
        $errors = [];
        // Get terminal and store
        $terminal = PosTerminal::find($transaction->terminal_id);
        if (!$terminal) {
            return ['Terminal not found'];
        }
        // Daily transaction limit
        $store = $terminal->store;
        $transactionDate = Carbon::parse($transaction->transaction_timestamp);
        if (!$store) {
            return ['Store not found for this terminal'];
        }
        // Transaction limits
        if ($store->max_transaction_amount && $transaction->gross_sales > $store->max_transaction_amount) {
            $errors[] = sprintf(
                'Transaction exceeds maximum allowed amount for this store: %.2f (limit: %.2f)',
                $transaction->gross_sales,
                $store->max_transaction_amount
            );
        }
        // Daily transaction limit, considering service charges
        $dailySalesTotal = $store->getDailySalesTotal($transactionDate);
        $newTotal = $dailySalesTotal + $transaction->gross_sales;
        if ($store->max_daily_sales && $newTotal > $store->max_daily_sales) {
            $errors[] = sprintf(
                'Transaction would exceed daily limit for this store: %.2f + %.2f = %.2f (limit: %.2f)',
                $dailySalesTotal,
                $transaction->gross_sales,
                $newTotal,
                $store->max_daily_sales
            );
        }
        // Service charge rules
        $serviceCharge = null;
        if (property_exists($transaction, 'service_charge') && $transaction->service_charge > 0) {
            $serviceCharge = $transaction->service_charge;
        } elseif (property_exists($transaction, 'management_service_charge') && $transaction->management_service_charge > 0) {
            $serviceCharge = $transaction->management_service_charge;
        }
        if ($serviceCharge) {
            if (!$store->allows_service_charge) {
                $errors[] = sprintf(
                    'This store does not allow service charges (found: %.2f)',
                    $serviceCharge
                );
            }
        }
        // Tax exemption validation
        $isTaxExempt = false;
        $hasTaxExemptId = false;
        if (property_exists($transaction, 'tax_exempt') && $transaction->tax_exempt) {
            $isTaxExempt = true;
        }
        if (property_exists($transaction, 'tax_exempt_id') && !empty($transaction->tax_exempt_id)) {
            $hasTaxExemptId = true;
        }
        if ($isTaxExempt) {
            if ($transaction->vat_amount != 0) {
                $errors[] = sprintf(
                    'Tax exempt transactions should have 0 VAT amount (found: %.2f)',
                    $transaction->vat_amount
                );
            }
            if (!$hasTaxExemptId) {
                $errors[] = 'Tax exemption requires a valid exemption ID';
            }
        } else {
            if ($store->tax_exempt && $transaction->vat_amount > 0) {
                $errors[] = 'Non-tax-exempt transactions in tax exempt stores should be flagged';
            }
        }
        // Discount validations
        if (property_exists($transaction, 'discount_amount') && $transaction->discount_amount > 0) {
            $discountAmount = $transaction->discount_amount;
            // Discount should not exceed 50% of gross_sales as a business rule
            $maxDiscount = $transaction->gross_sales * self::MAX_DISCOUNT_PERCENTAGE;
            if ($discountAmount > $maxDiscount) {
                $errors[] = sprintf(
                    'Discount amount (%.2f) exceeds maximum allowed threshold (50%% of gross sales: %.2f)',
                    $discountAmount,
                    $maxDiscount
                );
            }
        }
        return $errors;
    }
}