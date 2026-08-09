-- =============================================================================
-- A8 — Goldilocks Exclusion Check (sensitivity analysis only)
-- =============================================================================
-- SCOPE: SELECT-only. Requires :goldilocks_tenant_id + :date_from/:date_to.
--
-- USAGE RULE (binding — do not violate when producing any report from this package):
-- Every report using this query must present BOTH the full-population numbers
-- (A2/A6/A4/A10/etc. run with no tenant exclusion) AND, alongside — never in place
-- of — a second run of the same queries with `AND tenant_id <> :goldilocks_tenant_id`,
-- labeled exactly "sensitivity analysis: excludes known provider defect tenant".
-- No output file or report may contain only the excluded-population numbers.
--
-- Background: Goldilocks' POS terminal fails to subtract VAT on ~95% of its own
-- transactions — a genuine provider-side data defect, not a reporting-basis question
-- (see report-vat-correction-coverage.md's "Explicitly out of scope" section). This
-- query isolates that tenant so A2/A6/etc. can be rerun excluding it, to see the
-- general-population signal separately — not to hide or discard the Goldilocks case.
-- =============================================================================

SELECT t.tenant_id, ten.trade_name, pt.id AS terminal_id, pt.provider_id,
       COUNT(*) AS tx_count,
       SUM(t.vatable_sales > 0 AND ABS(t.vatable_sales * 0.12 - t.vat_amount) > 0.02) AS anomaly_count,
       ROUND(100.0 * SUM(t.vatable_sales > 0 AND ABS(t.vatable_sales * 0.12 - t.vat_amount) > 0.02) / COUNT(*), 2) AS anomaly_rate_pct
FROM transactions t
JOIN pos_terminals pt ON pt.id = t.terminal_id
JOIN tenants ten ON ten.id = t.tenant_id
WHERE t.tenant_id = :goldilocks_tenant_id
  AND t.transaction_date BETWEEN :date_from AND :date_to
GROUP BY t.tenant_id, ten.trade_name, pt.id, pt.provider_id;

-- To produce the "sensitivity analysis: excludes known provider defect tenant" view,
-- rerun A2 (20-track-a-cross-surface-comparison.sql) and A6
-- (30-track-a-tolerance-distribution.sql) with an added
-- `AND tenant_id <> :goldilocks_tenant_id` predicate in Fragment 0's tx_base WHERE
-- clause — keep the original, unfiltered run's output alongside it, per the Usage
-- Rule above.
