-- =============================================================================
-- A5, A5b — Existing Anomaly Query Reproduction and Overlap Analysis
-- =============================================================================
-- SCOPE: SELECT-only. A5 requires no fragment. A5b requires Fragment 0
-- (candidate_basis, 00-shared-fragments.sql) inlined ahead. Both require
-- :tenant_id + :date_from/:date_to (no IS-NULL-OR escape).
-- =============================================================================


-- -----------------------------------------------------------------------------
-- A5 — reproduce_existing_anomaly_query (index-friendly)
-- -----------------------------------------------------------------------------
-- Purpose: literal, parameterized reproduction of the anomaly condition at
-- app/Services/DashboardService.php:153-155 (tax_exempt=0 AND vatable_sales>0 AND
-- ABS(vatable_sales*0.12 - vat_amount) > 0.02) — verified against source, unmodified
-- in logic. Note: DashboardService's actual query also OR-combines this with a
-- validation_status/last_error condition; this reproduction covers only the VAT-basis
-- anomaly clause this spec is about, matching report-vat-correction-coverage.md's own
-- citation of lines 154-155.
-- -----------------------------------------------------------------------------
SELECT t.tenant_id, pt.provider_id, t.transaction_date, COUNT(*) AS anomaly_count
FROM transactions t
JOIN pos_terminals pt ON pt.id = t.terminal_id
WHERE t.tenant_id = :tenant_id
  AND t.transaction_date BETWEEN :date_from AND :date_to
  AND t.tax_exempt = 0
  AND t.vatable_sales > 0
  AND ABS(t.vatable_sales * 0.12 - t.vat_amount) > 0.02
GROUP BY t.tenant_id, pt.provider_id, t.transaction_date;


-- -----------------------------------------------------------------------------
-- A5b — anomaly_vs_heuristic_overlap_matrix (index-friendly)
-- -----------------------------------------------------------------------------
-- Purpose: evidence for Required Decision 6 — a 2x2 contingency between the existing
-- anomaly flag and the candidate_basis fragment's raw_looks_vat_inclusive flag, to see
-- whether the anomaly query is mostly redetecting the already-known basis issue
-- (candidate for retirement) or catching independent defects like Goldilocks
-- (candidate for keeping). See 70-goldilocks-sensitivity-analysis.sql for the
-- Goldilocks-specific isolation.
-- -----------------------------------------------------------------------------
SELECT tenant_id, provider_id,
    SUM(anomaly_flag AND raw_looks_vat_inclusive) AS both_flagged,
    SUM(anomaly_flag AND NOT raw_looks_vat_inclusive) AS anomaly_only,
    SUM(NOT anomaly_flag AND raw_looks_vat_inclusive) AS heuristic_only,
    SUM(NOT anomaly_flag AND NOT raw_looks_vat_inclusive) AS neither
FROM (
    SELECT f.*, (raw_vatable_sales > 0 AND ABS(raw_vatable_sales * 0.12 - raw_vat) > 0.02) AS anomaly_flag
    FROM candidate_basis f -- Fragment 0
) x
GROUP BY tenant_id, provider_id;
