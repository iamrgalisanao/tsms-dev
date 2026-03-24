# TSMS Integration Test Scenarios (Staging Environment)

This document provides a set of functional test scenarios for POS providers to verify their integration with the TSMS ingestion engine. These tests are conducted against the **Live Staging Environment** to ensure full operational readiness.

---

## 1. System Connectivity (Staging)

All API calls must use the **Sanctum-protected V1 prefix**. Failure to include `/api/v1/` will result in routing errors.

| Aspect | Specification |
| :--- | :--- |
| **Base URL** | `https://stagingtsms.pitx.com.test` |
| **Submission Endpoint** | `POST /api/v1/transactions/official` |
| **Status Endpoint** | `GET /api/v1/transactions/{transaction_id}/status` |
| **Content-Type** | `application/json` |
| **Authentication** | `Authorization: Bearer <YOUR_ACCESS_TOKEN>` |

> [!IMPORTANT]
> **Mandatory Prefix**: Ensure all requests are prefixed with `/api/v1/`. Direct calls to `/transactions/official` without the prefix are not supported and will return a `405 Method Not Allowed` error.

---

## 2. System Integration Requirements

To execute these tests, ensure the following are in place:
- **API Access Token**: A valid Bearer token provided during your POS terminal onboarding.
- **Data Integrity**: All transaction blocks must include a `payload_checksum` for security.
- **Unique Submission ID**: Use a unique `submission_uuid` for every new submission to prevent duplicate processing.
- **Validation**: System checks for valid JSON structure, correct integrity checksums, and mathematical consistency across sales fields.

---

## 3. Core Testing Objectives

To ensure a high-quality integration, focus on these four key areas:

- **Data Integrity**: Verify that your system correctly calculates the SHA-256 `payload_checksum` for both the transaction and the entire submission. This is mandatory for security.
- **Mathematical Reconciliation**: Ensure your calculation of Gross Sales, Net Sales, VAT, and Discounts matches the TSMS internal validation rules to avoid "FAILED" flags in the dashboard.
- **Network Resilience (Idempotency)**: Verify that retrying the exact same `submission_uuid` and `transaction_id` (e.g., after a timeout) results in a successful `already_processed` status without creating duplicate records.
- **Full Lifecycle Visibility**: Confirm that after a successful API response, the transaction correctly transitions from `PENDING` to `VALID` and is visible in the Merchant Dashboard.

---

## 4. Functional Test Cases

### TC-VAL-01: Standard VATable Sale
| Attribute | Details |
| :--- | :--- |
| **Objective** | Verify the system correctly processes a standard 12% VAT transaction. |
| **Pre-conditions** | Terminal is registered; active Access Token is used. |
| **Expected Result** | `201 Created` status; The record is visible in the Operational Dashboard. |

**Sample JSON Structure:**
```json
{
  "submission_uuid": "550e8400-e29b-41d4-a716-446655440001",
  "tenant_id": 1,
  "terminal_id": 1,
  "submission_timestamp": "2023-11-01T10:00:00Z",
  "transaction_count": 1,
  "payload_checksum": "PLACEHOLDER_64_CHAR_HASH",
  "transaction": {
    "transaction_id": "SALE-001",
    "hardware_id": "POS-TERM-01",
    "receipt_no": "REC-0001",
    "transaction_timestamp": "2023-11-01T09:55:00Z",
    "gross_sales": "1120.00",
    "net_sales": "1120.00",
    "promo_status": "NONE",
    "customer_code": "TEST001",
    "payload_checksum": "PLACEHOLDER_64_CHAR_HASH",
    "adjustments": [],
    "taxes": [
      {
        "tax_type": "VATABLE_SALES",
        "amount": "1120.00"
      },
      {
        "tax_type": "VAT",
        "amount": "120.00"
      }
    ]
  }
}
```

### TC-VAL-02: VAT-Exempt Transaction (Senior Citizen/PWD)
| Attribute | Details |
| :--- | :--- |
| **Objective** | Verify that VAT-exempt transactions with required discounts are processed. |
| **Logic** | Initial Price (VAT inc): 112.00 -> Base Amount: 100.00 -> 20% Discount (20.00) -> Paid: 80.00. |
| **Expected Result** | `201 Created`; Total amount paid matches the input values. |

**Sample JSON Structure:**
```json
{
  "submission_uuid": "550e8400-e29b-41d4-a716-446655440002",
  "tenant_id": 1,
  "terminal_id": 1,
  "submission_timestamp": "2023-11-01T10:05:00Z",
  "transaction_count": 1,
  "payload_checksum": "PLACEHOLDER_64_CHAR_HASH",
  "transaction": {
    "transaction_id": "SALE-002",
    "hardware_id": "POS-TERM-01",
    "receipt_no": "REC-0002",
    "transaction_timestamp": "2023-11-01T10:02:00Z",
    "gross_sales": "80.00",
    "net_sales": "100.00",
    "promo_status": "NONE",
    "customer_code": "TEST001",
    "payload_checksum": "PLACEHOLDER_64_CHAR_HASH",
    "adjustments": [
      {
        "adjustment_type": "senior_discount",
        "amount": "20.00"
      }
    ],
    "taxes": [
      {
        "tax_type": "SC_VAT_EXEMPT_SALES",
        "amount": "100.00"
      }
    ]
  }
}
```

---

## 5. Reliability & Security Testing

### TC-ERR-01: Data Integrity Check (Invalid Checksum)
| Attribute | Details |
| :--- | :--- |
| **Objective** | Ensure the server rejects payloads where the data has been altered. |
| **Step** | Alter an amount in the payload but send the original integrity checksum. |
| **Expected Result** | `422 Unprocessable Content`; Error message indicating data tampering. |

### TC-ERR-02: Unauthorized Access
| Attribute | Details |
| :--- | :--- |
| **Objective** | Ensure the API is protected against unauthorized requests. |
| **Step** | Send a valid payload with an incorrect or expired Access Token. |
| **Expected Result** | `401 Unauthorized`. |

### TC-IDEM-01: Idempotency (Duplicate Handling)
| Attribute | Details |
| :--- | :--- |
| **Objective** | Ensure a transaction is not processed twice if the same ID is reused. |
| **Step** | Send the exact same transaction request (same UUID) twice. |
| **Expected Result** | `200 OK` with `already_processed` status in the response array. |

---

## 6. Boundary & Format Testing

| Test ID | Area | Scenario | Expected Outcome |
| :--- | :--- | :--- | :--- |
| **TC-BND-01** | Amounts | Minimum valid amount (0.01 PHP). | `201 Created`. |
| **TC-BND-02** | Receipt Number | Maximum length (128 characters). | `201 Created`. |
| **TC-BND-03** | Receipt Number | Leading zero preservation (e.g. `"0005"`). | `201 Created`. |

---

## 7. Verification Steps

After receiving a successful API response, the POS provider should perform the following:
1.  **Poll Status**: Query the status endpoint to verify the transaction transitioned to **VALID**.
2.  **Merchant Portal**: Log in to the provided staging portal and confirm the transaction appears in the live logs with the correct details.
3.  **Audit History**: Ensure that the `transaction_id` and `receipt_no` are recorded exactly as sent.
