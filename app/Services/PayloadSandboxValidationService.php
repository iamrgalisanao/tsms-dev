<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PosTerminal;
use App\Rules\ReceiptNumber;
use Illuminate\Support\Str;

class PayloadSandboxValidationService
{
    public function __construct(private readonly PayloadChecksumService $checksumService)
    {
    }

    public function validate(array $payload, bool $includeDebug = false): array
    {
        $errors = [];
        $warnings = [];

        $this->validateStructure($payload, $errors);
        $this->validateContract($payload, $errors, $warnings);

        $checksums = $this->checksumDiagnostics($payload);
        if (($checksums['transaction']['matches'] ?? false) === false) {
            $errors[] = [
                'code' => 'TRANSACTION_CHECKSUM_MISMATCH',
                'severity' => 'error',
                'pointer' => '/transaction/payload_checksum',
                'message' => 'Transaction checksum does not match the V2.1 canonical transaction object.',
                'expected' => $checksums['transaction']['computed'],
                'actual' => $checksums['transaction']['provided'],
                'hint' => 'Remove only transaction.payload_checksum, canonicalize the remaining transaction object, then SHA-256 hash the compact JSON.',
            ];
        }

        if (($checksums['submission']['matches'] ?? false) === false) {
            $errors[] = [
                'code' => 'SUBMISSION_CHECKSUM_MISMATCH',
                'severity' => 'error',
                'pointer' => '/payload_checksum',
                'message' => 'Root submission checksum does not match the V2.1 canonical submission object.',
                'expected' => $checksums['submission']['computed'],
                'actual' => $checksums['submission']['provided'],
                'hint' => 'Compute transaction.payload_checksum first. Then remove only the root payload_checksum and hash the full submission including transaction.payload_checksum.',
            ];
        }

        if (($checksums['transaction']['matches'] ?? false) === false && ($checksums['submission']['matches'] ?? false) === false) {
            $warnings[] = [
                'code' => 'CHECKSUM_CASCADE',
                'severity' => 'warning',
                'pointer' => '/payload_checksum',
                'message' => 'Root checksum usually fails when transaction.payload_checksum is wrong because the root checksum includes the transaction checksum.',
            ];
        }

        $this->validateBusinessReconciliation($payload, $errors, $warnings);

        $report = [
            'success' => true,
            'valid' => empty($errors),
            'validation_id' => 'val_' . (string) Str::ulid(),
            'version' => 'v2.1',
            'summary' => [
                'error_count' => count($errors),
                'warning_count' => count($warnings),
            ],
            'checks' => [
                'schema' => $this->hasAnyErrorWithCodes($errors, [
                    'MISSING_REQUIRED_FIELD',
                    'INVALID_FIELD_TYPE',
                    'INVALID_UUID_FORMAT',
                    'INVALID_AMOUNT_FORMAT',
                    'INVALID_CHECKSUM_FORMAT',
                    'BATCH_NOT_SUPPORTED',
                    'INVALID_TIMESTAMP_FORMAT',
                ]) ? 'failed' : 'passed',
                'checksum' => (($checksums['transaction']['matches'] ?? false) && ($checksums['submission']['matches'] ?? false)) ? 'passed' : 'failed',
                'contract' => $this->hasAnyErrorWithCodes($errors, [
                    'HARDWARE_ID_MISSING_IN_TRANSACTION',
                    'TENANT_TERMINAL_MISMATCH',
                    'INVALID_ENUM_VALUE',
                ]) ? 'failed' : 'passed',
                'business_rules' => $this->hasAnyErrorWithCodes($errors, [
                    'VAT_RECONCILIATION_FAILED',
                    'AMOUNT_RECONCILIATION_FAILED',
                ]) ? 'failed' : 'passed',
            ],
            'errors' => $errors,
            'warnings' => $warnings,
            'checksums' => $checksums,
        ];

        if ($includeDebug) {
            $report['debug'] = $this->debugCanonicalPayloads($payload);
        }

        return $report;
    }

