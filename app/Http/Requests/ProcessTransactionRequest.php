<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessTransactionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Required Fields
            'tenant_id' => 'required|string|exists:tenants,id',
            'hardware_id' => 'required|string|exists:pos_terminals,terminal_uid',
            'transaction_id' => 'required|string|unique:transactions,transaction_id',
            'transaction_timestamp' => 'required|date',
            
            // Sales Fields
            'vatable_sales' => 'required|numeric|min:0',
            'net_sales' => 'required|numeric|min:0',
            'vat_exempt_sales' => 'required|numeric|min:0',
            'gross_sales' => 'required|numeric|min:0',
            
            // Discounts
            'promo_discount_amount' => 'nullable|numeric|min:0',
            'promo_status' => 'nullable|string|in:WITH_APPROVAL,NO_APPROVAL',
            'discount_total' => 'nullable|numeric|min:0',
            'discount_details' => 'nullable|json',
            
            // Charges
            'management_service_charge' => 'required|numeric|min:0',
            'employee_service_charge' => 'required|numeric|min:0',
            'other_tax' => 'nullable|numeric|min:0',
            
            // Additional Required Fields
            'vat_amount' => 'required|numeric|min:0',
            'transaction_count' => 'required|integer|min:1',
            'payload_checksum' => 'required|string',
            'validation_status' => 'required|string|in:VALID,INVALID',
            'error_code' => 'nullable|string',
            
            // Optional Fields
            'machine_number' => 'nullable|integer',
            'store_name' => 'nullable|string'
        ];
    }
}