<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class TransactionValidationService
{
    public function validate(array $payload): array
    {
        $requiredFields = [
            'tenant_id',
            'hardware_id',
            'machine_number',
            'transaction_id',
            'store_name',
            'transaction_timestamp',
            'gross_sales',
            'net_sales',
            'vatable_sales',
            'vat_exempt_sales',
            'promo_discount_amount',
            'promo_status',
            'discount_total',
            'discount_details',
            'other_tax',
            'management_service_charge',
            'employee_service_charge',
            'vat_amount',
            'transaction_count',
            'payload_checksum',
        ];

        $errors = [];

        // Check for missing fields
        foreach ($requiredFields as $field) {
            if (!isset($payload[$field])) {
                $errors[] = "Missing required field: $field";
            }
        }

        // Check checksum
        $originalChecksum = $payload['payload_checksum'] ?? null;
        $payloadCopy = $payload;
        unset($payloadCopy['payload_checksum']);

        $computedChecksum = hash('sha256', json_encode($payloadCopy));

        if ($originalChecksum !== $computedChecksum) {
            $errors[] = 'Checksum mismatch';
        }

        return [
            'validation_status' => empty($errors) ? 'VALID' : 'ERROR',
            'error_code' => empty($errors) ? null : 'ERR-102',
            'computed_checksum' => $computedChecksum,
            'errors' => $errors,
        ];
    }
}