    private function validateStructure(array $payload, array &$errors): void
    {
        foreach ([
            'submission_uuid' => 'string',
            'tenant_id' => 'integer',
            'terminal_id' => 'integer',
            'submission_timestamp' => 'string',
            'transaction_count' => 'integer',
            'payload_checksum' => 'string',
        ] as $field => $type) {
            if (!array_key_exists($field, $payload)) {
                $errors[] = $this->error('MISSING_REQUIRED_FIELD', '/' . $field, "{$field} is required.", $type, null);
                continue;
            }

            if (!$this->matchesType($payload[$field], $type)) {
                $errors[] = $this->error('INVALID_FIELD_TYPE', '/' . $field, "{$field} must be {$type}.", $type, gettype($payload[$field]));
            }
        }

        if (($payload['transaction_count'] ?? null) !== 1) {
            $errors[] = $this->error('BATCH_NOT_SUPPORTED', '/transaction_count', 'V2.1 Phase 1 requires transaction_count to be exactly 1.', 1, $payload['transaction_count'] ?? null);
        }

        if (
            isset($payload['submission_timestamp']) &&
            is_string($payload['submission_timestamp']) &&
            !$this->isProductionTimestamp($payload['submission_timestamp'])
        ) {
            $errors[] = $this->error(
                'INVALID_TIMESTAMP_FORMAT',
                '/submission_timestamp',
                'submission_timestamp must match production format Y-m-d\\TH:i:s\\Z without milliseconds.',
                'YYYY-MM-DDTHH:MM:SSZ',
                $payload['submission_timestamp']
            );
        }

        if (array_key_exists('transactions', $payload)) {
            $errors[] = $this->error('BATCH_NOT_SUPPORTED', '/transactions', 'Use transaction object, not transactions array, for V2.1 Phase 1.', 'field omitted', 'transactions present');
        }

        if (!isset($payload['transaction']) || !is_array($payload['transaction'])) {
            $errors[] = $this->error('MISSING_REQUIRED_FIELD', '/transaction', 'transaction object is required.', 'object', $payload['transaction'] ?? null);
            return;
        }

        $transaction = $payload['transaction'];
        foreach ([
            'transaction_id' => 'string',
            'transaction_timestamp' => 'string',
            'gross_sales' => 'money',
            'net_sales' => 'money',
            'promo_status' => 'string',
            'customer_code' => 'string',
            'receipt_no' => 'string',
            'payload_checksum' => 'string',
            'adjustments' => 'array',
            'taxes' => 'array',
        ] as $field => $type) {
            if (!array_key_exists($field, $transaction)) {
                $errors[] = $this->error('MISSING_REQUIRED_FIELD', '/transaction/' . $field, "transaction.{$field} is required.", $type, null);
                continue;
            }

            if ($type === 'money' && !$this->isMoneyString($transaction[$field])) {
                $errors[] = $this->error('INVALID_AMOUNT_FORMAT', '/transaction/' . $field, "transaction.{$field} must be a string with exactly two decimal places.", '0.00 string', $transaction[$field]);
                continue;
            }

            if ($type !== 'money' && !$this->matchesType($transaction[$field], $type)) {
                $errors[] = $this->error('INVALID_FIELD_TYPE', '/transaction/' . $field, "transaction.{$field} must be {$type}.", $type, gettype($transaction[$field]));
            }
        }

        if (
            isset($transaction['transaction_id']) &&
            is_string($transaction['transaction_id']) &&
            !$this->isUuidV4($transaction['transaction_id'])
        ) {
            $errors[] = $this->error(
                'INVALID_UUID_FORMAT',
                '/transaction/transaction_id',
                'transaction.transaction_id must be a valid UUID v4.',
                'UUID v4 string',
                $transaction['transaction_id']
            );
        }

        if (
            isset($transaction['transaction_timestamp']) &&
            is_string($transaction['transaction_timestamp']) &&
            !$this->isProductionTimestamp($transaction['transaction_timestamp'])
        ) {
            $errors[] = $this->error(
                'INVALID_TIMESTAMP_FORMAT',
                '/transaction/transaction_timestamp',
                'transaction.transaction_timestamp must match production format Y-m-d\\TH:i:s\\Z without milliseconds.',
                'YYYY-MM-DDTHH:MM:SSZ',
                $transaction['transaction_timestamp']
            );
        }

        if (
            array_key_exists('receipt_no', $transaction) &&
            ! (new ReceiptNumber())->passes('transaction.receipt_no', $transaction['receipt_no'])
        ) {
            $errors[] = $this->error(
                'INVALID_RECEIPT_FORMAT',
                '/transaction/receipt_no',
                'transaction.receipt_no is required and must contain only letters, numbers, dashes, or dots, up to 128 characters.',
                '^[A-Za-z0-9\\-.]{1,128}$',
                $transaction['receipt_no']
            );
        }

        $this->validateChecksumFormat($transaction['payload_checksum'] ?? null, '/transaction/payload_checksum', $errors);
        $this->validateChecksumFormat($payload['payload_checksum'] ?? null, '/payload_checksum', $errors);
        $this->validateRows($transaction['adjustments'] ?? [], 'adjustments', 'adjustment_type', $errors);
        $this->validateRows($transaction['taxes'] ?? [], 'taxes', 'tax_type', $errors);
    }

