<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class TransactionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'tenant_id' => 'required',  // Remove string validation
            'terminal_id' => 'required|string',
            'hardware_id' => 'required|string',
            'transaction_id' => 'required|string',
            'transaction_timestamp' => 'required|date',
            'transaction_date' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'type' => 'required|string',
            'gross_sales' => 'required|numeric|min:0',
            'net_sales' => 'required|numeric|min:0',
            'vatable_sales' => 'required|numeric|min:0',
            'vat_exempt_sales' => 'required|numeric|min:0',
            'vat_amount' => 'required|numeric|min:0',
            'transaction_count' => 'required|integer|min:1',
            'payload_checksum' => 'required|string',
            'machine_number' => 'required|integer'
        ];
    }

    protected function failedValidation($validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Transaction processing failed',
            'errors' => $validator->errors()
        ], 422));
    }
}