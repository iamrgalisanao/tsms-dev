# Circuit Breaker Documentation

## Overview

The circuit breaker pattern prevents cascading failures by temporarily stopping requests to failing services. It operates in three states:

-   CLOSED: Normal operation, requests flow through
-   OPEN: Service is failing, requests are blocked
-   HALF_OPEN: Testing if service has recovered

## Configuration

### Environment Variables

```env
CIRCUIT_BREAKER_THRESHOLD=3     # Number of failures before opening circuit
CIRCUIT_BREAKER_COOLDOWN=60     # Seconds to wait before attempting recovery
```

### Redis Requirements

The circuit breaker uses Redis for failure counting and metrics. Ensure Redis is configured in your `.env`:

```env
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_CLIENT=predis
```

## Usage

### 1. Protecting Routes

Apply the middleware to routes you want to protect:

```php
// In routes/api.php
Route::middleware(['circuit-breaker:service_name'])->group(function () {
    Route::get('/protected-endpoint', 'YourController@method');
});
```

### 2. Multi-tenant Support

Add the X-Tenant-ID header to your requests:

```php
$response = Http::withHeaders([
    'X-Tenant-ID' => $tenantId
])->get('/api/protected-endpoint');
```

### 3. Response Codes

-   200: Successful request
-   503: Circuit is OPEN (service unavailable)
    -   Includes 'retry_after' timestamp in response

### 4. Monitoring

The circuit breaker logs important events:

-   Circuit opening/closing
-   Failure counts
-   State transitions
-   Redis errors

## Error Handling

### Common HTTP Responses

```json
// Circuit Open Response
{
    "error": "Circuit breaker is open",
    "service": "service_name",
    "tenant_id": "1",
    "retry_after": "2025-05-06T10:30:00Z"
}
```

### Failure Detection

-   Any 5xx response is considered a failure
-   Connection timeouts count as failures
-   Application exceptions trigger failure counting

## Best Practices

1. **Service Granularity**

    - Use separate circuit breakers for different services
    - Consider endpoint-specific breakers for critical paths

2. **Threshold Selection**

    - Start with higher thresholds in production
    - Adjust based on service reliability
    - Consider service importance when setting thresholds

3. **Cooldown Periods**

    - Longer cooldowns for unstable services
    - Short cooldowns for quick-recovery services
    - Use exponential backoff for persistent failures

4. **Monitoring**
    - Watch for frequent circuit trips
    - Monitor Redis metrics
    - Set up alerts for circuit state changes

## Implementation Details

### State Transitions

```
CLOSED → OPEN:      After threshold failures
OPEN → HALF_OPEN:   After cooldown period
HALF_OPEN → CLOSED: After successful request
HALF_OPEN → OPEN:   After failed request
```

### Redis Keys

```
circuit_breaker:{tenant_id}:{service}:failure_count
circuit_breaker:{tenant_id}:{service}:success_count
circuit_breaker:{tenant_id}:{service}:last_failure
```

## Maintenance

### Redis Cleanup

Implement regular cleanup of old Redis keys:

```bash
# Example cleanup script
php artisan circuit:cleanup --older-than=7days
```

### Database Maintenance

Regular cleanup of old circuit breaker records:

```sql
-- Example cleanup query
DELETE FROM circuit_breakers
WHERE updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
  AND status = 'CLOSED';
```

## Troubleshooting

1. **Circuit Won't Open**

    - Verify threshold configuration
    - Check Redis connection
    - Confirm failure counting

2. **Stuck in OPEN State**

    - Verify cooldown period
    - Check time synchronization
    - Clear Redis keys manually if needed

3. **Multi-tenant Issues**

    - Verify tenant ID in headers
    - Check Redis key isolation
    - Monitor tenant-specific metrics

4. **Testing Issues**
    - Column name mismatches between tests and database schema
    - Ensure tenant records exist before testing with their IDs
    - Check for model accessor/mutator compatibility
    - Verify database constraint handling in test environment

## Implementation Notes

### Model-Database Compatibility

The CircuitBreaker model uses accessors and mutators to maintain compatibility between different naming conventions:

-   `state` attribute maps to the `status` database column
-   `failure_count` attribute maps to the `failures` database column
-   `opened_at` attribute maps to the `last_failure_at` database column

This design allows backward compatibility with existing code while maintaining a cleaner database schema.
