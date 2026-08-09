-- =============================================================================
-- MANUAL OPT-IN ONLY — never part of the default execution sequence
-- =============================================================================
-- Every query in this file is a full scan of a large table with no supporting
-- index for the filter it needs. They are NEVER to be run automatically, as part
-- of the standard execution order in README.md, or without explicit operator
-- sign-off. Run only:
--   - off-peak
--   - against the reporting read-replica connection
--   - with SET SESSION TRANSACTION READ ONLY; and a MAX_EXECUTION_TIME guard active
--   - with EXPLAIN reviewed first
--   - with the run recorded in an execution manifest (execution-manifest-template.md)
-- =============================================================================


-- -----------------------------------------------------------------------------
-- B1 — global_tax_type_inventory (EXPENSIVE — full scan of transaction_taxes,
-- no index on tax_type)
-- -----------------------------------------------------------------------------
-- MANUAL OPT-IN: requires operator sign-off; run only against the reporting
-- replica, off-peak.
-- -----------------------------------------------------------------------------
SELECT tax_type, COUNT(*) AS row_count, MIN(created_at) AS first_seen, MAX(created_at) AS last_seen
FROM transaction_taxes
GROUP BY tax_type
ORDER BY row_count DESC;


-- -----------------------------------------------------------------------------
-- historical_volume_sizing_all_tenants (EXPENSIVE — unscoped full scan of transactions)
-- -----------------------------------------------------------------------------
-- MANUAL OPT-IN: the tenant-scoped variant (A11) lives in
-- 60-track-a-backfill-scope-evidence.sql and should be preferred whenever a
-- specific tenant is the actual target. This unscoped form is only for a
-- one-time, whole-database sizing pass.
-- -----------------------------------------------------------------------------
SELECT tenant_id, COUNT(*) AS tx_count, MIN(transaction_date) AS earliest, MAX(transaction_date) AS latest
FROM transactions
GROUP BY tenant_id;
