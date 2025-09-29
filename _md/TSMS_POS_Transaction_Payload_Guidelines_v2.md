# TSMS POS Transaction Payload Guidelines

## Overview
This document provides the official format and requirements for POS terminal providers and tenants to submit single or batch transactions to the TSMS system.

## Table of Contents
1. [Transaction Submission Structure](#1-transaction-submission-structure)
2. [Field Requirements](#2-field-requirements)
3. [Financial Validation Rules](#3-financial-validation-rules)
4. [Required Field Types](#4-required-field-types)
5. [Field Ordering Requirements](#5-field-ordering-requirements)
6. [Payload Checksum Computation](#6-payload-checksum-computation)
7. [Authentication](#7-authentication)
8. [API Endpoints](#8-api-endpoints)
9. [Error Handling](#9-error-handling)
10. [Contact Information](#10-contact-information)

## 1. Transaction Submission Structure

### Single Transaction Format
```json
{
  "submission_uuid": "uuid-string",
  "tenant_id": "integer",
  "terminal_id": "integer",
  "submission_timestamp": "ISO8601-UTC",
  "transaction_count": 1,
  "payload_checksum": "64-char-hex",
  "transaction": {
    "transaction_id": "uuid-string",
    "transaction_timestamp": "ISO8601-UTC",
    "gross_sales": "decimal",
    "net_sales": "decimal",
    "promo_status": "string",
    "customer_code": "string",
    "payload_checksum": "64-char-hex",
    "adjustments": [
      {
        "adjustment_type": "string",
        "amount": "decimal"
      }
    ],
    "taxes": [
      {
        "tax_type": "string",
        "amount": "decimal"
      }
    ]
  }
}
```

### Batch Transaction Format
```json
{
  "submission_uuid": "uuid-string",
  "tenant_id": "integer",
  "terminal_id": "integer",
  "submission_timestamp": "ISO8601-UTC",
  "transaction_count": "integer > 1",
  "payload_checksum": "64-char-hex",
  "transactions": [
    {
      "transaction_id": "uuid-string",
      "transaction_timestamp": "ISO8601-UTC",
      "gross_sales": "decimal",
      "net_sales": "decimal",
      "promo_status": "string",
      "customer_code": "string",
      "payload_checksum": "64-char-hex",
      "adjustments": [
        {
          "adjustment_type": "string",
          "amount": "decimal"
        }
      ],
      "taxes": [
        {
          "tax_type": "string",
          "amount": "decimal"
        }
      ]
    }
  ]
}
```

## 2. Field Requirements

### Submission Level Fields
- **submission_uuid** (string, required): Unique UUID v4 for the submission
- **tenant_id** (integer, required): Tenant identifier assigned by TSMS
- **terminal_id** (integer, required): POS terminal identifier assigned by TSMS
- **submission_timestamp** (ISO8601 string, required): UTC timestamp when submission was sent (format: `YYYY-MM-DDTHH:MM:SSZ`)
- **transaction_count** (integer, required): Number of transactions in payload (1 for single, >1 for batch)
- **payload_checksum** (string, required): SHA-256 hash (64 hex characters) of full submission payload

### Transaction Level Fields
- **transaction_id** (string, required): Unique UUID v4 for the transaction
- **transaction_timestamp** (ISO8601 string, required): UTC timestamp when sale occurred (format: `YYYY-MM-DDTHH:MM:SSZ`)
- **gross_sales** (decimal, required): Total gross sales amount before any deductions (≥ 0, 2 decimal places)
- **net_sales** (decimal, required): Net sales amount after all deductions (2 decimal places)
- **promo_status** (string, required): Promotional status (`"WITH_APPROVAL"`, `"WITHOUT_APPROVAL"`, `"INACTIVE"`)
- **customer_code** (string, required): Customer identifier (cannot be empty)
- **payload_checksum** (string, required): SHA-256 hash (64 hex characters) of transaction payload
- **adjustments** (array, required): Minimum 7 adjustment entries
- **taxes** (array, required): Minimum 4 tax entries

### Adjustment Fields
- **adjustment_type** (string, required): Type of adjustment
- **amount** (decimal, required): Adjustment amount (2 decimal places)

### Tax Fields
- **tax_type** (string, required): Type of tax
- **amount** (decimal, required): Tax amount (2 decimal places)

## 3. Financial Validation Rules

### Core Financial Formula
```
net_sales = gross_sales - total_adjustments - other_tax
```

Where:
- **gross_sales**: Total sales amount before deductions
- **total_adjustments**: Sum of all adjustment amounts
- **other_tax**: Sum of tax amounts where `tax_type ≠ 'VAT'`

### Validation Tolerance
- Financial calculations allow ±0.01 rounding difference
- All monetary values must have exactly 2 decimal places

### Example Calculation
```
Gross Sales: 1,000.00
Adjustments: 50.00 (promo) + 20.00 (senior) = 70.00 total
Other Tax: 10.00 (OTHER_TAX only, VAT excluded)
Net Sales: 1,000.00 - 70.00 - 10.00 = 920.00
```

## 4. Required Field Types

### Required Adjustment Types (7 minimum)
1. `promo_discount`
2. `senior_discount`
3. `pwd_discount`
4. `vip_card_discount`
5. `service_charge_distributed_to_employees`
6. `service_charge_retained_by_management`
7. `employee_discount`

### Required Tax Types (4 minimum)
1. `VAT`
2. `VATABLE_SALES`
3. `SC_VAT_EXEMPT_SALES`
4. `OTHER_TAX` (or other tax types as needed)

## 5. Field Ordering Requirements

### Submission Level Ordering
```
submission_uuid → tenant_id → terminal_id → submission_timestamp → transaction_count → payload_checksum → transaction/transactions
```

### Transaction Level Ordering
```
transaction_id → transaction_timestamp → gross_sales → net_sales → promo_status → customer_code → payload_checksum → adjustments → taxes
```

**Critical**: `payload_checksum` must appear after all scalar fields but before array fields (`adjustments`, `taxes`).

## 6. Payload Checksum Computation

### Algorithm
- Use SHA-256 hashing algorithm
- Output must be 64 hexadecimal characters
- Use compact JSON format (no extra whitespace)

### Single Transaction Process
1. Create transaction object without `payload_checksum` field
2. Serialize to compact JSON string
3. Compute SHA-256 hash
4. Add hash as `payload_checksum` value
5. Create submission object with transaction (including its checksum)
6. Compute submission-level checksum (without submission `payload_checksum`)
7. Add submission hash as `payload_checksum`

### Batch Transaction Process
1. For each transaction: compute individual `payload_checksum` (following single transaction steps)
2. Create submission object with all transactions (including their checksums)
3. Compute submission-level checksum (without submission `payload_checksum`)
4. Add submission hash as `payload_checksum`

### Important Notes
- Field order must be consistent during serialization
- Exclude system-generated fields from checksum calculation
- Use the exact same JSON structure for both calculation and submission

## 7. Authentication

### API Token Requirements
- Each POS terminal receives a unique API token
- Include token in `Authorization: Bearer {token}` header
- Tokens are terminal-specific and cannot be shared

### Terminal Enrollment
- POS terminals must be enrolled in TSMS before transaction submission
- Enrollment is required for both live and testing environments
- Contact TSMS technical team for enrollment process

## 8. API Endpoints

### Submit Transaction
```
Method: POST
URL: https://stagingtsms.pitx.com.ph/api/v1/transactions/official
Headers:
  - Authorization: Bearer {api_token}
  - Content-Type: application/json
```

### Void Transaction
```
Method: PUT
URL: https://stagingtsms.pitx.com.ph/api/v1/transactions/{transaction_id}/void
Headers:
  - Authorization: Bearer {api_token}
  - Content-Type: application/json
Body:
  {
    "transaction_id": "{transaction_id}",
    "void_reason": "string",
    "payload_checksum": "64-char-hex"
  }
```

## 9. Error Handling

### Common HTTP Status Codes
- **200**: Success
- **401**: Unauthorized (invalid/missing API token)
- **422**: Validation failed (invalid payload structure/data)
- **404**: Transaction not found (for void operations)
- **429**: Rate limit exceeded

### Error Response Format
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "field_name": ["Error message 1", "Error message 2"],
    "structure": "Payload does not follow standard TSMS structure"
  },
  "structure_hint": "Ensure payload follows standard TSMS structure with payload_checksum positioned correctly"
}
```

### Validation Error Types
- **Missing required fields**
- **Invalid data types/formats**
- **Financial calculation mismatches**
- **Missing required adjustment/tax types**
- **Field ordering violations**
- **Checksum validation failures**

## 10. Contact Information

For questions or integration support, contact the TSMS technical team:
- **Email**: rgalisanao@pitx.com.ph
- **Subject**: TSMS Integration Support

---

## Examples

### Single Transaction Example
```json
{
  "submission_uuid": "e3b0c442-98fc-1c14-9afb-4c8996fb9242",
  "tenant_id": 45,
  "terminal_id": 5,
  "submission_timestamp": "2025-07-19T12:00:00Z",
  "transaction_count": 1,
  "payload_checksum": "7bccb261eb499ce776df26a37a57ac413acde53dbe587d2450ef14d895f3b507",
  "transaction": {
    "transaction_id": "f47ac10b-58cc-4372-a567-0e02b2c3d529",
    "transaction_timestamp": "2025-07-19T12:00:01Z",
    "gross_sales": 1000.00,
    "net_sales": 920.00,
    "promo_status": "WITH_APPROVAL",
    "customer_code": "CUST001",
    "payload_checksum": "7ba832a4d51f858298c8ccecb7de11eca740b7b400fc22b20f2a4b4ffa4c02d6",
    "adjustments": [
      {"adjustment_type": "promo_discount", "amount": 50.00},
      {"adjustment_type": "senior_discount", "amount": 20.00},
      {"adjustment_type": "pwd_discount", "amount": 0.00},
      {"adjustment_type": "vip_card_discount", "amount": 0.00},
      {"adjustment_type": "service_charge_distributed_to_employees", "amount": 0.00},
      {"adjustment_type": "service_charge_retained_by_management", "amount": 0.00},
      {"adjustment_type": "employee_discount", "amount": 0.00}
    ],
    "taxes": [
      {"tax_type": "VAT", "amount": 120.00},
      {"tax_type": "VATABLE_SALES", "amount": 0.00},
      {"tax_type": "SC_VAT_EXEMPT_SALES", "amount": 0.00},
      {"tax_type": "OTHER_TAX", "amount": 10.00}
    ]
  }
}
```

### Batch Transaction Example
```json
{
  "submission_uuid": "a1b2c3d4-5678-90ab-cdef-123456789012",
  "tenant_id": 45,
  "terminal_id": 5,
  "submission_timestamp": "2025-07-19T12:00:00Z",
  "transaction_count": 2,
  "payload_checksum": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08",
  "transactions": [
    {
      "transaction_id": "f47ac10b-58cc-4372-a567-0e02b2c3d529",
      "transaction_timestamp": "2025-07-19T12:00:01Z",
      "gross_sales": 1000.00,
      "net_sales": 920.00,
      "promo_status": "WITH_APPROVAL",
      "customer_code": "CUST001",
      "payload_checksum": "7ba832a4d51f858298c8ccecb7de11eca740b7b400fc22b20f2a4b4ffa4c02d6",
      "adjustments": [
        {"adjustment_type": "promo_discount", "amount": 50.00},
        {"adjustment_type": "senior_discount", "amount": 20.00},
        {"adjustment_type": "pwd_discount", "amount": 0.00},
        {"adjustment_type": "vip_card_discount", "amount": 0.00},
        {"adjustment_type": "service_charge_distributed_to_employees", "amount": 0.00},
        {"adjustment_type": "service_charge_retained_by_management", "amount": 0.00},
        {"adjustment_type": "employee_discount", "amount": 0.00}
      ],
      "taxes": [
        {"tax_type": "VAT", "amount": 120.00},
        {"tax_type": "VATABLE_SALES", "amount": 0.00},
        {"tax_type": "SC_VAT_EXEMPT_SALES", "amount": 0.00},
        {"tax_type": "OTHER_TAX", "amount": 10.00}
      ]
    },
    {
      "transaction_id": "b8c9d0e1-2345-6789-abcd-ef0123456789",
      "transaction_timestamp": "2025-07-19T12:01:00Z",
      "gross_sales": 500.00,
      "net_sales": 475.00,
      "promo_status": "WITHOUT_APPROVAL",
      "customer_code": "CUST002",
      "payload_checksum": "6f8d9c2a4b7e1f3a5c8d9e2b4f6a8c9d1e3b5f7a9c2d4e6b8f0a2c4d6e8b0",
      "adjustments": [
        {"adjustment_type": "promo_discount", "amount": 25.00},
        {"adjustment_type": "senior_discount", "amount": 0.00},
        {"adjustment_type": "pwd_discount", "amount": 0.00},
        {"adjustment_type": "vip_card_discount", "amount": 0.00},
        {"adjustment_type": "service_charge_distributed_to_employees", "amount": 0.00},
        {"adjustment_type": "service_charge_retained_by_management", "amount": 0.00},
        {"adjustment_type": "employee_discount", "amount": 0.00}
      ],
      "taxes": [
        {"tax_type": "VAT", "amount": 60.00},
        {"tax_type": "VATABLE_SALES", "amount": 0.00},
        {"tax_type": "SC_VAT_EXEMPT_SALES", "amount": 0.00},
        {"tax_type": "OTHER_TAX", "amount": 0.00}
      ]
    }
  ]
}
```

---

**Version**: 2.0
**Last Updated**: September 9, 2025
**Document Status**: Official TSMS Integration Guide</content>
<parameter name="filePath">/Users/teamsolo/Projects/PITX/tsms-dev/_md/TSMS_POS_Transaction_Payload_Guidelines_v2.md
