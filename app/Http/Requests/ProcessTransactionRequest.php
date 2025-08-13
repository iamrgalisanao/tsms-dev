<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessTransactionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Required Fields
            'customer_code' => 'required|string|exists:customers,code',
            'terminal_id' => 'required|integer|exists:pos_terminals,id',
            'hardware_id' => 'required|string',
            // Enforce RFC 4122 UUID format & uniqueness in transactions table
            'transaction_id' => 'required|string|uuid|unique:transactions,transaction_id',
            'transaction_timestamp' => 'required|date',
            'base_amount' => 'required|numeric|min:0',

            // Optional/Related Fields
            'payload_checksum' => 'required|string',
            'machine_number' => 'nullable|integer',
        ];
    }
}