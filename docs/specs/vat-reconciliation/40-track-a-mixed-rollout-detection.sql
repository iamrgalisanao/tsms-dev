-- =============================================================================
-- A4, A10 — Mixed-Rollout Detection and Provider/Terminal Consistency
-- =============================================================================
-- SCOPE: SELECT-only. Requires Fragment 0 (candidate_basis, 00-shared-fragments.sql)
-- inlined ahead, with the :tenant_id filter RELAXED per the cross-tenant exception
-- documented below.
--
-- CROSS-TENANT EXCEPTION (the only one in this package): a provider's reporting
-- convention is not tenant-scoped by definition, so both queries below legitimately
-- span tenants under one provider. :tenant_id MAY still be supplied to narrow the
-- result; :date_from/:date_to remain mandatory in all cases.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- A4 — mixed_rollout_detection_by_provider_month (moderate — always date-bound)
-- -----------------------------------------------------------------------------
-- Purpose: whether a provider's convention changed over time (Required Decision 3),
-- WITHOUT assuming a clean date boundary — a monthly time series, not a single
-- before/after split. Includes population-coverage metadata per the review requirement.
-- Also runnable at terminal_id grain instead of provider_id if finer evidence is needed.
-- -----------------------------------------------------------------------------
SELECT provider_id, DATE_FORMAT(transaction_date, '%Y-%m') AS year_month,
       COUNT(*) AS tx_count,
       COUNT(DISTINCT transaction_id) AS distinct_tx_count,
       SUM(raw_vatable_sales) AS monetary_volume,
       ROUND(100.0 * COUNT(*) / SUM(COUNT(*)) OVER (PARTITION BY provider_id), 2) AS pct_of_provider_population,
       ROUND(100.0 * SUM(reason_code='candidate_inclusive_heuristic') / COUNT(*), 2) AS pct_flagged_inclusive,
       SUM(reason_code IN ('insufficient_data','conflicting_signals')) AS unclassifiable_count
FROM candidate_basis -- Fragment 0 with the tenant filter relaxed (:tenant_id optional)
WHERE transaction_date BETWEEN :date_from AND :date_to
GROUP BY provider_id, year_month
ORDER BY provider_id, year_month;


-- -----------------------------------------------------------------------------
-- A10 — provider_basis_consistency_by_window (date-windowed, NOT all-history)
-- -----------------------------------------------------------------------------
-- Purpose: evidence for Required Decision 2. A provider-level basis rule is only
-- supportable when BOTH temporal consistency (across months) AND terminal-level
-- consistency (across terminals) are demonstrated — an all-history percentage alone
-- can make a provider look stable while hiding a rollout transition. This query
-- surfaces the stddev/sample-size/transition-month numbers; it does not threshold
-- them or pick a cutover date itself.
-- -----------------------------------------------------------------------------
WITH monthly_provider AS (
    SELECT provider_id, DATE_FORMAT(transaction_date, '%Y-%m') AS year_month,
           COUNT(*) AS tx_count, COUNT(DISTINCT transaction_id) AS distinct_tx_count,
           SUM(raw_vatable_sales) AS monetary_volume,
           SUM(reason_code IN ('insufficient_data','conflicting_signals')) AS unclassifiable_count,
           ROUND(100.0 * SUM(reason_code='candidate_inclusive_heuristic') / COUNT(*), 2) AS pct_inclusive
    FROM candidate_basis -- Fragment 0, tenant filter relaxed
    WHERE transaction_date BETWEEN :date_from AND :date_to
    GROUP BY provider_id, year_month
),
monthly_terminal AS (
    SELECT provider_id, terminal_id, DATE_FORMAT(transaction_date, '%Y-%m') AS year_month,
           COUNT(*) AS tx_count,
           ROUND(100.0 * SUM(reason_code='candidate_inclusive_heuristic') / COUNT(*), 2) AS pct_inclusive
    FROM candidate_basis -- Fragment 0, tenant filter relaxed
    WHERE transaction_date BETWEEN :date_from AND :date_to
    GROUP BY provider_id, terminal_id, year_month
),
provider_variation AS (
    SELECT provider_id, MIN(tx_count) AS min_monthly_sample,
           STDDEV_SAMP(pct_inclusive) AS pct_inclusive_stddev_across_months,
           COUNT(DISTINCT year_month) AS months_observed
    FROM monthly_provider GROUP BY provider_id
),
terminal_variation AS (
    SELECT provider_id, STDDEV_SAMP(pct_inclusive) AS pct_inclusive_stddev_across_terminals,
           MIN(tx_count) AS min_terminal_monthly_sample,
           COUNT(DISTINCT terminal_id) AS terminals_observed
    FROM monthly_terminal GROUP BY provider_id
),
majority_by_month AS (
    SELECT provider_id, year_month,
           CASE WHEN pct_inclusive >= 50 THEN 'inclusive_majority' ELSE 'exclusive_majority' END AS majority_class,
           LAG(CASE WHEN pct_inclusive >= 50 THEN 'inclusive_majority' ELSE 'exclusive_majority' END)
               OVER (PARTITION BY provider_id ORDER BY year_month) AS prev_majority_class
    FROM monthly_provider
)
SELECT mp.provider_id, mp.year_month, mp.tx_count, mp.distinct_tx_count, mp.monetary_volume,
       mp.unclassifiable_count, mp.pct_inclusive,
       pv.min_monthly_sample, pv.pct_inclusive_stddev_across_months, pv.months_observed,
       tv.pct_inclusive_stddev_across_terminals, tv.min_terminal_monthly_sample, tv.terminals_observed,
       (SELECT MIN(year_month) FROM majority_by_month m WHERE m.provider_id = mp.provider_id AND m.majority_class <> m.prev_majority_class) AS first_observed_transition_month,
       (SELECT MAX(year_month) FROM majority_by_month m WHERE m.provider_id = mp.provider_id AND m.majority_class <> m.prev_majority_class) AS last_observed_transition_month
FROM monthly_provider mp
JOIN provider_variation pv ON pv.provider_id = mp.provider_id
LEFT JOIN terminal_variation tv ON tv.provider_id = mp.provider_id
ORDER BY mp.provider_id, mp.year_month;
-- Requires MySQL 8+ for STDDEV_SAMP window usage and LAG(...) OVER — confirm against
-- the actual target server version before running.
