<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Service to compute and validate SHA-256 payload checksums
 * for transaction submissions.
 */
class PayloadChecksumService
{
    /**
     * Public wrapper for canonicalize (for debugging/external use)
     * 
     * @param mixed $data
     * @return array
     */
    public function getCanonicalized($data): array
    {
        return $this->canonicalize($data);
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
        return $this->validateSubmissionChecksums($submission);
    }

    /**
     * Validate both transaction and submission checksums with fallback logic.
     *
     * @param  array  $submission  The decoded submission payload
     * @return array  ['valid' => bool, 'errors' => array]
     */
    public function validateSubmissionChecksums(array $submission): array
    {
        // Try V2.1 (Strict String) first
        $result = $this->validateWithVersion($submission, 'v2.1');
        if ($result['valid']) {
            return $result;
        }

        // Fallback to V2.0 (Float Normalization)
        $fallback = $this->validateWithVersion($submission, 'v2.0');
        if ($fallback['valid']) {
            \Log::info('Checksum validated via V2.0 fallback', ['submission_uuid' => $submission['submission_uuid'] ?? null]);
            return $fallback;
        }

        return $result; // Return the original V2.1 errors if both fail
    }

    /**
     * Internal validation for a specific version logic.
     * 
     * @param array $submission
     * @param string $version
     * @return array
     */
    private function validateWithVersion(array $submission, string $version): array
    {
        $errors = [];
        $this->currentVersion = $version;

        // Single transaction
        if (isset($submission['transaction'])) {
            $txn = $submission['transaction'];
            $txnCopy = $txn;
            unset($txnCopy['payload_checksum']);
            $computedTxn = $this->computeChecksum($txnCopy);
            
            if (!isset($txn['payload_checksum']) || $txn['payload_checksum'] !== $computedTxn) {
                $errors[] = "Invalid payload_checksum for transaction ({$version}). Received: " . ($txn['payload_checksum'] ?? 'missing') . ", Computed: {$computedTxn}";
            }

            $submissionCopy = $submission;
            unset($submissionCopy['payload_checksum']);
            $computedSubmission = $this->computeChecksum($submissionCopy);
            if (!isset($submission['payload_checksum']) || $submission['payload_checksum'] !== $computedSubmission) {
                $errors[] = "Invalid submission payload_checksum ({$version}). Received: " . ($submission['payload_checksum'] ?? 'missing') . ", Computed: {$computedSubmission}";
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
            ];
        }

        // Batch transactions
        if (isset($submission['transactions']) && is_array($submission['transactions'])) {
            $allTxnValid = true;
            foreach ($submission['transactions'] as $i => $txn) {
                $txnCopy = $txn;
                unset($txnCopy['payload_checksum']);
                $computedTxn = $this->computeChecksum($txnCopy);
                if (!isset($txn['payload_checksum']) || $txn['payload_checksum'] !== $computedTxn) {
                    $errors[] = "Invalid payload_checksum for transaction at index {$i} ({$version}). Received: " . ($txn['payload_checksum'] ?? 'missing') . ", Computed: {$computedTxn}";
                    $allTxnValid = false;
                }
            }

            if ($allTxnValid) {
                $submissionCopy = $submission;
                unset($submissionCopy['payload_checksum']);
                $computedSubmission = $this->computeChecksum($submissionCopy);
                if (!isset($submission['payload_checksum']) || $submission['payload_checksum'] !== $computedSubmission) {
                    $errors[] = "Invalid submission payload_checksum ({$version}). Received: " . ($submission['payload_checksum'] ?? 'missing') . ", Computed: {$computedSubmission}";
                }
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
            ];
        }

        return ['valid' => false, 'errors' => ['Unsupported payload structure']];
    }

    /**
     * Compute SHA-256 checksum of the payload after canonicalization.
     */
    public function computeChecksum($payload): string
    {
        $canonical = $this->canonicalize($payload);

        $json = json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return hash('sha256', $json);
    }

    /**
     * Recursively canonicalize data according to strict version-specific rules.
     * 
     * @param mixed $data
     * @return mixed
     */
    private function canonicalize($data)
    {
        if (is_array($data)) {
            if ($this->isAssoc($data)) {
                ksort($data);
            }

            foreach ($data as $key => &$value) {
                $value = $this->canonicalize($value);

                if ($this->currentVersion === 'v2.1') {
                    if (in_array($key, ['gross_sales', 'net_sales', 'amount'], true)) {
                        if (is_numeric($value)) {
                            $value = number_format((float) $value, 2, '.', '');
                        }
                    }
                } elseif ($this->currentVersion === 'v2.0') {
                    if (in_array($key, ['gross_sales', 'net_sales', 'amount'], true)) {
                        $value = (float) $value;
                        // PHP json_encode will strip trailing zeros for floats
                    }
                }
            }
        }

        return $data;
    }

    private string $currentVersion = 'v2.1';

    private function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}
