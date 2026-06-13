<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Service to compute and validate SHA-256 payload checksums
 * for transaction submissions.
 */
class PayloadChecksumService
{
    private const SUPPORTED_VERSIONS = ['v2.1', 'v2.0'];

    /**
     * Public wrapper for canonicalize (for debugging/external use)
     */
    public function getCanonicalized($data, string $version = 'v2.1')
    {
        return $this->canonicalize($data, $version);
    }

    /**
     * Validate checksums from raw JSON string (canonicalize from original input).
     *
     * @param string $rawJson
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateSubmissionChecksumsFromRaw(string $rawJson): array
    {
        $submission = json_decode($rawJson, true);

        if (! is_array($submission) || json_last_error() !== JSON_ERROR_NONE) {
            return [
                'valid' => false,
                'errors' => ['Invalid JSON payload: ' . json_last_error_msg()],
                'diagnostics' => [],
            ];
        }

        return $this->validateSubmissionChecksums($submission);
    }

    /**
     * Validate both transaction and submission checksums.
     *
     * @param  array  $submission  The decoded submission payload
     * @return array  ['valid' => bool, 'errors' => array]
     */
    public function validateSubmissionChecksums(array $submission): array
    {
        $attempts = [];

        foreach (self::SUPPORTED_VERSIONS as $version) {
            $result = $this->validateWithVersion($submission, $version);
            $attempts[$version] = $result['diagnostics'];

            if ($result['valid']) {
                if ($version !== 'v2.1') {
                    \Log::info('Checksum validated via compatibility fallback', [
                        'submission_uuid' => $submission['submission_uuid'] ?? null,
                        'checksum_version' => $version,
                    ]);
                }

                return [
                    'valid' => true,
                    'errors' => [],
                    'checksum_version' => $version,
                    'diagnostics' => $attempts,
                ];
            }
        }

        $strictResult = $this->validateWithVersion($submission, 'v2.1');

        return [
            'valid' => false,
            'errors' => $strictResult['errors'],
            'checksum_version' => null,
            'diagnostics' => $attempts,
        ];
    }

    private function validateWithVersion(array $submission, string $version): array
    {
        $errors = [];
        $diagnostics = [
            'version' => $version,
            'transactions' => [],
            'submission' => null,
        ];

        if (isset($submission['transaction'])) {
            $txn = $submission['transaction'];
            $txnCopy = $txn;
            unset($txnCopy['payload_checksum']);
            $computedTxn = $this->computeChecksum($txnCopy, $version);
            $providedTxn = $txn['payload_checksum'] ?? null;
            $txnMatches = $this->checksumsMatch($providedTxn, $computedTxn);

            $diagnostics['transactions'][] = [
                'index' => 0,
                'transaction_id' => $txn['transaction_id'] ?? null,
                'provided' => $providedTxn,
                'computed' => $computedTxn,
                'matches' => $txnMatches,
            ];

            if (! $txnMatches) {
                $errors[] = $this->formatChecksumError('transaction', $version, $providedTxn, $computedTxn);
            }

            $submissionCopy = $submission;
            unset($submissionCopy['payload_checksum']);
            $computedSubmission = $this->computeChecksum($submissionCopy, $version);
            $providedSubmission = $submission['payload_checksum'] ?? null;
            $submissionMatches = $this->checksumsMatch($providedSubmission, $computedSubmission);

            $diagnostics['submission'] = [
                'provided' => $providedSubmission,
                'computed' => $computedSubmission,
                'matches' => $submissionMatches,
            ];

            if (! $submissionMatches) {
                $errors[] = $this->formatChecksumError('submission', $version, $providedSubmission, $computedSubmission);
            }

            return [
                'valid'  => empty($errors),
                'errors' => $errors,
                'diagnostics' => $diagnostics,
            ];
        }

        if (isset($submission['transactions']) && is_array($submission['transactions'])) {
            $allTxnValid = true;
            foreach ($submission['transactions'] as $i => $txn) {
                $txnCopy = $txn;
                unset($txnCopy['payload_checksum']);
                $computedTxn = $this->computeChecksum($txnCopy, $version);
                $providedTxn = $txn['payload_checksum'] ?? null;
                $txnMatches = $this->checksumsMatch($providedTxn, $computedTxn);

                $diagnostics['transactions'][] = [
                    'index' => $i,
                    'transaction_id' => $txn['transaction_id'] ?? null,
                    'provided' => $providedTxn,
                    'computed' => $computedTxn,
                    'matches' => $txnMatches,
                ];

                if (! $txnMatches) {
                    $errors[] = $this->formatChecksumError("transaction at index {$i}", $version, $providedTxn, $computedTxn);
                    $allTxnValid = false;
                }
            }

            if ($allTxnValid) {
                $submissionCopy = $submission;
                unset($submissionCopy['payload_checksum']);
                $computedSubmission = $this->computeChecksum($submissionCopy, $version);
                $providedSubmission = $submission['payload_checksum'] ?? null;
                $submissionMatches = $this->checksumsMatch($providedSubmission, $computedSubmission);

                $diagnostics['submission'] = [
                    'provided' => $providedSubmission,
                    'computed' => $computedSubmission,
                    'matches' => $submissionMatches,
                ];

                if (! $submissionMatches) {
                    $errors[] = $this->formatChecksumError('submission', $version, $providedSubmission, $computedSubmission);
                }
            }

            return [
                'valid'  => empty($errors),
                'errors' => $errors,
                'diagnostics' => $diagnostics,
            ];
        }

        return [
            'valid' => false,
            'errors' => ['Unsupported payload structure: expected transaction or transactions'],
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Compute SHA-256 checksum of the payload after canonicalization.
     *
     * @param  mixed  $payload  Array or scalar data
     * @return string  Hexadecimal SHA-256 hash
     */
    public function computeChecksum($payload, string $version = 'v2.1'): string
    {
        $canonical = $this->canonicalize($payload, $version);

        return hash('sha256', json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    /**
     * Recursively canonicalize data for consistent JSON serialization:
     * - Sort associative arrays by key
     * - Preserve indexed arrays order
     * - v2.1 formats monetary values as two-decimal strings
     * - v2.0 casts monetary values to floats
     *
     * @param  mixed  $data
     * @return mixed
     */
    private function canonicalize($data, string $version = 'v2.1')
    {
        if (is_array($data)) {
            $canonical = $data;

            if ($this->isAssoc($canonical)) {
                ksort($canonical);
            }

            foreach ($canonical as $key => $value) {
                $value = $this->canonicalize($value, $version);

                if ($version === 'v2.1' && in_array($key, ['gross_sales', 'net_sales', 'amount'], true) && is_numeric($value)) {
                    $value = number_format((float) $value, 2, '.', '');
                } elseif ($version === 'v2.0' && in_array($key, ['gross_sales', 'net_sales', 'amount'], true)) {
                    $value = (float) $value;
                }

                $canonical[$key] = $value;
            }

            return $canonical;
        }

        return $data;
    }

    private function checksumsMatch(?string $provided, string $computed): bool
    {
        if ($provided === null || $provided === '') {
            return false;
        }

        return hash_equals(strtolower($computed), strtolower($provided));
    }

    private function formatChecksumError(string $scope, string $version, ?string $provided, string $computed): string
    {
        $received = $provided ?: 'missing';

        return "Invalid payload_checksum for {$scope} ({$version}). Received: {$received}, Computed: {$computed}";
    }

    /**
     * Determine if an array is associative.
     *
     * @param  array  $array
     * @return bool
     */
    private function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}
