<?php

namespace App\Support\Reporting;

/**
 * Shared status filter for the transaction-log list, summary totals and export.
 *
 * Besides real validation_status values it supports the pseudo-statuses
 * VOIDED, REFUNDED and (ADR-004 slice 3) PROCESSING_FAILED.
 *
 * PROCESSING_FAILED is the single definition of "processing failed": job_status
 * FAILED with no final business outcome, i.e. validation_status NOT IN
 * ('VALID','INVALID') (covers PENDING, legacy '' and deprecated ERROR).
 */
final class TransactionStatusFilter
{
    public const PROCESSING_FAILED = 'PROCESSING_FAILED';

    /** Validation statuses that are a final business outcome. */
    public const FINAL_OUTCOMES = ['VALID', 'INVALID'];

    /**
     * Restrict a query to processing-failed rows.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     */
    public static function processingFailed($query, ?string $alias = null)
    {
        return $query->where(self::column('job_status', $alias), 'FAILED')
            ->whereNotIn(self::column('validation_status', $alias), self::FINAL_OUTCOMES);
    }

    /**
     * The same definition as a raw SQL condition, for CASE/IF expressions.
     */
    public static function processingFailedSql(?string $alias = null): string
    {
        return '('.self::column('job_status', $alias)." = 'FAILED' AND "
            .self::column('validation_status', $alias)." NOT IN ('".implode("', '", self::FINAL_OUTCOMES)."'))";
    }

    /**
     * PHP-side check for a loaded row/model.
     */
    public static function isProcessingFailed(?string $jobStatus, ?string $validationStatus): bool
    {
        return $jobStatus === 'FAILED' && ! in_array((string) $validationStatus, self::FINAL_OUTCOMES, true);
    }

    private static function column(string $column, ?string $alias): string
    {
        return $alias !== null && $alias !== '' ? "{$alias}.{$column}" : $column;
    }

    /**
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  string|null  $alias  table name or alias used to qualify the columns
     * @param  bool  $voidRefundPseudoStatuses  false keeps VOIDED/REFUNDED as plain validation_status matches
     * @return \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     */
    public static function apply($query, string $status, ?string $alias = null, bool $voidRefundPseudoStatuses = true)
    {
        $col = static fn (string $column) => self::column($column, $alias);

        // Void handling is parked (ADR-004 §10 item 1): callers that never had the
        // VOIDED/REFUNDED pseudo-statuses pass false and keep the plain equality match.
        if (! $voidRefundPseudoStatuses && in_array($status, ['VOIDED', 'REFUNDED'], true)) {
            return $query->where($col('validation_status'), $status);
        }

        return match ($status) {
            'VOIDED' => $query->whereNotNull($col('voided_at')),
            'REFUNDED' => $query->where($col('is_refunded'), true),
            self::PROCESSING_FAILED => self::processingFailed($query, $alias),
            default => $query->where($col('validation_status'), $status),
        };
    }
}
