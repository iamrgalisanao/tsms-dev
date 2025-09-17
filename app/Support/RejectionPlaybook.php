<?php

namespace App\Support;

/**
 * Maps rejection / validation message fragments to human-actionable remediation guidance.
 */
class RejectionPlaybook
{
    private static array $map = [
        'Mixed tenant / terminal batch not supported' => 'Split the batch so it contains only one tenant_id and one terminal_id.',
        'Outbound payload invalid' => 'Verify all required fields & formats match the published schema; correct then retry.',
        'Only transactions dated today' => 'Adjust the POS date/time (business date) so it matches the server date and resend.',
        'cannot be in the future' => 'Correct the POS clock (it is ahead). Ensure drift is within allowed tolerance.',
        'Duplicate transaction detected' => 'Do not re-send the same transaction_id for the same tenant. Generate a new ID or skip.',
        'Transaction ID format is not recognized' => 'Update the POS to emit IDs matching the documented patterns.',
    ];

    public static function explain(string $message): string
    {
        foreach (self::$map as $fragment => $advice) {
            if (stripos($message, $fragment) !== false) {
                return $advice;
            }
        }
        return 'Review payload against contract; correct fields or sequencing issues then retry.';
    }
}
