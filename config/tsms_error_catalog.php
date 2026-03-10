<?php

return [
    // High-level categories
    'categories' => [
        'PAYLOAD_VALIDATION' => 'Payload validation error',
        'CHECKSUM' => 'Checksum mismatch or invalid checksum',
        'DUPLICATE' => 'Duplicate submission or transaction',
        'AUTH' => 'Authentication or authorization error',
        'FORWARDING' => 'Forwarding / webhook error',
        'SYSTEM_ERROR' => 'Unexpected system error',
    ],

    // Map reason_code or log_type to human-friendly descriptions
    'reasons' => [
        'PARTIAL_FAILURE' => [
            'category' => 'PAYLOAD_VALIDATION',
            'title' => 'Some transactions in this submission failed',
            'message' => 'One or more transactions in this submission could not be processed. See per-transaction details for the exact reasons.',
            'action' => 'Open the submission items view and review each FAILED transaction. Correct the payload for the failed items and resend them with a new submission_uuid.',
        ],
        'CHECKSUM_MISMATCH' => [
            'category' => 'CHECKSUM',
            'title' => 'Checksum does not match the payload',
            'message' => 'The payload_checksum sent by the POS does not match the checksum TSMS computes from the submitted data.',
            'action' => 'Recompute the checksum using the TSMS checksum algorithm, update payload_checksum, and resend the submission with a new submission_uuid.',
        ],
        'DUPLICATE_SUBMISSION' => [
            'category' => 'DUPLICATE',
            'title' => 'Duplicate submission UUID detected',
            'message' => 'A submission with the same submission_uuid was already processed for this terminal.',
            'action' => 'If this is a retry of the exact same payload, you can ignore this message. If the payload has changed, generate a new submission_uuid before resending.',
        ],
        'DUPLICATE_IDENTITY' => [
            'category' => 'DUPLICATE',
            'title' => 'Duplicate transaction detected',
            'message' => 'A transaction with the same identity was already recorded in TSMS.',
            'action' => 'Confirm whether the transaction was already sent. If it was, do not resend it. If you need to correct it, send a void or refund transaction instead of duplicating the sale.',
        ],
        'PROCESSING_ERROR' => [
            'category' => 'SYSTEM_ERROR',
            'title' => 'Transaction failed during processing',
            'message' => 'TSMS encountered an error while processing this transaction.',
            'action' => 'Check the detailed error message and transaction payload. Fix any data issues and resend the transaction, or contact TSMS support if the error persists.',
        ],
        'VALIDATION_ERROR' => [
            'category' => 'PAYLOAD_VALIDATION',
            'title' => 'Payload failed validation checks',
            'message' => 'One or more required fields are missing or invalid in the request payload.',
            'action' => 'Review the validation errors in the API response or dashboard. Correct the invalid or missing fields and resend the request.',
        ],
        'OFFICIAL_TRANSACTION_INGESTION_FAILED' => [
            'category' => 'SYSTEM_ERROR',
            'title' => 'Official TSMS transaction failed ingestion',
            'message' => 'An error occurred while saving or validating a transaction submitted in official TSMS format.',
            'action' => 'Review the transaction details in the dashboard, fix any invalid data, and resend the transaction. Contact TSMS support if you need help interpreting the error.',
        ],
    ],
];
