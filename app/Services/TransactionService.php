<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class TransactionService
{
    public function store(array $payload): Transaction
    {
        // Exclude any system-computed fields from the payload for checksum
        $checksumInput = collect($payload)
            ->except(['payload_checksum', 'validation_status', 'error_code'])
            ->sortKeys() // Ensure consistent order
            ->toJson();

        // Compute SHA-256 hash
        $payload['payload_checksum'] = hash('sha256', $checksumInput);

        // Log the computed checksum for debugging
        Log::info('Computed payload_checksum:', ['checksum' => $payload['payload_checksum']]);

        return Transaction::create($payload);
    }
}
