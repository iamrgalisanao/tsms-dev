# TSMS-INTG-2025-001

## Tenant Sales Management System (TSMS) API Integration Guidelines for POS Providers

**Prepared by:** Rommel Galisanao  
**Date:** April 4, 2025  
**Version:** 1.0

---

## 1. Objective

This document provides comprehensive technical guidelines for POS providers to enable seamless integration with the Tenant Sales Management System (TSMS). The integration ensures secure, accurate, and real-time or scheduled transmission of sales data with full error-handling, validation, and audit capabilities.

---

## 2. Overview of Integration and Communication Channel

| Aspect                 | Description                                                                            |
| ---------------------- | -------------------------------------------------------------------------------------- |
| Integration Type       | RESTful API over HTTPS (TLS 1.2+)                                                      |
| Payload Format         | JSON (UTF-8 encoded)                                                                   |
| Authentication         | JWT Token (tenant-scoped, passed via Authorization header)                             |
| Transmission Mode      | Real-time (preferred) or Scheduled Batch                                               |
| Middleware Role        | Handles retry queues, transformation logic, validation, and audit logging              |
| Data Standard          | All payloads must adhere to the TSMS Data Dictionary and schema validation rules       |
| Outbound Communication | Optional webhook push from TSMS to registered POS endpoint                             |
| Resilience Support     | POS systems should implement retry queues and exponential backoff for transient errors |

---

## 3. Authentication and Security

-   All API requests must be authenticated using JWT Bearer Tokens.
-   Tokens are issued per tenant and scoped for POS Integration.
-   Secure transport via HTTPS (TLS 1.2+).
-   Example:  
    `Authorization: Bearer <JWT_TOKEN>`

---

## 4. API Endpoint Example

**Production Endpoint:**

```
POST https://tms.example.com/api/v1/transactions
```

**Headers:**

```
Content-Type: application/json
Authorization: Bearer <JWT_TOKEN>
```

---

## 5. JSON Payload Schema

Includes:

-   `tenant_id`, `hardware_id`, `transaction_id`, `transaction_timestamp`
-   Sales fields: `vatable_sales`, `gross_sales`, etc.
-   Discounts and promos
-   Integrity: `payload_checksum` (SHA-256)
-   Validation status and error code

**Sample Payload:**

```json
{
    "tenant_id": "C-T1005",
    "terminal_id": 1,
    "hardware_id": "7P589L2",
    "machine_number": 6,
    "transaction_id": "8a918a90-7cbd-4b44-adc0-bc3d31cee238",
    "store_name": "ABC Store #102",
    "transaction_timestamp": "2025-03-26T13:45:00Z",
    "vatable_sales": 12000.0,
    "net_sales": 18137.0,
    "vat_exempt_sales": 6137.0,
    "promo_discount_amount": 100.0,
    "promo_status": "WITH_APPROVAL",
    "discount_total": 50.0,
    "discount_details": {
        "Employee": "20.00",
        "Senior": "30.00"
    },
    "other_tax": 50.0,
    "management_service_charge": 8.5,
    "employee_service_charge": 4.0,
    "gross_sales": 12345.67,
    "vat_amount": 1500.0,
    "transaction_count": 1,
    "payload_checksum": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    "validation_status": "VALID",
    "error_code": ""
}
```

---

## 6. Error Handling and Response Codes

| Code | Meaning                                |
| ---- | -------------------------------------- |
| 200  | OK – Payload accepted                  |
| 400  | Bad Request – Invalid fields or format |
| 401  | Unauthorized – Token issues            |
| 409  | Conflict – Duplicate `transaction_id`  |
| 500  | Server Error – Retry via middleware    |

---

## 7. Validation & Retry Logic

-   TSMS validates payload against schema and rules.
-   Middleware retries on 500/network errors.
-   Invalid payloads (400/409) are logged and skipped for retry.
-   Supports dynamic transformation in admin UI.

---

## 8. Client Resilience and Offline Handling

### 8.1 Exponential Backoff Strategy

Recommended schedule:

-   1st: 2 seconds
-   2nd: 4 seconds
-   3rd: 8 seconds
-   4th: 16 seconds
-   5th: 32 seconds

Queue and resume when server is back.

### 8.2 Retry Queue Requirements

-   Must persist across restarts
-   FIFO logic required
-   Preserve original `payload_checksum`
-   Max retry window: 48 hours

---

## 9. Receiving TSMS Notifications

### 9.1 Webhook Integration

-   TSMS can notify POS of validation status via webhook.
-   Must respond with HTTP 200.
-   Retries up to 3 times on failure.

### 9.2 Polling Endpoint (Alternative)

```
GET /api/v1/notifications?hardware_id=XYZ123
```

---

## 10. Token Expiry and Refresh

-   JWT tokens expire after 24 hours.
-   Long expiry (7–14 days) during initial phase.
-   Can be reissued from TSMS admin UI.

---

## 11. Health Check Endpoint

To verify server status:

```
GET /api/v1/healthcheck
```

Authentication not required.

---

## Disclaimer

API structure and flow may evolve, but the data dictionary is considered final and authoritative. POS providers must adhere to it for reliable integration.
