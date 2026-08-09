-- =============================================================================
-- Track A — Cross-Surface Reconciliation & Candidate Comparison
-- =============================================================================
-- SCOPE: SELECT-only. No mutation. Requires 00-shared-fragments.sql's Fragment 0
-- (candidate_basis) inlined ahead of every query below. All require :tenant_id +
-- :date_from/:date_to as named bind placeholders (no IS-NULL-OR escape).
-- =============================================================================


-- -----------------------------------------------------------------------------
-- A1 — per_transaction_raw_vs_candidate (index-friendly)
-- -----------------------------------------------------------------------------
-- Purpose: row-level evidence of the delta between raw and candidate figures.
-- Prepend Fragment 0 (00-shared-fragments.sql) as a CTE, then:
-- -----------------------------------------------------------------------------
SELECT *, (raw_vatable_sales - candidate_vatable_for_gross) AS delta
FROM candidate_basis; -- candidate_basis = Fragment 0's final SELECT, aliased as a CTE


-- -----------------------------------------------------------------------------
-- A2 — variance_summary_by_tenant_provider_date (index-friendly)
-- -----------------------------------------------------------------------------
-- Purpose: the "variance summaries by tenant/provider/date" required output.
-- -----------------------------------------------------------------------------
SELECT tenant_id, provider_id, transaction_date,
       COUNT(*) AS tx_count,
       SUM(raw_vatable_sales) AS raw_vatable_sum,
       SUM(candidate_vatable_for_gross) AS candidate_vatable_sum,
       SUM(raw_vatable_sales - candidate_vatable_for_gross) AS delta_sum,
       SUM(reason_code = 'candidate_inclusive_heuristic') AS candidate_inclusive_count,
       SUM(reason_code = 'candidate_exclusive_heuristic') AS candidate_exclusive_count,
       SUM(reason_code IN ('insufficient_data','conflicting_signals')) AS unclassifiable_count
FROM candidate_basis
GROUP BY tenant_id, provider_id, transaction_date
ORDER BY transaction_date;


-- -----------------------------------------------------------------------------
-- A3 — cross_surface_total_comparison (index-friendly)
-- -----------------------------------------------------------------------------
-- Purpose: reconstruct what a "Dashboard-style raw" reader vs. a "candidate-corrected"
-- reader would each produce for the same tenant/range.
-- -----------------------------------------------------------------------------
SELECT tenant_id, 'dashboard_raw' AS surface,
       SUM(raw_vatable_sales) AS vatable_total, SUM(raw_vat) AS vat_total, SUM(raw_net_sales) AS net_total
FROM candidate_basis GROUP BY tenant_id
UNION ALL
SELECT tenant_id, 'candidate_corrected' AS surface,
       SUM(candidate_vatable_for_gross), SUM(raw_vat), SUM(raw_net_sales)
FROM candidate_basis GROUP BY tenant_id;


-- -----------------------------------------------------------------------------
-- A9 — candidate_shortcut_vs_row_level_approximation (index-friendly, moderate)
-- -----------------------------------------------------------------------------
-- Purpose: evidence for Required Decision 1 — whether a lighter-weight aggregate-only
-- correction (apply the candidate formula once to SUM()ed raw components) diverges
-- from row-level correct-then-sum.
-- -----------------------------------------------------------------------------
SELECT 'per_row_candidate_then_sum' AS method, SUM(candidate_vatable_for_gross) AS value
FROM candidate_basis
UNION ALL
SELECT 'aggregate_sum_then_candidate_formula' AS method, candidate_vatable_for_gross AS value
FROM (
    -- Apply Fragment 0's identical tx_step1/tx_step2/tx_step3/tx_candidate formula ONCE
    -- to a single row of SUM(raw_vatable_sales), SUM(raw_vat), SUM(raw_net_sales),
    -- SUM(raw_sc_vat_exempt_sales), SUM(senior_pwd) over the same tenant/date range,
    -- then compare the two scalar values above.
    SELECT tenant_id, transaction_date,
           SUM(raw_vatable_sales) AS raw_vatable_sales, SUM(raw_vat) AS raw_vat,
           SUM(raw_net_sales) AS raw_net_sales, SUM(raw_sc_vat_exempt_sales) AS raw_sc_vat_exempt_sales,
           SUM(senior_pwd) AS senior_pwd
    FROM candidate_basis
    GROUP BY tenant_id, transaction_date
    -- then re-apply Fragment 0's tx_step1..tx_final CASE logic to this single
    -- pre-aggregated row to produce candidate_vatable_for_gross
) agg;
