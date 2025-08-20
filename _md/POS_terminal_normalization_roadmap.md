# Last Update

**Date:** July 3, 2025

**Notes:**

-   All controllers and services that previously referenced the removed `terminal_uid` field have been updated to use the new `serial_number` field, in line with the normalized schema.
-   All Eloquent models, validation, and business logic now use foreign key relationships and lookup tables for POS terminal types, integration types, auth types, and statuses.
-   The codebase is now free of legacy enum/string references for terminal type/status/auth/integration, and all config fields have been moved to the `terminal_configs` table.
-   The roadmap checklist and "To Revisit" section have been updated to reflect completed and pending work.
-   Moving forward, `tenant_id` will be replaced with `customer_code` as the primary customer identifier in all transaction-related logic and payloads.

# Progress & Implementation Checklist

This section tracks all normalization tasks for the POS Terminal project. Update this as you complete each requirement.

---

## ✅ Completed

-   Database schema updated: lookup tables and foreign keys (`pos_type_id`, `integration_type_id`, `auth_type_id`, `status_id`) added to `pos_terminals`.
-   `terminal_configs` table created and linked 1:1 with `pos_terminals`.
-   All Eloquent models updated:
    -   `PosTerminal` uses new relationships and fields, config fields removed.
    -   `TerminalConfig` model created and configured.
    -   Lookup models (`PosType`, `IntegrationType`, `AuthType`, `TerminalStatus`) have correct relationships.
-   Validation and creation logic in `RegisterTerminalController` updated to use new fields and relationships.
-   All controllers/services updated to use `serial_number` instead of `terminal_uid`.
-   All controller/service logic updated to use foreign key relationships and lookup tables (no more enum strings).

---

## ⏳ In Progress / Next Steps

-   [ ] Update UI forms to use lookup tables for dropdowns and display names via relationships.
-   [ ] Update queries and reports to join lookup tables for human-readable names.
-   [ ] Update and add tests for new relationships, validation, and config logic.
-   [ ] Seed lookup tables and migrate any legacy data.
-   [ ] Update documentation and developer guides.

---

_Update this checklist as you complete each step to ensure a smooth and trackable normalization process._

---

# POS Terminal Normalization Roadmap

This document outlines the changes and steps required to normalize the `pos_terminals` table and related application logic in the PITX TSMS system.

---

## 🔄 To Revisit: Terminal Registration

-   Add logic to create and update `TerminalConfig` when registering or updating a terminal.
-   Decide which config fields are required/optional at registration and update validation accordingly.
-   Ensure registration endpoint returns config data as needed for UI/UX.
-   Review and refactor any legacy logic or fields related to terminal registration.
-   Add/expand tests for registration with config and lookup fields.

---

## 1. Replace ENUMs with Lookup Tables

**Current:**  
`pos_terminals` uses ENUM columns for types, integration, authentication, and status.

```
CREATE TABLE pos_types (
id TINYINT UNSIGNED PRIMARY KEY,
name VARCHAR(50) NOT NULL UNIQUE -- e.g. 1 → 'F&B', 2 → 'Retail'
);


CREATE TABLE integration_types (
id TINYINT UNSIGNED PRIMARY KEY,
name VARCHAR(50) NOT NULL UNIQUE -- e.g. 1 → 'API', 2 → 'SFTP', 3 → 'Manual'
);

CREATE TABLE auth_types (
id TINYINT UNSIGNED PRIMARY KEY,
name VARCHAR(50) NOT NULL UNIQUE -- e.g. 1 → 'JWT', 2 → 'API_KEY'
);

CREATE TABLE terminal_statuses (
id TINYINT UNSIGNED PRIMARY KEY,
name VARCHAR(50) NOT NULL UNIQUE -- e.g. 1 → 'active', 2 → 'inactive', 3 → 'suspended'
);
```

**Target:**  
Replace ENUMs with foreign keys referencing dedicated lookup tables:

-   `pos_types`
-   `integration_types`
-   `auth_types`
-   `terminal_statuses`

**Benefits:**

-   Easier to add new types/statuses without migrations.
-   Improved referential integrity.
-   Cleaner, more maintainable schema.

---

## 2. Core Table Adjustments

-   Replace `terminal_uid` with `serial_number` (unique).
-   Use `status_id` (foreign key) instead of a status ENUM.
-   Ensure `tenant_id` is a foreign key.
-   Standardize timestamps (`created_at`, `updated_at`).
-

