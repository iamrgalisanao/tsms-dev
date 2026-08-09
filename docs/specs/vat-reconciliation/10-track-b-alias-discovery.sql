-- =============================================================================
-- Track B — Tax-Type Alias Discovery
-- =============================================================================
-- SCOPE: SELECT-only. No mutation. Requires 00-shared-fragments.sql's Fragment 0b
-- (known_tax_type_aliases) inlined ahead of B4/B5 below.
-- All queries here require :tenant_id + :date_from/:date_to as named bind
-- placeholders (no IS-NULL-OR escape). B1 (global, unscoped) lives in
-- 90-unscoped-manual-opt-in-only.sql, NOT here — it is never part of this file
-- or the default execution sequence.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- B2 — tax_type_inventory_by_tenant_provider (index-friendly)
-- -----------------------------------------------------------------------------
-- Purpose: alias counts scoped to a tenant/date range, joined via
-- pos_terminals -> pos_providers. Uses idx_tx_logs_tenant_transaction_date.
-- -----------------------------------------------------------------------------
SELECT pp.id AS provider_id, pp.name AS provider_name, t.tenant_id, tt.tax_type,
       COUNT(*) AS row_count, COUNT(DISTINCT t.transaction_id) AS distinct_transactions,
       MIN(t.transaction_date) AS first_seen, MAX(t.transaction_date) AS last_seen
FROM transactions t
JOIN pos_terminals pt ON pt.id = t.terminal_id
JOIN pos_providers pp ON pp.id = pt.provider_id
JOIN transaction_taxes tt ON tt.transaction_id = t.transaction_id
WHERE t.tenant_id = :tenant_id
  AND t.transaction_date BETWEEN :date_from AND :date_to
GROUP BY pp.id, pp.name, t.tenant_id, tt.tax_type
ORDER BY row_count DESC;


-- -----------------------------------------------------------------------------
-- B2b — tax_type_inventory_by_provider_only (SAMPLE FIRST — two-step, no provider_id
-- index exists on pos_terminals or transactions)
-- -----------------------------------------------------------------------------
-- Step 1 (cheap — pos_terminals is small): resolve the provider's terminal ids first.
--   SELECT id FROM pos_terminals WHERE provider_id = :provider_id;
-- Step 2: filter transactions by terminal_id IN (...) + date range to use
--   idx_tx_logs_terminal_transaction_date, instead of joining the large transactions
--   table to pos_providers and filtering on an unindexed provider_id.
-- -----------------------------------------------------------------------------
SELECT tt.tax_type, COUNT(*) AS row_count
FROM transactions t
JOIN transaction_taxes tt ON tt.transaction_id = t.transaction_id
WHERE t.terminal_id IN (:terminal_ids) -- from Step 1 above
  AND t.transaction_date BETWEEN :date_from AND :date_to
GROUP BY tt.tax_type;


