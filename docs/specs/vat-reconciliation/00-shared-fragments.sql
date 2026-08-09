-- =============================================================================
-- Fragment 0 and 0b — shared building blocks for the VAT reconciliation package
-- =============================================================================
-- SCOPE: SQL artifact only. No application behavior changes. No schema changes.
-- No backfill. No production mutation. Read-only (SELECT-only fragments, copy-paste
-- into other files by name — these are NOT database views or any other schema object).
--
-- TERMINOLOGY: "candidate" / "heuristic", never "canonical". This fragment reproduces
-- the currently PROPOSED correction methodology for evidence-gathering only. It is not
-- an approved accounting rule. See docs/specs/report-vat-correction-coverage.md.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- Fragment 0 — candidate_basis
-- -----------------------------------------------------------------------------
-- CONTRACT
--
-- Inputs:
--   - transactions.vatable_sales, vat_amount, net_sales, sc_vat_exempt_sales,
--     senior_discount, pwd_discount — DECIMAL(12,2). PHP source (FinanceCalculationService)
--     reads these via (float) casts with `?? 0` null-coalescing. This fragment assumes
--     these are NOT NULL with a 0.00 default per the verified schema; if any can actually
--     be NULL in the target environment, add explicit COALESCE(...,0) before use.
--   - transaction_taxes.amount — consulted only as the exempt-alias fallback when
--     sc_vat_exempt_sales = 0 (mirrors FinanceCalculationService::aggregateComponents()
--     lines 100-102).
--   - VAT rate: hardcoded literal 0.12, matching FinanceCalculationService::deriveMetrics()
--     exactly. Not read from pos_providers or any config table. If the rate ever becomes
--     provider/tenant-configurable, this fragment must be revisited.
--   - Rounding: MySQL ROUND() mirrors PHP round() at every step the PHP source rounds.
--     KNOWN DIVERGENCE: PHP round() is half-away-from-zero; MySQL ROUND() on negative
--     halfway values can differ. Immaterial at 2-decimal-peso scale for the overwhelming
--     majority of rows, named here rather than silently assumed identical.
--   - Null handling: no rows are filtered for NULL; a NULL monetary column (if it can
--     occur) propagates through arithmetic and surfaces via 'insufficient_data', not
--     silently coerced to 0.
--   - Negative/refund handling: FinanceCalculationService::deriveMetrics() applies no
--     special branch for negative vat_amount/vatable_sales (refund/credit-note rows) —
--     this fragment does the same. Negative-value rows are not excluded.
--   - Discount handling: only transactions.senior_discount + transactions.pwd_discount
--     are read directly. FinanceCalculationService::aggregateComponents()'s FALLBACK to
--     transaction_adjustments / payload-derived discount figures (lines 135-149) is
--     NOT reproduced here — a named fidelity gap, distinct from the discount-adjustment
--     branch itself (which IS reproduced below).
--
-- Outputs:
--   - candidate_basis_classification: one of 'inclusive_corrected' / 'exclusive_as_reported'
--     / 'ambiguous'
--   - candidate_vatable_for_gross: the fragment's proposed corrected figure
--   - Diagnostic flags: raw_looks_vat_inclusive, already_ex_vat, apply_discount_adjustment,
--     apply_candidate_ex_vat
--   - reason_code: exactly one of 'explicit_provider_rule', 'candidate_inclusive_heuristic',
--     'candidate_exclusive_heuristic', 'insufficient_data', 'conflicting_signals'.
--     NOTE: 'explicit_provider_rule' is a reserved forward-compatibility value — no
--     config-driven per-provider basis flag exists in the codebase today (that is exactly
--     what Required Decision 2 is about), so no row emitted by this fragment will ever
--     carry that code today. It is documented, not fabricated.
--
-- Non-goals:
--   - No production UPDATE/backfill of any column.
--   - No approved accounting rule — every output is evidence for the seven Required
--     Decisions in report-vat-correction-coverage.md, not a production implementation.
--   - No decision on provider/tenant scope, tolerance, or historical cutover.
--   - No mutation of raw stored values under any circumstance.
--
-- Required bind parameters (no IS-NULL-OR escape — omitting one is a runtime error):
--   :tenant_id, :date_from, :date_to
-- -----------------------------------------------------------------------------

