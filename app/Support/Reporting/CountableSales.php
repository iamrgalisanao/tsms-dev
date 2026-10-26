<?php

namespace App\Support\Reporting;

/**
 * ADR-004 D10: which transactions count toward gross/net sales sums.
 *
 * A sale counts unless it is business-INVALID, and (when the existing
 * tsms.reporting.exclude_voids_from_totals flag is on) unless it is voided.
 * PENDING and legacy '' rows still count: they are recorded sales that have
 * not been validated yet.
 */
final class CountableSales
{
    public const EXCLUDED_STATUS = 'INVALID';

    /**
     * Whether the existing config-gated void exclusion is enabled.
     */
    public static function excludeVoids(): bool
    {
        return (bool) config('tsms.reporting.exclude_voids_from_totals', true);
    }

    /**
     * Restrict a transactions query to countable sales.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  string|null  $alias  table name or alias used to qualify the columns
     * @param  bool  $voidFilter  false skips the void exclusion (e.g. the column is absent)
     * @return \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     */
    public static function apply($query, ?string $alias = null, bool $voidFilter = true)
    {
        if ($voidFilter && self::excludeVoids()) {
            $query->where(self::column('transaction_type', $alias), '!=', 'VOID')
                ->whereNull(self::column('voided_at', $alias));
        }

        return $query->where(self::column('validation_status', $alias), '<>', self::EXCLUDED_STATUS);
    }

    /**
     * Conditional sum that ignores INVALID rows while keeping them in the row set
     * (for queries that also report status counts).
     */
    public static function sumExpr(string $col, ?string $alias = null): string
    {
        $status = self::column('validation_status', $alias);

        return "SUM(CASE WHEN {$status} <> '".self::EXCLUDED_STATUS."' THEN {$col} ELSE 0 END)";
    }

    /**
     * Countable sum of one column on an existing query (no void filtering is added).
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation  $query
     */
    public static function sum($query, string $col, ?string $alias = null): float
    {
        return (float) ($query->selectRaw('COALESCE('.self::sumExpr(self::column($col, $alias), $alias).', 0) as countable_sum')
            ->value('countable_sum') ?? 0);
    }

    private static function column(string $column, ?string $alias): string
    {
        return $alias !== null && $alias !== '' ? "{$alias}.{$column}" : $column;
    }
}
