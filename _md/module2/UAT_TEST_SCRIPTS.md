# User Acceptance Testing Scripts - Module 2

## 1. Transaction Submission Tests

### TC001: Submit Valid JSON Transaction

#### Prerequisites

-   Valid JWT token
-   Active terminal status
-   Valid terminal ID

#### Steps

1. Submit POST request to `/api/v1/transactions`
2. Use valid JSON payload with all required fields
3. Check response status and format
4. Verify transaction status via GET endpoint
5. Confirm database record creation

### TC002: Submit Valid Text Format Transaction

#### Steps

1. Submit transaction using KEY:VALUE format
2. Submit transaction using KEY=VALUE format
3. Submit transaction using KEY VALUE format
4. Verify format detection and parsing
5. Confirm data consistency

## 2. Error Handling Tests

### TC003: Validation Failures

1. Submit transaction with:
    - Missing required fields
    - Invalid amounts
    - Future dates
    - Invalid checksums
2. Verify appropriate error responses
3. Check error logging
4. Confirm status updates

### TC004: Circuit Breaker Tests

1. Generate consecutive failures
2. Verify circuit breaker activation
3. Test half-open state transition
4. Confirm success restores circuit

## 3. Performance Tests

### TC005: Load Testing

1. Submit 100 concurrent transactions
2. Monitor response times
3. Check queue processing
4. Verify database performance

### TC006: Rate Limiting

1. Exceed rate limits (>100/minute)
2. Verify 429 responses
3. Test rate limit headers
4. Confirm limit reset timing

## Version Control

-   Version: 1.0.0
-   Last Updated: 2025-05-21
