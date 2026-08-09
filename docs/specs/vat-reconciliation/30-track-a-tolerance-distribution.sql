-- =============================================================================
-- A6 — tolerance_distribution_histogram (index-friendly)
-- =============================================================================
-- SCOPE: SELECT-only. Requires Fragment 0 (candidate_basis, 00-shared-fragments.sql)
-- inlined ahead. Requires :tenant_id + :date_from/:date_to (no IS-NULL-OR escape).
--
-- Purpose: distribution of |raw - candidate| deltas bucketed — evidence for Required
-- Decision 5 (what tolerance is empirically reasonable), WITHOUT picking one. Includes
-- minimum-sample/confidence metadata (row count, distinct transaction count, monetary
-- volume, percentage of the tenant/provider group's population, unclassifiable count)
-- so low-confidence buckets are visible rather than silently averaged away.
-- =============================================================================

SELECT tenant_id, provider_id, delta_bucket,
       COUNT(*) AS tx_count,
       COUNT(DISTINCT transaction_id) AS distinct_tx_count,
       SUM(raw_vatable_sales) AS monetary_volume,
       ROUND(100.0 * COUNT(*) / SUM(COUNT(*)) OVER (PARTITION BY tenant_id, provider_id), 2) AS pct_of_group_population,
       SUM(reason_code IN ('insufficient_data','conflicting_signals')) AS unclassifiable_count
FROM (
    SELECT f.*,
        CASE WHEN ABS(raw_vatable_sales - candidate_vatable_for_gross) <= 0.02 THEN '<=0.02'
             WHEN ABS(raw_vatable_sales - candidate_vatable_for_gross) <= 0.10 THEN '0.02-0.10'
             WHEN ABS(raw_vatable_sales - candidate_vatable_for_gross) <= 1.00 THEN '0.10-1.00'
             ELSE '>1.00' END AS delta_bucket
    FROM candidate_basis f
) x
GROUP BY tenant_id, provider_id, delta_bucket
ORDER BY tenant_id, provider_id, delta_bucket;
