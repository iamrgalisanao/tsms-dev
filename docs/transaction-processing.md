# Transaction Processing Documentation

## Overview

The Transaction Processing Pipeline handles POS transactions through multiple stages from ingestion to completion.

## Components

### 1. Transaction Validation

-   Request validation via `TransactionRequest`
-   Business rules validation through `TransactionValidationService`
-   Supports both JSON and legacy text formats

### 2. Queue Configuration

```bash
QUEUE_CONNECTION=redis
REDIS_QUEUE=transactions
QUEUE_RETRY_AFTER=90
```

### 3. Job Status Tracking

Status transitions:

-   `pending` → Initial state
-   `processing` → Being processed
-   `completed` → Successfully processed
-   `failed` → Processing failed

### 4. API Endpoints

#### Submit Transaction

```http
POST /api/v1/transactions
Content-Type: application/json

{
    "terminal_id": "TERM123",
    "amount": 100.00,
    "type": "PAYMENT",
    "reference_number": "TXN123"
}
```

#### Check Status

```http
GET /api/v1/transactions/{id}/status
```

## Testing Scenarios

Refer to `tests/Feature/TransactionValidationTest.php` for example test cases.

## UAT Checklist

-   [ ] Transaction submission successful
-   [ ] Status updates correctly tracked
-   [ ] Error handling works as expected
-   [ ] Retry mechanism functions properly
