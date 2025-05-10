# Security Testing Framework

This document provides a brief overview of how to test the security monitoring components
in the TSMS application.

## Overview

The Security Monitoring module provides functionality to:

1. Record security events
2. Monitor for suspicious activity
3. Trigger alerts based on thresholds
4. Respond to security incidents

## Testing Components

### NoAuthTestHelpers Trait

When testing security components, use the `NoAuthTestHelpers` trait to set up a clean
testing environment without dealing with cookie/session authentication complexities:

```php
use Tests\Traits\NoAuthTestHelpers;

class YourSecurityTest extends TestCase
{
    use NoAuthTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTestDatabase();

        // Your test setup code
    }

    // Test methods
}
```

### SecurityMonitorServiceTest Example

The `SecurityMonitorServiceTest` provides a good example of how to test security components:

1. It creates a test tenant
2. It records security events
3. It verifies alert rules
4. It tests time-based event windowing

See `tests/Unit/Security/SecurityMonitorServiceTest.php` for a complete example.

## Best Practices

1. Always test with fresh database migrations
2. Use MySQL for testing security components (not SQLite)
3. Reset the cache between tests
4. Set explicit time for testing time-based features
5. Test both success and failure scenarios

## Debugging Tips

If you encounter issues with the security tests:

1. Check database connection configuration
2. Verify migrations are running properly
3. Ensure tenant creation includes required fields
4. Reset cache between tests to avoid interference
5. Use Carbon::setTestNow() for time-based tests

For more comprehensive guidance, see [Authentication Testing Guidelines](authentication-testing.md).
