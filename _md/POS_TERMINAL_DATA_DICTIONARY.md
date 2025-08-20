# POS Terminal Data Dictionary & Bulk Upload Guide

This document describes the required data structure for bulk or single upload of POS terminal details, including all related lookup tables. Use this as a reference for preparing CSV, Excel, or JSON files for import.

---

## Main Table: `pos_terminals`
| Field                | Type         | Required | Description                                                      | Example                |
|----------------------|--------------|----------|------------------------------------------------------------------|------------------------|
| id                   | bigint       | Auto     | Primary key (auto-increment)                                     | 1                      |
| tenant_id            | bigint       | Yes      | FK to `tenants.id`                                               | 101                    |
| serial_number        | string(191)  | Yes      | Unique serial number of the terminal                             | "SN-123456"           |
| api_key              | string       | No       | API key for terminal (nullable)                                  | "api_abcdef123456"    |
| is_active            | boolean      | No       | Terminal active status (default: true)                           | true                   |
| machine_number       | string(191)  | No       | Machine number (nullable)                                        | "MACH-001"            |
| supports_guest_count | boolean      | No       | Supports guest count (default: false)                            | false                  |
| pos_type_id          | bigint       | No       | FK to `pos_types.id` (nullable)                                  | 2                      |
| integration_type_id  | bigint       | No       | FK to `integration_types.id` (nullable)                          | 1                      |
| auth_type_id         | bigint       | No       | FK to `auth_types.id` (nullable)                                 | 1                      |
| status_id            | bigint       | Yes      | FK to `terminal_statuses.id`                                     | 1                      |
| expires_at           | datetime     | No       | Expiry date (nullable)                                           | "2025-12-31 23:59:59" |
| registered_at        | timestamp    | Yes      | Registration timestamp                                           | "2025-07-01 10:00:00" |
| last_seen_at         | timestamp    | No       | Last transaction timestamp (nullable)                            | "2025-07-19 09:00:00" |
| heartbeat_threshold  | int          | No       | Seconds before marking inactive (default: 300)                   | 300                    |
| created_at           | timestamp    | Auto     | Record creation timestamp                                        | "2025-07-01 10:00:00" |
| updated_at           | timestamp    | Auto     | Record update timestamp                                          | "2025-07-19 09:00:00" |

---

## Lookup Tables

### 1. `tenants`
| Field         | Type    | Required | Description                        | Example         |
|---------------|---------|----------|------------------------------------|-----------------|
| id            | bigint  | Auto     | Primary key                        | 101             |
| company_id    | bigint  | Yes      | FK to `companies.id`               | 1               |
| customer_code | string  | No       | Unique code for tenant (nullable)  | "CUST-001"     |
| trade_name    | string  | Yes      | Registered trade name              | "Store ABC"    |
| location_type | enum    | No       | Kiosk/Inline (nullable)            | "Kiosk"        |
| location      | string  | No       | Mall/area description (nullable)   | "Mall X"       |
| unit_no       | string  | No       | Stall/unit number (nullable)       | "A-12"         |
| floor_area    | decimal | No       | Area in sqm (nullable)             | 25.5            |
| status        | enum    | Yes      | Operational/Not Operational        | "Operational"  |
| category      | enum    | No       | F&B/Retail/Services (nullable)     | "F&B"          |
| created_at    | timestamp | Auto   | Record creation timestamp          | "2025-07-01"   |
| updated_at    | timestamp | Auto   | Record update timestamp            | "2025-07-19"   |

### 2. `companies`
| Field         | Type    | Required | Description                        | Example         |
|---------------|---------|----------|------------------------------------|-----------------|
| id            | bigint  | Auto     | Primary key                        | 1               |
| customer_code | string  | Yes      | Unique company code                | "CUST-001"     |
| company_name  | string  | Yes      | Company name                       | "Company XYZ"  |
| tin           | string  | Yes      | Tax Identification Number          | "123-456-789"  |
| created_at    | timestamp | Auto   | Record creation timestamp          | "2025-07-01"   |
| updated_at    | timestamp | Auto   | Record update timestamp            | "2025-07-19"   |

### 3. `pos_types`
| Field | Type   | Required | Description         | Example      |
|-------|--------|----------|---------------------|--------------|
| id    | bigint | Auto     | Primary key         | 1            |
| name  | string | Yes      | POS type name       | "Android"   |

### 4. `integration_types`
| Field | Type   | Required | Description         | Example      |
|-------|--------|----------|---------------------|--------------|
| id    | bigint | Auto     | Primary key         | 1            |
| name  | string | Yes      | Integration type    | "REST API"  |

### 5. `auth_types`
| Field | Type   | Required | Description         | Example      |
|-------|--------|----------|---------------------|--------------|
| id    | bigint | Auto     | Primary key         | 1            |
| name  | string | Yes      | Auth type name      | "Sanctum"   |

### 6. `terminal_statuses`
| Field | Type   | Required | Description         | Example      |
|-------|--------|----------|---------------------|--------------|
| id    | bigint | Auto     | Primary key         | 1            |
| name  | string | Yes      | Status name         | "active"    |

---

## Data Structure Example (JSON)
```json
{
  "tenant_id": 101,
  "serial_number": "SN-123456",
  "api_key": "api_abcdef123456",
  "is_active": true,
  "machine_number": "MACH-001",
  "supports_guest_count": false,
  "pos_type_id": 2,
  "integration_type_id": 1,
  "auth_type_id": 1,
  "status_id": 1,
  "expires_at": "2025-12-31 23:59:59",
  "registered_at": "2025-07-01 10:00:00",
  "last_seen_at": "2025-07-19 09:00:00",
  "heartbeat_threshold": 300
}
```

## Bulk Upload Guidelines
- All foreign key fields (`tenant_id`, `pos_type_id`, etc.) must reference valid IDs in their respective tables.
- For lookups, ensure referenced values exist before upload (e.g., create tenants, pos_types, etc. first).
- `serial_number` must be unique for each terminal.
- `api_key` can be auto-generated or provided.
- For CSV/Excel, use column names matching the field names above.
- Dates/timestamps should be in `YYYY-MM-DD HH:MM:SS` format.
- Boolean fields: use `true`/`false` or `1`/`0`.
- For single upload, provide all required fields in the request body.

## Related Table Population
- **Tenants:** Create tenants first, referencing their company and providing all required details.
- **Companies:** Ensure company exists before linking tenants.
- **POS Types, Integration Types, Auth Types, Statuses:** Seed these tables with all possible values before uploading terminals.

## Validation Checklist
- [ ] All required fields present
- [ ] All foreign keys valid
- [ ] Unique serial numbers
- [ ] Proper date/time formats
- [ ] Boolean values correct
- [ ] Lookup tables populated

---

**Use this guide to prepare your data for bulk or single POS terminal uploads.**