    private function validateContract(array $payload, array &$errors, array &$warnings): void
    {
        $transaction = is_array($payload['transaction'] ?? null) ? $payload['transaction'] : [];

        if (!array_key_exists('hardware_id', $transaction) || $transaction['hardware_id'] === null || $transaction['hardware_id'] === '') {
            $errors[] = $this->error(
                'HARDWARE_ID_MISSING_IN_TRANSACTION',
                '/transaction/hardware_id',
                'hardware_id is required inside transaction.',
                'non-empty string',
                $transaction['hardware_id'] ?? null
            );

            if (array_key_exists('hardware_id', $payload)) {
                $warnings[] = [
                    'code' => 'HARDWARE_ID_AT_ROOT_ONLY',
                    'severity' => 'warning',
                    'pointer' => '/hardware_id',
                    'message' => 'hardware_id was found at the root, but V2.1 requires it inside transaction.',
                ];
            }
        }

        if (isset($payload['terminal_id'], $payload['tenant_id']) && is_int($payload['terminal_id']) && is_int($payload['tenant_id'])) {
            $terminal = PosTerminal::find($payload['terminal_id']);
            if ($terminal && (int) $terminal->tenant_id !== (int) $payload['tenant_id']) {
                $errors[] = $this->error(
                    'TENANT_TERMINAL_MISMATCH',
                    '/tenant_id',
                    'terminal_id does not belong to the provided tenant_id.',
                    (int) $terminal->tenant_id,
                    (int) $payload['tenant_id']
                );
            }
        }

        $promoStatus = $transaction['promo_status'] ?? null;
        if ($promoStatus !== null && !in_array($promoStatus, ['NONE', 'WITH_APPROVAL', 'WITHOUT_APPROVAL'], true)) {
            $errors[] = $this->error('INVALID_ENUM_VALUE', '/transaction/promo_status', 'promo_status is not an accepted V2.1 value.', ['NONE', 'WITH_APPROVAL', 'WITHOUT_APPROVAL'], $promoStatus);
        }
    }

