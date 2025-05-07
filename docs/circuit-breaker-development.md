# Circuit Breaker Development & Testing Guide

## Development Setup

### Prerequisites

```bash
# Required services
- PHP 8.4.6 or higher
- Redis Server
- MySQL/MariaDB

# Required PHP extensions
- redis
- pdo_mysql
```

### Local Environment Setup

1. Install dependencies:

```bash
composer require predis/predis
```

2. Add required environment variables to `.env`:

```env
CIRCUIT_BREAKER_THRESHOLD=3
CIRCUIT_BREAKER_COOLDOWN=60

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

3. Run migrations:

```bash
php artisan migrate
```

## Testing Procedures

### 1. Unit Tests

Location: `tests/Unit/CircuitBreakerTest.php`

Run unit tests:

```bash
php artisan test --filter=CircuitBreakerTest
```

Key test scenarios:

-   State transitions
-   Failure counting
-   Multi-tenant isolation
-   Redis metrics recording

### 2. Integration Tests

Location: `tests/Feature/CircuitBreakerIntegrationTest.php`

Run integration tests:

```bash
php artisan test --filter=CircuitBreakerIntegrationTest
```

Key test scenarios:

-   End-to-end request handling
-   Redis integration
-   Database operations
-   Middleware functionality

### 3. Manual Testing Script

The `circuit:test` command provides comprehensive testing:

```bash
# Basic state transition test
php artisan circuit:test --failures=5 --service=test_service

# Test with specific tenant
php artisan circuit:test --failures=3 --service=test_service --tenant=123

# Test with delay
php artisan circuit:test --failures=5 --service=test_service --delay=1000
```

### 4. Load Testing

Using Apache Benchmark for basic load testing:

```bash
# Test with circuit breaker closed
ab -n 1000 -c 10 http://localhost:8000/api/v1/test-circuit-breaker

# Test with forced failures
ab -n 1000 -c 10 "http://localhost:8000/api/v1/test-circuit-breaker?fail=true"
```

## Debugging

### 1. Redis Monitoring

Monitor Redis operations in real-time:

```bash
# Watch Redis operations
redis-cli monitor | grep circuit_breaker

# Check current failure counts
redis-cli keys "circuit_breaker:*:failure_count"
```

### 2. Circuit State Inspection

Query current circuit breaker states:

```sql
-- Check all circuit breakers
SELECT name, status, trip_count, cooldown_until
FROM circuit_breakers
ORDER BY updated_at DESC;

-- Check specific service
SELECT * FROM circuit_breakers
WHERE name = 'service_name'
  AND tenant_id = 1;
```

### 3. Log Analysis

Monitor circuit breaker events:

```bash
# Watch circuit breaker logs in real-time
tail -f storage/logs/laravel.log | grep "Circuit breaker"
```

## Common Development Tasks

### 1. Adding New Failure Detection Rules

Location: `app/Http/Middleware/CircuitBreakerMiddleware.php`

Example of custom failure detection:

```php
// Add to handle() method in try block
if ($response instanceof JsonResponse) {
    $body = $response->getData(true);
    if (isset($body['error_code']) && in_array($body['error_code'], [
        'SERVICE_OVERLOADED',
        'MAINTENANCE_MODE'
    ])) {
        throw new ServiceException($body['message']);
    }
}
```

### 2. Customizing State Transitions

Example of adding custom state transition logic:

```php
// In CircuitBreakerMiddleware
private function shouldTransitionToOpen(int $failureCount): bool
{
    // Add time-based rules
    if (now()->isWeekend() && $failureCount >= 5) {
        return true;
    }
    return $failureCount >= $this->threshold;
}
```

### 3. Adding Metrics Collection

Example of extending metrics collection:

```php
private function recordMetrics(string $redisKey, Response $response): void
{
    Redis::pipeline(function ($pipe) use ($redisKey, $response) {
        $pipe->incr("{$redisKey}:total_requests");
        $pipe->incrBy("{$redisKey}:total_latency", $response->headers->get('X-Runtime'));
        $pipe->expire("{$redisKey}:total_requests", 86400);
        $pipe->expire("{$redisKey}:total_latency", 86400);
    });
}
```

## Best Practices for Development

1. **State Management**

    - Always use CircuitBreaker model constants for states
    - Implement atomic state transitions
    - Include proper error handling

2. **Redis Keys**

    - Use consistent key naming patterns
    - Implement key expiration
    - Handle Redis connection failures

3. **Testing**

    - Write tests for new failure scenarios
    - Include multi-tenant test cases
    - Test edge cases in state transitions

4. **Monitoring**
    - Log all state transitions
    - Track failure patterns
    - Monitor Redis memory usage

## Troubleshooting Development Issues

1. **Redis Connection Issues**

```bash
# Verify Redis connection
redis-cli ping

# Clear all circuit breaker keys
redis-cli keys "circuit_breaker:*" | xargs redis-cli del
```

2. **Database Issues**

```bash
# Reset circuit breaker table
php artisan migrate:refresh --path=database/migrations/*_create_circuit_breakers_table.php
```

3. **State Transition Issues**

```bash
# Reset all circuits to CLOSED
php artisan circuit:reset --all
```
