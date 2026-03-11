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
        // Basic structure validation only - detailed validation moved to controller
        return [
            'submission_uuid' => 'required|string|uuid',
            'tenant_id' => 'required|integer',
            'terminal_id' => 'required|integer|exists:pos_terminals,id',
            'submission_timestamp' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?$/'],
            'transaction_count' => 'required|integer|min:1',
            'payload_checksum' => 'required|string|min:64|max:64',
        ];
    }

    // All complex validation logic moved to TransactionController
    // This ensures proper audit trail creation for validation failures

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
