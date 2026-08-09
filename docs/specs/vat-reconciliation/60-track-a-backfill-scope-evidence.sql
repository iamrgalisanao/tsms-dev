-- =============================================================================
-- A7, A11 — Cached-vs-Live Reconciliation and Historical Volume Sizing
-- =============================================================================
-- SCOPE: SELECT-only. A7 requires Fragment 0 (candidate_basis, 00-shared-fragments.sql)
-- inlined ahead. Both require :tenant_id + :date_from/:date_to (no IS-NULL-OR escape).
-- The unscoped, all-tenant variant of A11 lives in 90-unscoped-manual-opt-in-only.sql,
-- NOT here.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- A7 — cached_summary_vs_live_reconciliation (index-friendly both sides)
-- -----------------------------------------------------------------------------
-- Purpose: evidence for Required Decision 4 (backfill scope) — compares
-- daily_transaction_summaries (populated by RefreshDailyTransactionSummaries,
-- confirmed via direct source read to SUM() raw vatable_sales/vat_amount with NO
-- candidate/deriveMetrics()-style correction applied) against live raw and live
-- candidate figures for the same tenant/terminal/date.
--
-- CONTRACT (source-verified, not assumed):
--
-- Confirmed comparison parity:
--   - same tenant join key
--   - same business-date resolution through ResolvesReportBusinessDate (both
--     RefreshDailyTransactionSummaries and the live/candidate side of this
--     comparison resolve business date via this same trait — confirmed by direct
--     read of app/Console/Commands/RefreshDailyTransactionSummaries.php, which
--     `use`s App\Traits\ResolvesReportBusinessDate and calls reportDateExpression())
--   - no validation_status filter on either side (confirmed absent from
--     RefreshDailyTransactionSummaries by direct source read; the live/candidate
--     side applies none either)
--   - same void-exclusion predicate WHEN tsms.reporting.exclude_voids_from_totals=true
--     (transaction_type != 'VOID' AND voided_at IS NULL, confirmed at
--     RefreshDailyTransactionSummaries.php:99-101, config default true)
--
-- Runtime condition:
--   - record the EFFECTIVE tsms.reporting.exclude_voids_from_totals value in the
--     execution manifest (execution-manifest-template.md) BEFORE interpreting any
--     cached-vs-live difference from this query. This is a runtime config value,
--     not something a static source read can confirm for a given environment/moment.
--
-- Confirmed storage difference (source-verified — NOT parity):
--   - transactions.{vatable_sales, vat_amount} use DECIMAL(12,2)
--     (2025_09_07_134104_add_vat_fields_to_transactions_table.php)
--   - daily_transaction_summaries.{vatable_sales, vat_amount} use DECIMAL(15,2)
--     (2026_06_16_000002_create_daily_transaction_summaries.php)
--   - both use scale 2 (rounding behavior unaffected), but total precision differs.
--     No practical impact expected under normal daily transaction volumes (overflow
--     would require a single day's tenant total to exceed the smaller field's
--     capacity, which is not realistic at this business's scale) — stated here as a
--     confirmed mismatch, not asserted as parity.
--
-- Remaining fidelity caveats:
--   - This query's "live candidate" side does not fully reproduce
--     FinanceCalculationService::aggregateComponents()'s discount/payload-adjustment
--     fallback logic (lines 135-149) unless explicitly implemented — see Fragment 0's
--     own "Non-goals"/gap note in 00-shared-fragments.sql.
--   - PHP round() and MySQL ROUND() may differ at negative halfway values (see
--     Fragment 0's contract).
--   - Cache freshness must be confirmed through report_refresh_states.status before
--     treating any delta as VAT-correction evidence rather than staleness — necessary,
--     but per the classification below, not sufficient alone.
-- -----------------------------------------------------------------------------

-- Precondition (necessary, not sufficient on its own — see difference_classification):
-- SELECT status FROM report_refresh_states WHERE report_type='daily_transaction_summaries'
--   AND tenant_id = :tenant_id AND business_date BETWEEN :date_from AND :date_to;
-- Also record the live value of: SHOW VARIABLES; -- or application config inspection for
--   tsms.reporting.exclude_voids_from_totals, into the execution manifest before running.

WITH live AS (
    SELECT tenant_id, terminal_id, transaction_date,
           COUNT(*) AS live_tx_count,
           SUM(raw_vatable_sales) AS raw_vatable_sum,
           SUM(candidate_vatable_for_gross) AS candidate_vatable_sum
    FROM candidate_basis -- Fragment 0
    -- Void exclusion replicated to match RefreshDailyTransactionSummaries lines 99-101:
    -- add `AND t.transaction_type != 'VOID' AND t.voided_at IS NULL` inside Fragment 0's
    -- tx_base WHERE clause when tsms.reporting.exclude_voids_from_totals is true in the
    -- target environment (confirm the live value before running, per Runtime condition above).
    GROUP BY tenant_id, terminal_id, transaction_date
)
SELECT
    dts.tenant_id, dts.terminal_id, dts.business_date,
    dts.transaction_count AS cached_tx_count, live.live_tx_count,
    dts.vatable_sales AS cached_raw_vatable, live.raw_vatable_sum, live.candidate_vatable_sum,
    (dts.vatable_sales - live.raw_vatable_sum) AS cache_vs_live_raw_delta,
    (dts.vatable_sales - live.candidate_vatable_sum) AS cache_vs_candidate_delta,
    CASE
        WHEN live.live_tx_count IS NULL THEN 'missing_summary_row'
        WHEN dts.transaction_count <> live.live_tx_count THEN 'source_filter_mismatch'
        WHEN ABS(dts.vatable_sales - live.raw_vatable_sum) <= 0.02
             AND ABS(dts.vatable_sales - live.candidate_vatable_sum) > 0.02 THEN 'candidate_basis_delta'
        WHEN ABS(dts.vatable_sales - live.raw_vatable_sum) > 0.02
             AND ABS(dts.vatable_sales - live.raw_vatable_sum) <= 0.10 THEN 'rounding_only'
        WHEN ABS(dts.vatable_sales - live.raw_vatable_sum) > 0.10 THEN 'cache_staleness_possible'
        ELSE 'unclassified'
    END AS difference_classification
FROM daily_transaction_summaries dts
LEFT JOIN live ON live.tenant_id = dts.tenant_id AND live.terminal_id = dts.terminal_id
              AND live.transaction_date = dts.business_date
WHERE dts.tenant_id = :tenant_id AND dts.business_date BETWEEN :date_from AND :date_to;
-- 'cache_staleness_possible' is a heuristic bucket, not a confirmed diagnosis — pair
-- with the report_refresh_states precondition check above; if a discrepancy remains
-- unexplained after that check, treat it as 'unclassified' rather than assuming staleness.


-- -----------------------------------------------------------------------------
-- A11 — historical_volume_sizing (tenant-scoped; moderate, index-only scan)
-- -----------------------------------------------------------------------------
-- Purpose: row counts/date span for one tenant, to size a potential backfill
-- before deciding its scope (Required Decision 4). The unscoped, all-tenant
-- variant (historical_volume_sizing_all_tenants) is a MANUAL OPT-IN query — see
-- 90-unscoped-manual-opt-in-only.sql, not here.
-- -----------------------------------------------------------------------------
SELECT tenant_id, COUNT(*) AS tx_count, MIN(transaction_date) AS earliest, MAX(transaction_date) AS latest
FROM transactions
WHERE tenant_id = :tenant_id
GROUP BY tenant_id;
