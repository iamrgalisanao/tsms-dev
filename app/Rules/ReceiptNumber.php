<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * Validates that a receipt number is present, non-empty, and follows
 * the standard TSMS alphanumeric/dash format.
 */
class ReceiptNumber implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value)
    {
        // Must be non-null and a string
        if ($value === null || !is_string($value)) {
            return false;
        }

        // Must not be empty or whitespace-only
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        // Basic alphanumeric and dash constraint (V2.2 Spec: Alphanumeric/Dashes, max 128 chars)
        return (bool) preg_match('/^[A-Za-z0-9\-\.]{1,128}$/', $trimmed);
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message()
    {
        return 'The :attribute is required and must follow the standard alphanumeric format (Max 128 chars).';
    }
}