    private function validateBusinessReconciliation(array $payload, array &$errors, array &$warnings): void
    {
        $transaction = is_array($payload['transaction'] ?? null) ? $payload['transaction'] : [];
        if (!$this->isMoneyString($transaction['gross_sales'] ?? null) || !$this->isMoneyString($transaction['net_sales'] ?? null)) {
            return;
        }

        $taxes = $this->taxBuckets($transaction['taxes'] ?? []);
        $adjustmentSum = $this->sumAmounts($transaction['adjustments'] ?? []);
        $gross = (float) $transaction['gross_sales'];
        $net = (float) $transaction['net_sales'];
        $vat = $taxes['VAT'] ?? 0.0;
        $vatable = $taxes['VATABLE_SALES'] ?? 0.0;
        $exempt = $taxes['SC_VAT_EXEMPT_SALES'] ?? 0.0;
        $otherTax = $taxes['OTHER_TAX'] ?? 0.0;
        $netIncludesVat = (bool) config('tsms.validation.net_includes_vat', true);
        $strictComputation = (bool) config('tsms.validation.enable_computation_validation', false);

        if ($vat > 0.0 && $vatable <= 0.0) {
            $this->addReconciliationFinding($errors, $warnings, $strictComputation, [
                'code' => 'VAT_RECONCILIATION_FAILED',
                'pointer' => '/transaction/taxes',
                'message' => 'VAT is greater than zero but VATABLE_SALES is zero.',
                'expected' => ['VATABLE_SALES' => number_format(max($net - $vat - $exempt, 0), 2, '.', '')],
                'actual' => ['VATABLE_SALES' => number_format($vatable, 2, '.', '')],
            ]);
        }

        $expectedNet = $netIncludesVat
            ? round($vatable + $exempt + $vat, 2)
            : round($vatable + $exempt, 2);

        if (($vatable > 0.0 || $exempt > 0.0 || $vat > 0.0) && abs($net - $expectedNet) > 0.01) {
            $this->addReconciliationFinding($errors, $warnings, $strictComputation, [
                'code' => 'AMOUNT_RECONCILIATION_FAILED',
                'pointer' => '/transaction/net_sales',
                'message' => $netIncludesVat
                    ? 'net_sales does not reconcile with VATABLE_SALES, SC_VAT_EXEMPT_SALES, and VAT.'
                    : 'net_sales does not reconcile with VATABLE_SALES and SC_VAT_EXEMPT_SALES.',
                'expected' => number_format($expectedNet, 2, '.', ''),
                'actual' => number_format($net, 2, '.', ''),
            ]);
        }

        $expectedGross = $netIncludesVat
            ? round($net + $adjustmentSum + $otherTax, 2)
            : round($net + $vat + $adjustmentSum + $otherTax, 2);

        if (abs($gross - $expectedGross) > 0.01) {
            $warnings[] = [
                'code' => 'GROSS_RECONCILIATION_WARNING',
                'severity' => 'warning',
                'pointer' => '/transaction/gross_sales',
                'message' => $netIncludesVat
                    ? 'gross_sales does not equal net_sales plus adjustments and OTHER_TAX under the sandbox reconciliation formula.'
                    : 'gross_sales does not equal net_sales plus VAT, adjustments, and OTHER_TAX under the sandbox reconciliation formula.',
                'expected' => number_format($expectedGross, 2, '.', ''),
                'actual' => number_format($gross, 2, '.', ''),
            ];
        }
    }

    private function addReconciliationFinding(array &$errors, array &$warnings, bool $strict, array $finding): void
    {
        $finding['severity'] = $strict ? 'error' : 'warning';

        if ($strict) {
            $errors[] = $finding;
            return;
        }

        $warnings[] = $finding;
    }

    private function checksumDiagnostics(array $payload): array
    {
        $transaction = is_array($payload['transaction'] ?? null) ? $payload['transaction'] : [];
        $transactionCopy = $transaction;
        unset($transactionCopy['payload_checksum']);
        $computedTransaction = $transaction ? $this->checksumService->computeChecksum($transactionCopy) : null;

        $submissionCopy = $payload;
        unset($submissionCopy['payload_checksum']);
        $computedSubmission = $this->checksumService->computeChecksum($submissionCopy);

        return [
            'transaction' => [
                'provided' => $transaction['payload_checksum'] ?? null,
                'computed' => $computedTransaction,
                'matches' => is_string($transaction['payload_checksum'] ?? null) && is_string($computedTransaction) && hash_equals($computedTransaction, $transaction['payload_checksum']),
                'safe_to_copy' => false,
            ],
            'submission' => [
                'provided' => $payload['payload_checksum'] ?? null,
                'computed' => $computedSubmission,
                'matches' => is_string($payload['payload_checksum'] ?? null) && hash_equals($computedSubmission, $payload['payload_checksum']),
                'safe_to_copy' => false,
            ],
        ];
    }

