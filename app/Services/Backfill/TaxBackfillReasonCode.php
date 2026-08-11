<?php

declare(strict_types=1);

namespace App\Services\Backfill;

/**
 * Machine-readable reason codes for `quarantined` TaxBackfillRecord rows
 * (T013).
 *
 * `reason_code` on the model remains a plain nullable string column, not an
 * enum-backed cast — this repo has no precedent for casting an Eloquent
 * attribute to a PHP enum (see App\Services\Licensing\LicenseReasonCode for
 * the closest prior art, itself used as a plain value object rather than a
 * DB cast), and a later slice (the actual TaxBackfillRunner, or a `failed`
 * outcome) may need failure-specific codes that aren't enumerated here.
 * These two cases are the ones this feature's spec requires to be distinct
 * and documented today.
 */
enum TaxBackfillReasonCode: string
{
    /**
     * No recoverable payload at all: TaxReconstructionService::reconstructTaxRows()
     * returned an empty array with no candidate rows — the "no match" case
     * (research.md V1a's 216 unrecoverable transactions).
     */
    case MissingPayload = 'missing_payload';

    /**
     * Payload exists and parses, but TaxReconstructionService::crossCheck()
     * returned a non-empty mismatch list — the "ambiguous match" case:
     * recoverable-looking but suspect, needs human review before ever being
     * trusted.
     */
    case CrossCheckMismatch = 'cross_check_mismatch';
}
