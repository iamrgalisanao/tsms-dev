<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class TSMSTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $isSingle = $this->input('transaction_count') === 1;
        
        $rules = [
            'submission_uuid' => 'required|string|uuid',
            'tenant_id' => 'required|integer',
            'terminal_id' => 'required|integer|exists:pos_terminals,id',
            'submission_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
            'transaction_count' => 'required|integer|min:1',
            'payload_checksum' => 'required|string|min:64|max:64',
        ];

        if ($isSingle) {
            $rules = array_merge($rules, [
                'transaction' => 'required|array',
                'transaction.transaction_id' => 'required|string|uuid',
                'transaction.transaction_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
                'transaction.gross_sales' => 'required|numeric|min:0',
                'transaction.net_sales' => 'required|numeric',
                'transaction.promo_status' => 'required|string',
                'transaction.customer_code' => 'required|string',
                'transaction.payload_checksum' => 'required|string|min:64|max:64',
                'transaction.adjustments' => 'required|array|min:7',
                'transaction.adjustments.*.adjustment_type' => 'required|string',
                'transaction.adjustments.*.amount' => 'required|numeric',
                'transaction.taxes' => 'required|array|min:4',
                'transaction.taxes.*.tax_type' => 'required|string',
                'transaction.taxes.*.amount' => 'required|numeric',
            ]);
        } else {
            $rules = array_merge($rules, [
                'transactions' => 'required|array|min:1',
                'transactions.*.transaction_id' => 'required|string|uuid',
                'transactions.*.transaction_timestamp' => 'required|date_format:Y-m-d\TH:i:s\Z',
                'transactions.*.gross_sales' => 'required|numeric|min:0',
                'transactions.*.net_sales' => 'required|numeric',
                'transactions.*.promo_status' => 'required|string',
                'transactions.*.customer_code' => 'required|string',
                'transactions.*.payload_checksum' => 'required|string|min:64|max:64',
                'transactions.*.adjustments' => 'required|array|min:7',
                'transactions.*.adjustments.*.adjustment_type' => 'required|string',
                'transactions.*.adjustments.*.amount' => 'required|numeric',
                'transactions.*.taxes' => 'required|array|min:4',
                'transactions.*.taxes.*.tax_type' => 'required|string',
                'transactions.*.taxes.*.amount' => 'required|numeric',
            ]);
        }

        return $rules;
    }

    /**
     * Validate standard TSMS payload structure
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->validateStandardStructure()) {
                $validator->errors()->add('structure', 'Payload does not follow standard TSMS structure');
            }

            // Validate transaction structure and required fields
            $this->validateAmountRelationship($validator);
        });
    }

    /**
     * Validate that the payload follows the standard TSMS structure
     */
    private function validateStandardStructure(): bool
    {
        $payload = $this->all();
        
        // Check submission-level structure
        if (!$this->validateSubmissionStructure($payload)) {
            return false;
        }
        
        // Check transaction-level structure
        if ($this->input('transaction_count') === 1) {
            return $this->validateTransactionStructure($payload['transaction'] ?? []);
        } else {
            foreach ($payload['transactions'] ?? [] as $transaction) {
                if (!$this->validateTransactionStructure($transaction)) {
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * Validate amount relationships and required fields
     */
    private function validateAmountRelationship(Validator $validator): void
    {
        $payload = $this->all();

        if ($this->input('transaction_count') === 1) {
            $this->validateSingleTransactionAmounts($validator, $payload['transaction'] ?? []);
        } else {
            foreach ($payload['transactions'] ?? [] as $index => $transaction) {
                $this->validateSingleTransactionAmounts($validator, $transaction, "transactions.{$index}");
            }
        }
    }

    /**
     * Validate amounts and structure for a single transaction
     */
    private function validateSingleTransactionAmounts(Validator $validator, array $transaction, string $prefix = 'transaction'): void
    {
        $grossSales = $transaction['gross_sales'] ?? null;
        $netSales = $transaction['net_sales'] ?? null;
        $adjustments = $transaction['adjustments'] ?? [];
        $taxes = $transaction['taxes'] ?? [];

        // Validate gross_sales is present and positive
        if ($grossSales === null || $grossSales < 0) {
            $validator->errors()->add(
                "{$prefix}.gross_sales",
                "gross_sales must be present and non-negative"
            );
            return;
        }

        // Validate net_sales is present
        if ($netSales === null) {
            $validator->errors()->add(
                "{$prefix}.net_sales",
                "net_sales must be present"
            );
            return;
        }

        // Calculate expected net_sales = gross_sales - adjustments - other_tax
        $adjustmentSum = 0;
        foreach ($adjustments as $adjustment) {
            if (isset($adjustment['amount'])) {
                $adjustmentSum += $adjustment['amount'];
            }
        }

        $otherTaxSum = 0;
        foreach ($taxes as $tax) {
            if (isset($tax['tax_type']) && $tax['tax_type'] !== 'VATABLE_SALES' && isset($tax['amount'])) {
                $otherTaxSum += $tax['amount'];
            }
        }

        $expectedNetSales = round($grossSales - $adjustmentSum - $otherTaxSum, 2);

        // Allow for small rounding differences (0.01 tolerance)
        if (abs($netSales - $expectedNetSales) > 0.01) {
            $validator->errors()->add(
                "{$prefix}.net_sales",
                "net_sales ({$netSales}) must equal gross_sales ({$grossSales}) - adjustments ({$adjustmentSum}) - other_tax ({$otherTaxSum}) = {$expectedNetSales}"
            );
        }

        // Validate adjustments array has required structure
        if (count($adjustments) < 7) {
            $validator->errors()->add(
                "{$prefix}.adjustments",
                "adjustments array must contain at least 7 entries"
            );
        }

        // Validate taxes array has required structure
        if (count($taxes) < 4) {
            $validator->errors()->add(
                "{$prefix}.taxes",
                "taxes array must contain at least 4 entries"
            );
        }

        // Validate that required adjustment types are present
        $requiredAdjustmentTypes = [
            'promo_discount',
            'senior_discount',
            'pwd_discount',
            'vip_card_discount',
            'service_charge_distributed_to_employees',
            'service_charge_retained_by_management',
            'employee_discount'
        ];

        $presentTypes = array_column($adjustments, 'adjustment_type');
        $missingTypes = array_diff($requiredAdjustmentTypes, $presentTypes);

        if (!empty($missingTypes)) {
            $validator->errors()->add(
                "{$prefix}.adjustments",
                "Missing required adjustment types: " . implode(', ', $missingTypes)
            );
        }

        // Relaxed validation: allow non-VAT sales if SC_VAT_EXEMPT_SALES is present and VAT/VATABLE_SALES are zero
        $requiredTaxTypes = ['VAT', 'VATABLE_SALES', 'SC_VAT_EXEMPT_SALES'];
        $presentTaxTypes = array_column($taxes, 'tax_type');
        $missingTaxTypes = array_diff($requiredTaxTypes, $presentTaxTypes);

        $vatAmount = 0;
        $vatableSalesAmount = 0;
        $scVatExemptAmount = 0;
        foreach ($taxes as $tax) {
            if ($tax['tax_type'] === 'VAT') {
                $vatAmount = $tax['amount'];
            }
            if ($tax['tax_type'] === 'VATABLE_SALES') {
                $vatableSalesAmount = $tax['amount'];
            }
            if ($tax['tax_type'] === 'SC_VAT_EXEMPT_SALES') {
                $scVatExemptAmount = $tax['amount'];
            }
        }

        // Only error if missing required tax types AND not a valid non-VAT sale
        if (!empty($missingTaxTypes)) {
            $isNonVat = ($vatAmount == 0 && $vatableSalesAmount == 0 && $scVatExemptAmount > 0);
            if (!$isNonVat) {
                $validator->errors()->add(
                    "{$prefix}.taxes",
                    "Missing required tax types: " . implode(', ', $missingTaxTypes)
                );
            }
        }
    }

    /**
     * Validate submission-level field ordering
     */
    private function validateSubmissionStructure(array $payload): bool
    {
        $keys = array_keys($payload);
        
        // Find positions of key fields
        $payloadChecksumPos = array_search('payload_checksum', $keys);
        $transactionPos = array_search('transaction', $keys) ?: array_search('transactions', $keys);
        
        // payload_checksum should come after transaction_count but before transaction/transactions
        if ($payloadChecksumPos === false || $transactionPos === false) {
            return false;
        }
        
        return $payloadChecksumPos < $transactionPos;
    }

    /**
     * Validate transaction-level field ordering
     */
    private function validateTransactionStructure(array $transaction): bool
    {
        $keys = array_keys($transaction);
        
        // Find positions of key fields
        $payloadChecksumPos = array_search('payload_checksum', $keys);
        $adjustmentsPos = array_search('adjustments', $keys);
        $taxesPos = array_search('taxes', $keys);
        
        // payload_checksum must come before adjustments and taxes
        if ($payloadChecksumPos === false) {
            return false;
        }
        
        if ($adjustmentsPos !== false && $payloadChecksumPos > $adjustmentsPos) {
            return false;
        }
        
        if ($taxesPos !== false && $payloadChecksumPos > $taxesPos) {
            return false;
        }
        
        // Validate required field order: scalar fields before payload_checksum before arrays
        $scalarFields = ['transaction_id', 'transaction_timestamp', 'gross_sales', 'net_sales', 'promo_status', 'customer_code'];
        
        foreach ($scalarFields as $field) {
            $fieldPos = array_search($field, $keys);
            if ($fieldPos !== false && $fieldPos > $payloadChecksumPos) {
                return false; // Scalar field comes after payload_checksum
            }
        }
        
        return true;
    }

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'structure_hint' => 'Ensure payload follows standard TSMS structure with payload_checksum positioned correctly'
            ], 422)
        );
    }
}
