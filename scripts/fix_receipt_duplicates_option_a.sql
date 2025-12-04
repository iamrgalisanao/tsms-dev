-- fix_receipt_duplicates_option_a.sql
--
-- Non-destructive remediation for duplicate (tenant_id, terminal_id, receipt_no)
-- Approach: keep one canonical row per (tenant,terminal,receipt) and append
-- a suffix "-dup-<id>" to other rows' receipt_no so the composite becomes unique.
-- The script creates an audit table to record old -> new mappings, applies the
-- update, then verifies that no duplicates remain. A rollback snippet is
-- provided at the end that uses the audit table to restore original values.
--
-- IMPORTANT: Back up your database before running this script.
-- Usage (example):
--   mysql -u deploy_user -p tsms_db < scripts/fix_receipt_duplicates_option_a.sql

-- Preview offending groups (top 200) but only where the canonical transaction
-- date is the same. Canonical date = DATE(COALESCE(transaction_timestamp, completed_at, created_at)).
SELECT tenant_id, terminal_id, receipt_no,
       DATE(COALESCE(transaction_timestamp, completed_at, created_at)) AS tx_date,
       COUNT(*) AS cnt
FROM transactions
WHERE receipt_no IS NOT NULL AND receipt_no <> ''
GROUP BY tenant_id, terminal_id, receipt_no, tx_date
HAVING cnt > 1
ORDER BY cnt DESC
LIMIT 200;

-- Preview rows that would be changed (show new value to be set). We keep one
-- canonical row per (tenant,terminal,receipt,tx_date) and update others.
SELECT t.id, t.tenant_id, t.terminal_id, t.receipt_no,
       DATE(COALESCE(t.transaction_timestamp, t.completed_at, t.created_at)) AS tx_date,
       CONCAT(t.receipt_no, '-dup-', t.id) AS new_receipt_no
FROM transactions t
JOIN (
  SELECT tenant_id, terminal_id, receipt_no, DATE(COALESCE(transaction_timestamp, completed_at, created_at)) AS tx_date, MIN(id) AS keep_id
  FROM transactions
  WHERE receipt_no IS NOT NULL AND receipt_no <> ''
  GROUP BY tenant_id, terminal_id, receipt_no, tx_date
  HAVING COUNT(*) > 1
) dup ON t.tenant_id = dup.tenant_id
      AND t.terminal_id = dup.terminal_id
      AND t.receipt_no = dup.receipt_no
      AND DATE(COALESCE(t.transaction_timestamp, t.completed_at, t.created_at)) = dup.tx_date
WHERE t.id <> dup.keep_id
ORDER BY t.tenant_id, t.terminal_id, t.receipt_no, tx_date
LIMIT 200;

-- Create an audit table to record old -> new mappings (idempotent create)
CREATE TABLE IF NOT EXISTS receipt_dup_audit (
  id BIGINT PRIMARY KEY,
  tenant_id INT,
  terminal_id INT,
  old_receipt_no VARCHAR(255),
  new_receipt_no VARCHAR(255),
  tx_date DATE,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Populate the audit table for rows we intend to change.
INSERT INTO receipt_dup_audit (id, tenant_id, terminal_id, old_receipt_no, new_receipt_no, tx_date)
SELECT t.id, t.tenant_id, t.terminal_id, t.receipt_no, CONCAT(t.receipt_no, '-dup-', t.id),
       DATE(COALESCE(t.transaction_timestamp, t.completed_at, t.created_at)) AS tx_date
FROM transactions t
JOIN (
  SELECT tenant_id, terminal_id, receipt_no, DATE(COALESCE(transaction_timestamp, completed_at, created_at)) AS tx_date, MIN(id) AS keep_id
  FROM transactions
  WHERE receipt_no IS NOT NULL AND receipt_no <> ''
  GROUP BY tenant_id, terminal_id, receipt_no, tx_date
  HAVING COUNT(*) > 1
) dup ON t.tenant_id = dup.tenant_id
      AND t.terminal_id = dup.terminal_id
      AND t.receipt_no = dup.receipt_no
      AND DATE(COALESCE(t.transaction_timestamp, t.completed_at, t.created_at)) = dup.tx_date
WHERE t.id <> dup.keep_id;

-- Apply the update using the audit table. Wrap in a transaction for safety.
START TRANSACTION;

UPDATE transactions t
JOIN receipt_dup_audit a ON t.id = a.id
SET t.receipt_no = a.new_receipt_no;

COMMIT;

-- Verification: ensure no offending groups remain (same-day duplicates)
SELECT tenant_id, terminal_id, receipt_no, DATE(COALESCE(transaction_timestamp, completed_at, created_at)) AS tx_date, COUNT(*) AS cnt
FROM transactions
WHERE receipt_no IS NOT NULL AND receipt_no <> ''
GROUP BY tenant_id, terminal_id, receipt_no, tx_date
HAVING cnt > 1
ORDER BY cnt DESC
LIMIT 50;

-- Quick counts: how many rows were changed
SELECT COUNT(*) AS changed_rows FROM receipt_dup_audit;

-- ROLLBACK snippet (if you must revert)
-- WARNING: running this will restore original receipt_no values for ids present
-- in receipt_dup_audit. Keep audit table around until you are sure of the results.
--
-- UPDATE transactions t
-- JOIN receipt_dup_audit a ON t.id = a.id
-- SET t.receipt_no = a.old_receipt_no;

-- After verifying everything, you may optionally drop the audit table or move it
-- to a long-term audit schema. Example:
-- DROP TABLE IF EXISTS receipt_dup_audit; -- only when you're certain