-- candidate_basis (copy-paste this CTE chain into any query that needs it)
WITH tx_base AS (
    SELECT
        t.id, t.tenant_id, t.terminal_id, pt.provider_id,
        t.transaction_id, t.transaction_date,
        t.vatable_sales AS raw_vatable_sales,
        t.vat_amount    AS raw_vat,
        t.net_sales     AS raw_net_sales,
        t.senior_discount AS raw_senior_discount,
        t.pwd_discount    AS raw_pwd_discount,
        ROUND(COALESCE(t.senior_discount,0) + COALESCE(t.pwd_discount,0), 2) AS senior_pwd,
        CASE
            WHEN t.sc_vat_exempt_sales <> 0 THEN t.sc_vat_exempt_sales
            ELSE COALESCE((
                SELECT SUM(tt.amount) FROM transaction_taxes tt
                WHERE tt.transaction_id = t.transaction_id
                  AND tt.tax_type IN ('SC_VAT_EXEMPT_SALES','VAT_EXEMPT_SALES','VATEXEMPT_SALES',
                                       'VAT-EXEMPT','EXEMPT','VATEXEMPT')
            ), 0)
        END AS raw_sc_vat_exempt_sales
    FROM transactions t
    JOIN pos_terminals pt ON pt.id = t.terminal_id
    WHERE t.tenant_id = :tenant_id
      AND t.transaction_date BETWEEN :date_from AND :date_to
),
tx_step1 AS ( -- deriveMetrics() lines 202-208: collapse vatable->net-basis when they look equal
    SELECT b.*,
        (raw_net_sales > 0 AND raw_vat > 0) AS has_taxable_gate,
        CASE WHEN raw_net_sales > 0 AND raw_vat > 0
                  AND ABS(raw_vatable_sales - raw_net_sales) <= 0.05
             THEN ROUND(raw_vatable_sales - raw_vat, 2)
             ELSE raw_vatable_sales
        END AS vatable_for_gross_step1
    FROM tx_base b
),
tx_step2 AS ( -- deriveMetrics() lines 210-218: discount-adjusted-ex-vat branch (senior/PWD)
    SELECT s.*,
        ROUND(vatable_for_gross_step1 - raw_vat + senior_pwd, 2) AS discount_adjusted_ex_vat,
        (has_taxable_gate
            AND ROUND(vatable_for_gross_step1 - raw_vat + senior_pwd, 2) >= 0
            AND ABS(raw_vat - ROUND(ROUND(vatable_for_gross_step1 - raw_vat + senior_pwd, 2) * 0.12, 2)) <= 0.10
            AND ABS(raw_vat - ROUND(vatable_for_gross_step1 * 0.12, 2)) > 0.10
        ) AS apply_discount_adjustment
    FROM tx_step1 s
),
tx_step3 AS (
    SELECT s2.*,
        CASE WHEN apply_discount_adjustment THEN discount_adjusted_ex_vat ELSE vatable_for_gross_step1 END AS vatable_for_gross_step2,
        CASE WHEN raw_sc_vat_exempt_sales > 0 AND raw_net_sales >= raw_sc_vat_exempt_sales
             THEN ROUND(raw_net_sales - raw_sc_vat_exempt_sales, 2)
             ELSE raw_net_sales
        END AS net_base
    FROM tx_step2 s2
),
tx_candidate AS ( -- deriveMetrics() lines 220-232
    SELECT s3.*,
        ROUND(net_base - raw_vat, 2) AS candidate_ex_vat,
        (has_taxable_gate AND ABS(vatable_for_gross_step2 - ROUND(ROUND(net_base - raw_vat, 2) + raw_vat, 2)) <= 0.05) AS raw_looks_vat_inclusive,
        (ABS(raw_vat - ROUND(vatable_for_gross_step2 * 0.12, 2)) <= 0.10) AS already_ex_vat
    FROM tx_step3 s3
),
tx_final AS (
    SELECT tc.*,
        (has_taxable_gate AND candidate_ex_vat >= 0 AND raw_looks_vat_inclusive AND NOT already_ex_vat) AS apply_candidate_ex_vat
    FROM tx_candidate tc
)
SELECT
    id, tenant_id, terminal_id, provider_id, transaction_id, transaction_date,
    raw_vatable_sales, raw_vat, raw_net_sales, raw_sc_vat_exempt_sales, raw_senior_discount, raw_pwd_discount,
    vatable_for_gross_step2, candidate_ex_vat,
    raw_looks_vat_inclusive, already_ex_vat, apply_discount_adjustment, apply_candidate_ex_vat,
    CASE WHEN apply_candidate_ex_vat THEN candidate_ex_vat ELSE vatable_for_gross_step2 END AS candidate_vatable_for_gross,
    CASE
        WHEN NOT has_taxable_gate THEN 'insufficient_data'
        WHEN apply_candidate_ex_vat THEN 'candidate_inclusive_heuristic'
        WHEN already_ex_vat OR apply_discount_adjustment THEN 'candidate_exclusive_heuristic'
        ELSE 'conflicting_signals'
    END AS reason_code,
    CASE
        WHEN NOT has_taxable_gate THEN 'ambiguous'
        WHEN apply_candidate_ex_vat THEN 'inclusive_corrected'
        WHEN already_ex_vat OR apply_discount_adjustment THEN 'exclusive_as_reported'
        ELSE 'ambiguous'
    END AS candidate_basis_classification
