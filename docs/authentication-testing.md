# Authentication Testing Guidelines

## Overview

This document outlines best practices and solutions for testing authentication-related functionality in the TSMS application. It serves as a reference for developers working on authentication components, security features, and related test suites.

## Common Authentication Testing Challenges

### 1. Test Environment vs. Production Environment

When testing authentication in Laravel applications, the testing environment often needs different configurations than production:

| Aspect     | Production                     | Testing                    |
| ---------- | ------------------------------ | -------------------------- |
| Database   | MySQL/PostgreSQL (persistent)  | In-memory/transactional    |
| Sessions   | File/Redis/Database            | Array driver               |
| Cookies    | Encrypted, HTTP-only           | May need to be simplified  |
| Middleware | All security middleware active | Often selectively disabled |

### 2. Cookie/Session Handling

Laravel's authentication system relies heavily on cookies and sessions, which can be challenging in test environments:

-   **Cookie encryption** requires a valid app key
-   **Session drivers** may behave differently in tests
-   **CSRF protection** can interfere with automated tests

## Our Solutions

### AuthTestHelpers Trait

We've implemented an improved `AuthTestHelpers` trait that:

1. Sets up a clean testing environment
2. Configures proper encryption keys
3. Uses appropriate drivers for testing
4. Mocks the cookie service to prevent "Target class [cookie] does not exist" errors
5. Provides helper methods for creating and authenticating test users

```php
// Example: AuthTestHelpers.php
protected function setUpAuthTestEnvironment()
{
    // Set up testing environment
    Config::set('app.env', 'testing');
    Config::set('app.debug', true);

    // Use a random key for testing
    Config::set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    // Set up database and cache for testing
    Config::set('database.default', 'mysql');
    Config::set('cache.default', 'array');

    // Mock the cookie service that's missing from providers
    $this->mockCookieService();

    // Make sure roles are reset
    $this->resetRoles();
}

protected function mockCookieService()
{
    // Create a mock cookie service to prevent errors
    $cookieMock = Mockery::mock('cookie');
    app()->instance('cookie', $cookieMock);
}
```

### Older Approach: NoAuthTestHelpers Trait

1. Sets up a clean testing environment
2. Configures proper encryption keys
3. Uses appropriate drivers for testing
4. Runs fresh migrations for each test

```php
// Example: NoAuthTestHelpers.php
protected function setUpTestDatabase()
{
    // Set up testing environment
    config(['app.env' => 'testing']);
    config(['app.debug' => true]);

    // Use a random key for testing
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    // Set up database and cache
    config(['database.default' => 'mysql']);
    config(['cache.default' => 'array']);

    try {
        // Run migrations
        Artisan::call('migrate:fresh', ['--force' => true]);
    } catch (\Exception $e) {
        Log::error('Migration failed: ' . $e->getMessage());
        throw $e;
    }
}
```

### Important Considerations

1. **Test Isolation**: The traits and helpers are for isolated unit/feature tests only and should not be used in production code.

2. **Authentication Bypass**: For certain tests, we intentionally bypass authentication to test specific components (like services) in isolation.

3. **Multi-Tenant Testing**: When testing multi-tenant features, ensure proper tenant context is established.

## Best Practices

### Unit Tests

-   Use dependency injection rather than facades where possible
-   Mock authentication services rather than using real ones
-   Focus on testing the unit's responsibility, not authentication

### Feature Tests

-   Use Laravel's built-in testing helpers (`actingAs()`, etc.)
-   Test the full authentication flow in integration tests
-   Consider using database transactions to speed up tests

### Security Tests

-   Include dedicated tests for security features
-   Test authentication failure scenarios
-   Verify CSRF protection
-   Check for proper authorization checks

## Future Improvements

1. ~~**Testing Strategy Refactoring**: Move toward more isolated tests with better mocking~~ (Implemented with AuthTestHelpers)
2. **Test Data Factories**: Create more robust user/role factories
3. **Authentication Integration Tests**: Add dedicated test suite for auth flows
4. **Complete Cookie Provider**: Add proper CookieServiceProvider to the application for better testing

## References

-   [Laravel Testing Documentation](https://laravel.com/docs/11.x/testing)
-   [TSMS Security Module Documentation](docs/security-module.md)
