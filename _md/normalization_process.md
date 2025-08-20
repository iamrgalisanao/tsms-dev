
# TMS Transactions Table Normalization Process

This document outlines the step-by-step normalization of the `transactions` table in MySQL (3NF), complete with SQL DDL and explanations.

---

## 1. Lookup Tables (Replace ENUMs)

```sql
CREATE TABLE validation_statuses (
  code        VARCHAR(20)    NOT NULL,
  description VARCHAR(100)   NOT NULL,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_statuses (
  code        VARCHAR(20)    NOT NULL,
  description VARCHAR(100)   NOT NULL,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- **Purpose**: Store allowable statuses for validation and job processing.  
- **Benefit**: Add new statuses without altering core tables.

---

## 2. Core Transactions Table

```sql
CREATE TABLE transactions (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id              BIGINT UNSIGNED NOT NULL,
  terminal_id            BIGINT UNSIGNED NOT NULL,
  transaction_id         CHAR(36)       NOT NULL UNIQUE,
  hardware_id            VARCHAR(191)   NOT NULL,
  machine_number         INT            NOT NULL,
  transaction_timestamp  DATETIME       NOT NULL,
  base_amount            DECIMAL(15,2)  NOT NULL,
  payload_checksum       CHAR(64)       NOT NULL,
  validation_status      VARCHAR(20)    NOT NULL,
  validation_details     TEXT,
  error_code             VARCHAR(191),
  created_at             TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_txn_tenant   FOREIGN KEY (tenant_id ) REFERENCES tenants(id)       ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_txn_terminal FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_txn_valid    FOREIGN KEY (validation_status) REFERENCES validation_statuses(code) ON DELETE RESTRICT ON UPDATE CASCADE,

  INDEX idx_txn_time   (transaction_timestamp),
  INDEX idx_txn_status (validation_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- **Goal**: Keep only core domain data.  
- **Notes**:  
  - `base_amount` replaces `gross_sales`.  
  - Narrow and indexed for reporting.

---

## 3. Transaction Adjustments

```sql
CREATE TABLE transaction_adjustments (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id   CHAR(36)       NOT NULL,
  adjustment_type  VARCHAR(50)    NOT NULL,
  amount           DECIMAL(15,2)  NOT NULL,
  created_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_adj_txn FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX idx_adj_txn_type (transaction_id, adjustment_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- **Use**: All discounts, promotions, and service charges.  
- **Flexibility**: Add new types via rows.

---

## 4. Transaction Taxes

```sql
CREATE TABLE transaction_taxes (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id   CHAR(36)       NOT NULL,
  tax_type         VARCHAR(20)    NOT NULL,
  amount           DECIMAL(15,2)  NOT NULL,

  CONSTRAINT fk_tax_txn FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX idx_tax_txn_type (transaction_id, tax_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- **Use**: VAT, VAT-exempt, and other taxes.

---

## 5. Transaction Jobs

```sql
CREATE TABLE transaction_jobs (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id   CHAR(36)       NOT NULL,
  job_status       VARCHAR(20)    NOT NULL,
  last_error       TEXT,
  attempts         INT UNSIGNED   NOT NULL DEFAULT 0,
  retry_count      INT UNSIGNED   NOT NULL DEFAULT 0,
  completed_at     TIMESTAMP      NULL,
  created_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_job_txn    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_job_status FOREIGN KEY (job_status)       REFERENCES job_statuses(code)       ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX idx_job_txn_status (transaction_id, job_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- **Responsibility**: Stores retry, status, and error metadata separately.

---

## 6. Optional: Move Validation Metadata

```sql
CREATE TABLE transaction_validations (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id     CHAR(36)        NOT NULL,
  status_code        VARCHAR(20)     NOT NULL,
  validation_details TEXT,
  error_code         VARCHAR(191),
  validated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_val_txn    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_val_status FOREIGN KEY (status_code)      REFERENCES validation_statuses(code)       ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX idx_val_txn        (transaction_id),
  INDEX idx_val_status     (status_code)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE transactions
  DROP COLUMN validation_status,
  DROP COLUMN validation_details,
  DROP COLUMN error_code;
```

- **Use**: Keep a history/audit of validations.

---

## 7. (Optional) Partitioning for Scale

```sql
ALTER TABLE transactions
  PARTITION BY RANGE (YEAR(transaction_timestamp)*100 + MONTH(transaction_timestamp)) (
    PARTITION p202501 VALUES LESS THAN (202502),
    PARTITION p202502 VALUES LESS THAN (202503),
    PARTITION pMax     VALUES LESS THAN MAXVALUE
  );
```

- **Benefit**: Time-based partitioning accelerates range queries and clean-up.

---

*End of normalization process.*
