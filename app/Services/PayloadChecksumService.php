<?php

namespace App\Services;

class PayloadChecksumService
{
    public function validateSubmissionChecksums(array $submission)
    {
        $errors = [];

        // Validate transaction checksum
        $txn = $submission['transaction'];
        $txnCopy = $txn;
        unset($txnCopy['payload_checksum']);

        $computedTxnChecksum = $this->computeChecksum($txnCopy);

        if ($txn['payload_checksum'] !== $computedTxnChecksum) {
            $errors[] = "Invalid payload_checksum for transaction";
        }

        // Validate submission checksum
        $submissionCopy = $submission;
        unset($submissionCopy['payload_checksum']);

        $computedSubmissionChecksum = $this->computeChecksum($submissionCopy);

        if ($submission['payload_checksum'] !== $computedSubmissionChecksum) {
            $errors[] = "Invalid submission payload_checksum";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    public function computeChecksum($payload)
    {
        $canonical = $this->canonicalize($payload);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function canonicalize($data)
    {
        if (is_array($data)) {
            if ($this->isAssoc($data)) {
                ksort($data);
            }
            foreach ($data as $key => &$value) {
                $value = $this->canonicalize($value);

                // Force consistent float casting for amount fields
                if (in_array($key, ['base_amount', 'amount'], true)) {
                    $value = (float)$value;
                }
            }
        }
        return $data;
    }

    private function isAssoc(array $array)
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}