# TSMS API Integration Guidelines for POS Providers

**Document Code**: TSMS-INTG-2025-001  
**Prepared by**: Rommel Galisanao  
**Date**: April 4, 2025  
**Version**: 1.0

---

## 1. Objective

This document provides technical guidelines for POS providers to integrate with the **Tenant Sales Management System (TSMS)**. It ensures secure, real-time or scheduled sales data transmission with full validation and audit logging.

---

## 2. Integration and Communication Overview

| Aspect                 | Description                                              |
| ---------------------- | -------------------------------------------------------- |
| Integration Type       | RESTful API over HTTPS (TLS 1.2+)                        |
| Payload Format         | JSON (UTF-8 encoded)                                     |
| Authentication         | JWT Token (passed via `Authorization` header)            |
| Transmission Mode      | Real-time (preferred) or Scheduled Batch                 |
| Middleware Role        | Handles retries, transformation, validation, and logging |
| Data Standard          | Follows TSMS Data Dictionary and schema validation       |
| Outbound Communication | Optional webhook for errors or feedback                  |
| Resilience             | POS must support retry queues and exponential backoff    |

---

## 3. Authentication & Security

-   JWT Bearer Tokens (scoped per tenant).
-   Secure HTTPS with TLS 1.2+.
-   Example:  
    `Authorization: Bearer <JWT_TOKEN>`

---

## 4. API Endpoint Example

**POST** `https://tms.example.com/api/v1/transactions`  
**Headers**:

-   `Content-Type: application/json`
-   `Authorization: Bearer <JWT_TOKEN>`

---

## 5. JSON Payload Schema

### Required Fields

-   `tenant_id`, `hardware_id`, `transaction_id`, `transaction_timestamp`
-   Sales: `vatable_sales`, `net_sales`, `vat_exempt_sales`, `gross_sales`, etc.
-   Discounts: `promo_discount_amount`, `promo_status`, `discount_total`, `discount_details`
-   Charges: `management_service_charge`, `employee_service_charge`, `other_tax`
-   `vat_amount`, `transaction_count`, `payload_checksum`, `validation_status`, `error_code`

### Sample Payload

```json
{
    "tenant_id": "C-T1005",
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

## 6. Error Handling & Response Codes

| HTTP Code | Meaning                         |
| --------- | ------------------------------- |
| 200       | Payload accepted                |
| 400       | Invalid fields or format        |
| 401       | Unauthorized token              |
| 409       | Duplicate `transaction_id`      |
| 500       | Server error (retry applicable) |

---

## 7. Validation & Retry Logic

-   TSMS validates each payload against schema and business rules.
-   Middleware automatically retries on `500` or network errors.
-   Invalid payloads (e.g., `400` or `409`) are logged and excluded from retries.
-   Admin UI supports dynamic transformation overrides for varied formats.

---

## 8. Client Resilience and Offline Handling

To ensure seamless operation during intermittent connectivity, POS systems must implement local queuing and retry logic.

### 8.1 Exponential Backoff Strategy

When a transaction POST fails due to a `500` or network error, the POS should retry the request using exponential backoff:

| Attempt | Delay      |
| ------- | ---------- |
| 1st     | 2 seconds  |
| 2nd     | 4 seconds  |
| 3rd     | 8 seconds  |
| 4th     | 16 seconds |
| 5th     | 32 seconds |

After the final attempt fails, the transaction must be queued and retried once the server is reachable.

### 8.2 Retry Queue Requirements

-   **Persistence**: The queue must survive POS system restarts (e.g., save to disk).
-   **FIFO Order**: Transactions must be retried in the order they were generated.
-   **Checksum Integrity**: Retried transactions must retain their original `payload_checksum`.
-   **Retention Policy**: All queued transactions must be retried within **48 hours** or flagged for manual intervention.

---

## 9. Receiving TSMS Notifications

### 9.1 Webhook Integration

POS systems may provide a **webhook endpoint** that TSMS will call with transaction status updates. The payload includes:

-   `transaction_id`
-   `type`
-   `error_message`

**Behavior**:

-   POS must respond with HTTP `200`.
-   If not acknowledged, TSMS will retry the webhook up to 3 times.
-

### 9.2 Polling Endpoint (Alternative)

If the POS does not support webhooks, it may poll for updates:

-   GET /api/v1/notifications?hardware_id=XYZ123

## 10. Token Expiry and Refresh

JWT tokens issued by TSMS may expire after **24 hours**. POS systems must implement logic to detect expiration and refresh tokens accordingly.

### 10.1 Refresh Workflow

Until the refresh endpoint is deployed:

-   Tokens will have an extended validity period (7–14 days).
-   Token reissuance can be done manually via the TSMS admin panel.
-

## 11. Health Check Endpoint

To verify server availability, POS systems may use:

-   GET /api/v1/healthcheck
-   No authentication required
-   Returns server uptime info