```
CREATE TABLE pos_terminals (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id           BIGINT UNSIGNED         NOT NULL,
  provider_id         BIGINT UNSIGNED         NULL,
  serial_number       VARCHAR(191)            NOT NULL UNIQUE,
  machine_number      VARCHAR(191)            NULL,
  supports_guest_count TINYINT(1)             NOT NULL DEFAULT 0,

  -- new nullable lookup FKs for full normalization
  pos_type_id         TINYINT UNSIGNED        NULL,
  integration_type_id TINYINT UNSIGNED        NULL,
  auth_type_id        TINYINT UNSIGNED        NULL,

  status_id           TINYINT UNSIGNED        NOT NULL,
  created_at          TIMESTAMP               NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP               NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expires_at          DATETIME                NULL,

  -- indexes & constraints
  INDEX (tenant_id, status_id),
  FOREIGN KEY (tenant_id)   REFERENCES tenants(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  FOREIGN KEY (provider_id) REFERENCES pos_providers(id)
    ON DELETE SET NULL  ON UPDATE CASCADE,
  FOREIGN KEY (pos_type_id)         REFERENCES pos_types(id)
    ON DELETE SET NULL  ON UPDATE CASCADE,
  FOREIGN KEY (integration_type_id) REFERENCES integration_types(id)
    ON DELETE SET NULL  ON UPDATE CASCADE,
  FOREIGN KEY (auth_type_id)        REFERENCES auth_types(id)
    ON DELETE SET NULL  ON UPDATE CASCADE,
  FOREIGN KEY (status_id)           REFERENCES terminal_statuses(id)
);
```

---

## 3. Move Config Fields to `terminal_configs`

-   Create a new `terminal_configs` table for frequently changing or optional configuration fields.
-   Link `terminal_configs` 1:1 with `pos_terminals` via `terminal_id`.

```
CREATE TABLE terminal_configs (
  terminal_id         BIGINT UNSIGNED PRIMARY KEY,
  webhook_url         VARCHAR(255),
  max_retries         SMALLINT UNSIGNED DEFAULT 3,
  retry_interval_sec  INT UNSIGNED       DEFAULT 300,
  retry_enabled       BOOLEAN            DEFAULT TRUE,
  ip_whitelist        VARCHAR(255),
  device_fingerprint  VARCHAR(255),
  is_sandbox          BOOLEAN            DEFAULT FALSE,

  FOREIGN KEY (terminal_id) REFERENCES pos_terminals(id)
    ON DELETE CASCADE ON UPDATE CASCADE
);
```

---

## 4. Application Model & Logic Updates

-   Update Eloquent models to use new relationships for lookup tables and configs.
-   Update validation rules to check for valid foreign key IDs instead of ENUM strings.
-   Update business logic to use IDs for type/status checks.
-   Update UI forms to populate dropdowns from lookup tables.
-   Update queries and reports to join lookup tables for human-readable names.

---

## 5. Data Migration

-   Write migration scripts to:
    -   Populate lookup tables with existing ENUM values.
    -   Update `pos_terminals` to use foreign key IDs.
    -   Move config data to `terminal_configs`.

---

## 6. Testing & Seeding

-   Update and add tests to cover new relationships and validation.
-   Seed lookup tables with initial values.

---

## 7. Summary Table

| Area            | Change Required? | Notes                                                        |
| --------------- | ---------------- | ------------------------------------------------------------ |
| Models          | Yes              | Add relationships for lookups/configs, update field names    |
| Validation      | Yes              | Validate foreign keys, not enums                             |
| Business Logic  | Yes              | Use IDs, not strings, for type/status logic                  |
| UI/UX           | Yes              | Populate selects from lookup tables, display names via joins |
| Data Migration  | Yes              | Migrate enums/strings to lookup IDs, move config fields      |
| Queries/Reports | Yes              | Update to use joins for descriptive names                    |
| Testing/Seeding | Yes              | Update tests, seed lookup tables                             |

---

## 8. Transaction Model Adjustments

-   Remove any `store()` relationship; use `trade_name` instead.
-   Update any business logic or validation that referenced the old store relationship.
-   Ensure all protected `$fillable` and `$casts` fields match the latest schema.
-   Update or remove any logic that depended on the store relationship.

---

## 9. Rollout Steps

1.  Create lookup tables and `terminal_configs`.
2.  Update models and relationships.
3.  Migrate existing data.
4.  Update validation, business logic, and UI.
5.  Test thoroughly.
6.  Deploy
