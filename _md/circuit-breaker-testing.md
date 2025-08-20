# Circuit Breaker Testing Guidelines

## Overview

This document outlines best practices and solutions for testing the circuit breaker functionality in the TSMS application. It serves as a reference for developers working on reliability, fault tolerance, and circuit breaker-related features.

## Common Circuit Breaker Testing Challenges

### 1. Test Environment vs. Production Environment

When testing circuit breakers in Laravel applications, the testing environment often needs different configurations than production:

| Aspect            | Production                    | Testing                        |
| ----------------- | ----------------------------- | ------------------------------ |
| Database          | MySQL/PostgreSQL (persistent) | In-memory/transactional        |
| Redis             | Persistent Redis instance     | Isolated test database (DB 15) |
| State Persistence | Long-lived data               | Ephemeral test data            |
| Time-sensitivity  | Real time cooldown periods    | Simulated time with Carbon     |

### 2. Redis Dependency

The circuit breaker implementation relies on Redis for state tracking and metrics:

-   **Redis availability** must be ensured in the test environment
-   **Test isolation** requires using a separate Redis database
-   **Key cleanup** must occur between tests to prevent interference

### 3. Database Schema Changes

Tests can break when database schema changes don't align with model properties:

-   Column renames (e.g., `state` vs `status`)
-   Field type changes
-   Added/removed columns

## Our Solutions

### CircuitBreakerTestHelpers Trait

We implemented a custom `CircuitBreakerTestHelpers` trait that:

1. Sets up a clean testing environment for circuit breaker tests
2. Configures Redis to use a separate test database
3. Creates necessary test tenants
4. Cleans up Redis after tests complete

```php
// Example: CircuitBreakerTestHelpers.php
protected function setUpCircuitBreakerTest()
{
    // Set up testing environment
    config(['app.env' => 'testing']);

    // Configure Redis to use a separate testing database
    config(['redis.connections.default.database' => '15']); // Use DB 15 for testing

    // Configure circuit breaker settings
    config(['circuit_breaker.threshold' => 3]);
    config(['circuit_breaker.cooldown' => 60]);

    try {
        // Clean Redis test database
        Redis::connection()->flushdb();

        // Run migrations
        Artisan::call('migrate:fresh', ['--force' => true]);

        // Create test tenants
        $this->createTestTenants();
    } catch (\Exception $e) {
        Log::error('Circuit breaker test setup failed: ' . $e->getMessage());
        throw $e;
    }
}
```

### Model-Database Compatibility

We use accessor and mutator methods in the `CircuitBreaker` model to handle schema changes without breaking tests:

```php
// Accessor for backward compatibility
public function getStateAttribute()
{
    return $this->status;
}

// Mutator for backward compatibility
public function setStateAttribute($value)
{
    $this->attributes['status'] = $value;
}
```

## Best Practices

### Unit Tests

-   Test each state transition independently
-   Verify failure counting logic
-   Test multi-tenant isolation

### Integration Tests

-   Test actual Redis integration
-   Test with HTTP requests through middleware
-   Verify proper error responses when circuit is open

### Time-Sensitive Tests

-   Use Carbon to mock time for cooldown periods
-   Test edge cases around cooldown boundaries
-   Ensure state transitions happen at correct times

## Important Considerations

1. **Test Isolation**: Always use a separate Redis database for testing to avoid interfering with development or production data.

2. **Database Constraints**: Be aware of foreign key constraints when creating test data.

3. **Model vs. Database**: Keep accessor/mutator methods updated when schema changes occur.

## Future Improvements

1. **Mock Redis**: Create a mock Redis service for tests that don't need real Redis
2. **Test Data Factories**: Develop more robust circuit breaker factories
3. **Visual Dashboard Tests**: Add tests for the circuit breaker dashboard

## References

-   [Circuit Breaker Documentation](docs/circuit-breaker.md)
-   [Laravel Redis Documentation](https://laravel.com/docs/11.x/redis)
-   [Testing with Time in Laravel](https://laravel.com/docs/11.x/mocking#interacting-with-time)