-- -----------------------------------------------------------------------------
-- B3 — tax_type_vocabulary_drift_by_month (moderate — always date-bound)
-- -----------------------------------------------------------------------------
-- Purpose: whether an alias appeared/disappeared over time per provider (vocabulary
-- drift, distinct from Track A's basis-drift in 40-track-a-mixed-rollout-detection.sql).
-- Includes minimum-sample/confidence metadata per report-vat-correction-coverage.md's
-- review requirement: row count, distinct transaction count, monetary volume, and
-- percentage of that month's tax rows this alias represents.
-- -----------------------------------------------------------------------------
SELECT pp.id AS provider_id, pp.name AS provider_name,
       DATE_FORMAT(t.transaction_date, '%Y-%m') AS year_month, tt.tax_type,
       COUNT(*) AS row_count,
       COUNT(DISTINCT t.transaction_id) AS distinct_tx_count,
       SUM(tt.amount) AS monetary_volume,
       ROUND(100.0 * COUNT(*) / SUM(COUNT(*)) OVER (PARTITION BY pp.id, DATE_FORMAT(t.transaction_date,'%Y-%m')), 2) AS pct_of_month_tax_rows
FROM transactions t
JOIN pos_terminals pt ON pt.id = t.terminal_id
JOIN pos_providers pp ON pp.id = pt.provider_id
JOIN transaction_taxes tt ON tt.transaction_id = t.transaction_id
WHERE t.tenant_id = :tenant_id
  AND t.transaction_date BETWEEN :date_from AND :date_to
GROUP BY pp.id, pp.name, year_month, tt.tax_type
ORDER BY provider_id, year_month;
-- A tax_type IS NULL row, if present, groups on its own and is the
-- null/unclassifiable indicator for this granularity.


-- -----------------------------------------------------------------------------
-- B4 — unknown_alias_inventory (index-friendly when tenant/date supplied)
-- -----------------------------------------------------------------------------
-- Purpose: tax_type values not recognized by Fragment 0b's known_tax_type_aliases.
-- Retains raw evidence: raw_tax_type (untouched), normalized_tax_type (trimmed/
-- uppercased comparison form), and separate exact-match vs normalized-match flags,
-- so a genuinely novel string can be distinguished from a case/whitespace near-miss
-- of an already-known alias. Nothing is normalized away.
--
-- Prepend Fragment 0b (00-shared-fragments.sql) as a CTE before running this query,
-- e.g.: WITH known_tax_type_aliases (...) AS (VALUES ROW(...), ...) <this query>
-- -----------------------------------------------------------------------------
SELECT
    tt.tax_type AS raw_tax_type,
    TRIM(UPPER(tt.tax_type)) AS normalized_tax_type,
    (k_exact.raw_value IS NOT NULL) AS matched_by_exact_alias,
    (k_norm.raw_value IS NOT NULL) AS matched_by_normalized_alias,
    COUNT(*) AS row_count,
    COUNT(DISTINCT t.tenant_id) AS distinct_tenants,
    MIN(t.transaction_date) AS first_seen, MAX(t.transaction_date) AS last_seen
FROM transactions t
JOIN transaction_taxes tt ON tt.transaction_id = t.transaction_id
LEFT JOIN known_tax_type_aliases k_exact ON k_exact.raw_value = tt.tax_type
LEFT JOIN known_tax_type_aliases k_norm ON TRIM(UPPER(k_norm.raw_value)) = TRIM(UPPER(tt.tax_type))
WHERE t.tenant_id = :tenant_id
  AND t.transaction_date BETWEEN :date_from AND :date_to
  AND k_exact.raw_value IS NULL -- "unknown" = not recognized by exact (case-sensitive) match, matching today's PHP behavior
GROUP BY tt.tax_type, k_exact.raw_value, k_norm.raw_value
ORDER BY row_count DESC;


-- -----------------------------------------------------------------------------
-- B5 — alias_classification_delta_across_surfaces (index-friendly)
-- -----------------------------------------------------------------------------
-- Purpose: rows/pesos that would be classified differently depending on which
-- surface's alias set reads them — direct evidence of the Track B risk described
-- in report-vat-correction-coverage.md's "Related Gap" section. Consumes Fragment
-- 0b's per-surface recognition columns rather than a second hard-coded alias list.
--
-- Prepend Fragment 0b (00-shared-fragments.sql) as a CTE before running this query.
-- -----------------------------------------------------------------------------
SELECT
    tt.tax_type AS raw_tax_type,
    TRIM(UPPER(tt.tax_type)) AS normalized_tax_type,
    k.candidate_class,
    k.recognized_by_ingestion_narrow, k.recognized_by_validation_medium, k.recognized_by_reporting_broad,
    COUNT(*) AS row_count, SUM(tt.amount) AS total_amount
FROM transactions t
JOIN transaction_taxes tt ON tt.transaction_id = t.transaction_id
LEFT JOIN known_tax_type_aliases k ON k.raw_value = tt.tax_type
WHERE t.tenant_id = :tenant_id
  AND t.transaction_date BETWEEN :date_from AND :date_to
GROUP BY tt.tax_type, k.candidate_class, k.recognized_by_ingestion_narrow, k.recognized_by_validation_medium, k.recognized_by_reporting_broad
ORDER BY row_count DESC;
