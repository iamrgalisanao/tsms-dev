<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

trait LogsValidation
{
    protected function logValidationResult(string $transactionId, string $type, array $result): void
    {
        Log::info("Transaction validation: {$type}", [
            'transaction_id' => $transactionId,
            'type' => $type,
            'result' => $result
        ]);
    }
}