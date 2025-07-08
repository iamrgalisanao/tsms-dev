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
            'customer_code' => 'required|string|exists:customers,code',
            'terminal_id' => 'required|integer|exists:pos_terminals,id',
            'hardware_id' => 'required|string',
            'transaction_id' => 'required|string',
            'transaction_timestamp' => 'required|date',
            'base_amount' => 'required|numeric|min:0',
            'payload_checksum' => 'required|string',
            'machine_number' => 'nullable|integer',
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