    private function debugCanonicalPayloads(array $payload): array
    {
        $transaction = is_array($payload['transaction'] ?? null) ? $payload['transaction'] : [];
        $transactionCopy = $transaction;
        unset($transactionCopy['payload_checksum']);

        $submissionCopy = $payload;
        unset($submissionCopy['payload_checksum']);

        $canonicalTransaction = $transaction ? $this->checksumService->getCanonicalized($transactionCopy) : null;
        $canonicalSubmission = $this->checksumService->getCanonicalized($submissionCopy);

        return [
            'canonical_transaction' => $canonicalTransaction,
            'canonical_transaction_json' => $canonicalTransaction === null ? null : json_encode($canonicalTransaction, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'canonical_submission' => $canonicalSubmission,
            'canonical_submission_json' => json_encode($canonicalSubmission, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    private function validateRows(mixed $rows, string $collection, string $typeField, array &$errors): void
    {
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $errors[] = $this->error('INVALID_FIELD_TYPE', "/transaction/{$collection}/{$index}", "{$collection} row must be an object.", 'object', gettype($row));
                continue;
            }

            if (!isset($row[$typeField]) || !is_string($row[$typeField]) || $row[$typeField] === '') {
                $errors[] = $this->error('MISSING_REQUIRED_FIELD', "/transaction/{$collection}/{$index}/{$typeField}", "{$collection} row requires {$typeField}.", 'non-empty string', $row[$typeField] ?? null);
            }

            if (!array_key_exists('amount', $row) || !$this->isMoneyString($row['amount'])) {
                $errors[] = $this->error('INVALID_AMOUNT_FORMAT', "/transaction/{$collection}/{$index}/amount", "{$collection} amount must be a string with exactly two decimal places.", '0.00 string', $row['amount'] ?? null);
            }
        }
    }

    private function validateChecksumFormat(mixed $value, string $pointer, array &$errors): void
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/', $value)) {
            $errors[] = $this->error('INVALID_CHECKSUM_FORMAT', $pointer, 'payload_checksum must be a 64-character lowercase SHA-256 hex string.', '64 lowercase hex characters', $value);
        }
    }

    private function taxBuckets(mixed $taxes): array
    {
        $buckets = [];
        if (!is_array($taxes)) {
            return $buckets;
        }

        foreach ($taxes as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtoupper(trim((string) ($row['tax_type'] ?? '')));
            $amount = is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : 0.0;
            $buckets[$type] = ($buckets[$type] ?? 0.0) + $amount;
        }

        return $buckets;
    }

    private function sumAmounts(mixed $rows): float
    {
        if (!is_array($rows)) {
            return 0.0;
        }

        return array_reduce($rows, function (float $sum, mixed $row): float {
            return $sum + (is_array($row) && is_numeric($row['amount'] ?? null) ? (float) $row['amount'] : 0.0);
        }, 0.0);
    }

    private function isMoneyString(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d+\.\d{2}$/', $value) === 1;
    }

    private function isUuidV4(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function isProductionTimestamp(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) === 1;
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'array' => is_array($value),
            default => false,
        };
    }

    private function hasAnyErrorWithCodes(array $errors, array $codes): bool
    {
        foreach ($errors as $error) {
            if (in_array($error['code'] ?? null, $codes, true)) {
                return true;
            }
        }

        return false;
    }

    private function error(string $code, string $pointer, string $message, mixed $expected, mixed $actual): array
    {
        return [
            'code' => $code,
            'severity' => 'error',
            'pointer' => $pointer,
            'message' => $message,
            'expected' => $expected,
            'actual' => $actual,
        ];
    }
}
