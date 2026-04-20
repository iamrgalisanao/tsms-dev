<?php

namespace App\Http\Requests;

use App\Http\Requests\TSMSTransactionRequest;
use App\Rules\UuidV4;
use App\Rules\ReceiptNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class TSMSTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Enforces strict binding between the API token and the reported terminal_id.
     */
    public function authorize(): bool
    {
        $authenticatedTerminal = $this->user();
        
        // Block if not authenticated or if terminal_id in payload doesn't match the token holder
        if (!$authenticatedTerminal || (int)$this->input('terminal_id') !== (int)$authenticatedTerminal->id) {
            return false;
        }

        return true;
    }

    /**
     * Handle a failed authorization attempt.
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Terminal Identity Mismatch: The terminal_id provided in the payload does not match the identity of the authenticated API token. Token sharing across multiple terminals is strictly prohibited.',
                'error_code' => 'TERMINAL_TOKEN_MISMATCH'
            ], 403)
        );
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // Basic structure validation only - detailed validation moved to controller
        return [
            'submission_uuid' => ['required', 'string', new UuidV4()],
            'tenant_id' => 'required|integer',
            'terminal_id' => 'required|integer|exists:pos_terminals,id',
            'submission_timestamp' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?$/'],
            'transaction_count' => 'required|integer|min:1',
            'payload_checksum' => 'required|string|min:64|max:64|regex:/^[0-9a-f]{64}$/i',
            'transaction.receipt_no' => ['required', new ReceiptNumber()],
        ];
    }

    // All complex validation logic moved to TransactionController
    // This ensures proper audit trail creation for validation failures

    /**
     * Handle a failed validation attempt.
     */
    protected function failedValidation(Validator $validator): void
    {
        \Log::warning('TSMSTransactionRequest: Validation failed', [
            'errors' => $validator->errors()->toArray(),
            'submission_uuid' => $this->input('submission_uuid'),
            'terminal_id' => $this->input('terminal_id'),
            'ip' => $this->ip(),
        ]);

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
