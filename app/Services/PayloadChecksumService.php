<?php

namespace App\Services;

/**
 * Service to compute and validate SHA-256 payload checksums
 * for transaction submissions.
 */
class PayloadChecksumService
{
    /**
     * Validate checksums from raw JSON string (canonicalize from original input).
     *
     * @param string $rawJson
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateSubmissionChecksumsFromRaw(string $rawJson): array
    {
        $parsed = json_decode($rawJson, true);
        $errors = [];

        // --- Transaction checksum ---
        if (isset($parsed['transaction'])) {
            $txn = $parsed['transaction'];
            unset($txn['payload_checksum']);
            $computedTxn = $this->computeChecksum($txn);

            if (!isset($parsed['transaction']['payload_checksum']) || $parsed['transaction']['payload_checksum'] !== $computedTxn) {
                $errors[] = 'Invalid payload_checksum for transaction';
            }
        } else {
            $errors[] = 'Missing transaction data';
        }

        // --- Submission checksum ---
        $copy = $parsed;
        unset($copy['payload_checksum']);
        $computedRoot = $this->computeChecksum($copy);

        if (!isset($parsed['payload_checksum']) || $parsed['payload_checksum'] !== $computedRoot) {
            $errors[] = 'Invalid submission payload_checksum';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    /**
     * Validate both transaction and submission checksums.
     *
     * @param  array  $submission  The decoded submission payload
     * @return array  ['valid' => bool, 'errors' => array]
     */
    public function validateSubmissionChecksums(array $submission): array
    {
        $errors = [];

        // Validate transaction-level checksum
        $txn = $submission['transaction'];
        $txnCopy = $txn;
        unset($txnCopy['payload_checksum']);
        $computedTxn = $this->computeChecksum($txnCopy);

        if (!isset($txn['payload_checksum']) || $txn['payload_checksum'] !== $computedTxn) {
            $errors[] = 'Invalid payload_checksum for transaction';
        }

        // Validate submission-level checksum
        $submissionCopy = $submission;
        unset($submissionCopy['payload_checksum']);
        $computedSubmission = $this->computeChecksum($submissionCopy);

        if (!isset($submission['payload_checksum']) || $submission['payload_checksum'] !== $computedSubmission) {
            $errors[] = 'Invalid submission payload_checksum';
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Compute SHA-256 checksum of the payload after canonicalization.
     *
     * @param  mixed  $payload  Array or scalar data
     * @return string  Hexadecimal SHA-256 hash
     */
    public function computeChecksum($payload): string
    {
        $canonical = $this->canonicalize($payload);

        return hash('sha256', json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    /**
     * Recursively canonicalize data for consistent JSON serialization:
     * - Sort associative arrays by key
     * - Preserve indexed arrays order
     * - Cast monetary values to float
     *
     * @param  mixed  $data
     * @return mixed
     */
    private function canonicalize($data)
    {
        if (is_array($data)) {
            // If associative array, sort by keys
            if ($this->isAssoc($data)) {
                ksort($data);
            }

            foreach ($data as $key => &$value) {
                // Recurse
                $value = $this->canonicalize($value);

                // Cast monetary fields to float
                if (in_array($key, ['base_amount', 'amount'], true)) {
                    $value = (float) $value;
                }
            }
        }

        return $data;
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
