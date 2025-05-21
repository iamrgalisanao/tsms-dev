# Transaction Processing API Documentation

## Overview
The Transaction Processing API provides endpoints for submitting and monitoring POS transactions with comprehensive validation, error handling, and retry mechanisms.

## Authentication
All endpoints require JWT authentication using bearer tokens.

## Endpoints

### 1. Submit Transaction
`POST /api/v1/transactions`

#### Headers
```http
Authorization: Bearer {jwt_token}
Content-Type: application/json|text/plain
X-Terminal-ID: {terminal_id}
X-Transaction-ID: {unique_transaction_id}
```

#### Request Formats

##### JSON Format
```json
{
    "transaction_id": "TXN-{timestamp}",
    "hardware_id": "HW-001",
    "gross_sales": 1220.00,
    "net_sales": 1100.00,
    "vatable_sales": 1000.00,
    "vat_exempt_sales": 100.00,
    "vat_amount": 120.00,
    "management_service_charge": 0.00,
    "discount_total": 0.00,
    "transaction_count": 1,
    "machine_number": 1
}
```

##### Text Format Support
Supports multiple formats:
```plaintext
# KEY: VALUE Format
TXNID: TXN-123
GROSS: 1220.00
NET: 1100.00

# KEY=VALUE Format
TXNID=TXN-123
GROSS=1220.00
NET=1100.00

# KEY VALUE Format
TXNID TXN-123
GROSS 1220.00
NET 1100.00
```

#### Response Codes
- `202`: Transaction accepted for processing
- `400`: Invalid request format/data
- `401`: Authentication failure
- `403`: Rate limit exceeded
- `409`: Duplicate transaction
- `422`: Validation error
- `503`: Service unavailable (Circuit breaker open)

#### Success Response
```json
{
    "status": "success",
    "message": "Transaction queued for processing",
    "data": {
        "transaction_id": "TXN-123",
        "status": "PENDING",
        "validation_status": "PENDING",
        "job_status": "QUEUED"
    }
}
```

### 2. Transaction Status
`GET /api/v1/transactions/{id}/status`

#### Response
```json
{
    "status": "success",
    "data": {
        "transaction_id": "TXN-123",
        "validation_status": "VALID|ERROR|PENDING",
        "job_status": "COMPLETED|FAILED|QUEUED|PROCESSING",
        "job_attempts": 1,
        "completed_at": "2025-05-21T10:00:00Z",
        "errors": []
    }
}
```

## Error Handling

### Error Response Format
```json
{
    "status": "error",
    "message": "Error description",
    "errors": {
        "field": ["Error detail"]
    },
    "code": "ERROR_CODE"
}
```

### Common Error Codes
- `invalid_checksum`: Payload integrity check failed
- `invalid_amounts`: Amount validation failed
- `future_date`: Transaction date is in future
- `missing_fields`: Required fields missing
- `max_retries`: Maximum retry attempts reached

## Rate Limiting
- Default: 100 requests per minute per terminal
- Headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`
- Retry-After header provided when limit exceeded

## Circuit Breaker
- Opens after 5 consecutive failures
- Half-open state after 30-second cooldown
- First successful request in half-open closes breaker

## Best Practices
1. Always provide unique transaction IDs
2. Implement idempotency handling
3. Honor rate limits and circuit breaker states
4. Handle all possible response codes
5. Implement exponential backoff for retries

## Version Control
- Version: 1.0.0
- Last Updated: 2025-05-21