FROM tx_final;


-- -----------------------------------------------------------------------------
-- Fragment 0b — known_tax_type_aliases (single shared alias source, Track B)
-- -----------------------------------------------------------------------------
-- Source: FinanceCalculationService::NON_OTHER_TAX_TYPES (broad, verified at
-- app/Services/Reports/FinanceCalculationService.php:49), TransactionValidationService::
-- getTaxBuckets()'s medium set, and TransactionController / Transaction::otherTaxSum() /
-- TransactionSummaryPresenter's narrow set (SC_VAT_EXEMPT_SALES only) — all per
-- report-vat-correction-coverage.md's "Related Gap: Tax-Type Alias Consistency" citations,
-- not re-derived here.
--
-- Both 10-track-b-alias-discovery.sql queries (B4, B5) consume THIS fragment only.
-- Do not duplicate this alias list anywhere else in this package.
--
-- COMPATIBILITY: the VALUES ROW(...) AS alias(cols) table-value-constructor syntax
-- requires MySQL 8.0.19+. If the target server is older, replace with:
--   SELECT * FROM (SELECT 'X' AS raw_value, 'y' AS candidate_class, ... UNION ALL SELECT ...) AS known_tax_type_aliases
-- -----------------------------------------------------------------------------

WITH known_tax_type_aliases (raw_value, candidate_class,
    recognized_by_ingestion_narrow, recognized_by_validation_medium, recognized_by_reporting_broad) AS (
    VALUES
    ROW('SC_VAT_EXEMPT_SALES', 'vat_exempt', TRUE,  TRUE,  TRUE),
    ROW('VAT_EXEMPT_SALES',    'vat_exempt', FALSE, TRUE,  TRUE),
    ROW('VAT_EXEMPT',          'vat_exempt', FALSE, TRUE,  FALSE),
    ROW('VATEXEMPT_SALES',     'vat_exempt', FALSE, FALSE, TRUE),
    ROW('VAT-EXEMPT',          'vat_exempt', FALSE, FALSE, TRUE),
    ROW('EXEMPT',              'vat_exempt', FALSE, FALSE, TRUE),
    ROW('VATEXEMPT',           'vat_exempt', FALSE, FALSE, TRUE),
    ROW('ZERO_RATED',          'zero_rated', FALSE, FALSE, TRUE),
    ROW('NON-VAT',             'non_vat',    FALSE, FALSE, TRUE),
    ROW('NON_VAT',             'non_vat',    FALSE, FALSE, TRUE),
    ROW('ZERO-RATED',          'zero_rated', FALSE, FALSE, TRUE),
    ROW('VAT',                 'vat_sale',   FALSE, FALSE, TRUE),
    ROW('VAT_AMOUNT',          'vat_sale',   FALSE, FALSE, TRUE),
    ROW('VATABLE_SALES',       'vat_sale',   FALSE, FALSE, TRUE),
    ROW('OTHER_TAX',           'other_tax',  FALSE, FALSE, TRUE),
    ROW('OTHER-TAX',           'other_tax',  FALSE, FALSE, TRUE)
)
SELECT * FROM known_tax_type_aliases;
