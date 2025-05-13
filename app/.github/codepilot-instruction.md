## Tech Stack Requirements

### Framework & Core Technologies

-   **Backend**: Laravel 11 with PHP 8.2+
-   **Frontend**: React 18+ with JavaScript (not TypeScript)
-   **Database**: MySQL (structured schema aligned with business logic)
-   **Caching & Queuing**: Redis (used for Laravel Horizon, circuit breaker, and rate limiting)
-   **Local Development**: Laravel Sail (preferred for Docker-based environments)

---

## Backend Architecture Standards

-   **API Layer**: Laravel resource controllers following RESTful design
-   **Authentication**: Laravel Sanctum (token-based), migrating to `tymon/jwt-auth` for tenant-scoped access
-   **Service Layer**: Business logic must reside in service classes under `App\Services`
-   **Validation**: Use Laravel’s request validation and custom exception classes
-   **Multi-Tenancy**: Models and middleware must be tenant-aware
-   **Error Handling**: Centralized using custom exception classes

---

## Frontend Implementation Guidelines

-   **Components**: Functional components only (no class-based)
-   **Hooks**: Utilize React Hooks (e.g., `useState`, `useEffect`)
-   **State Management**: React Context API / Redux as needed
-   **Routing**: React Router (SPA-based navigation)
-   **HTTP Requests**: Axios
-   **UI Framework**: Tailwind CSS (preferred), Bootstrap allowed if scoped

---

## Forbidden Technologies

-   jQuery
-   AngularJS
-   Class-based React components
-   Unapproved UI libraries (e.g., Material UI)
-   Direct DB access in Blade/React
-   Laravel packages that bypass middleware or Horizon queue
-   `.jsx` files are **not allowed**.
    -   Use `.js` files for all React components.
    -   JSX syntax is still permitted in `.js` files as long as Babel is configured to transpile them.
    -   This keeps file extensions consistent across the project and simplifies tooling and linting.

---

## Developer Tooling

-   **PHP Package Management**: Composer
-   **JavaScript Package Management**: npm
-   **Linting & Formatters**:
    -   ESLint + Prettier (JS)
    -   PHP CS Fixer or Laravel Pint (PHP)
-   **Static Analysis**: PHPStan / Psalm
-   **Git Workflow**: Feature branches with semantic commits (e.g., `feat: add retry metrics`)

---

## Testing Framework

### Backend

-   **Unit Testing**: PHPUnit
-   **Feature/API Testing**: Laravel's feature testing
-   **Helpers**:
    -   `AuthTestHelpers` for authentication scenarios
    -   `CircuitBreakerTestHelpers` for retry/circuit behavior
-   **Database**: `RefreshDatabase` trait with dedicated test schema

### Frontend

-   **Testing Library**: Jest (React component tests)

### Coverage

-   **Goal**: 80%+ code coverage (backend and frontend)

---

## Quality & Coding Standards

-   **PHP Style**: PSR-12 compliant
-   **JS Style**: ESLint + Prettier formatting
-   **Docs**: Use PHPDoc for all classes and methods
-   **REST API**: Consistent response structure and status codes
-   **Migrations**: One task per file, with `up()` and `down()` methods
-   **Separation of Concerns**: No logic in controllers/models—use services

---

## Security Measures

-   Input validation on all API endpoints
-   Rate limiting with tenant-level isolation
-   Circuit breaker patterns on external-facing endpoints
-   CSRF protection for web routes
-   Role-Based Access Control (Spatie)
-   JWT token handling for terminal-authenticated APIs
-   Encryption (AES-256 at rest, TLS 1.2+ in transit)
-   Activity logging and audit trails

---

## Copilot Behavior and Safeguards

-   **Preserve Working Code**:

    -   Copilot must not suggest or modify code in previously working/stable modules unless the change is explicitly part of the current task.
    -   Any changes to existing code must be documented with:
        -   A clear comment (`// Modified for [feature/task ID]`)
        -   A corresponding commit message explaining the purpose of the modification

-   **Scoped Suggestions Only**:

    -   Copilot should be used **only** for writing new logic within the assigned task/module boundaries.
    -   Use manual code review to ensure no regressions or side-effects are introduced to unrelated files or functions.

-   **Testing Isolation**:

    -   When writing tests for new code, ensure Copilot does not auto-suggest or duplicate test patterns from unrelated modules.
    -   All test helpers, mocks, and fixtures must be reviewed to ensure task-specific isolation and avoid global mutations or false positives.

-   **IDE Practice**:
    -   Disable Copilot for legacy files during critical test or production patch work.
    -   Always manually review Copilot-generated diffs before staging or committing.

---

# 🔧 Error Handling Guidelines

_For Laravel 11 (Backend) + React 18+ (Frontend)_  
_Last Updated: 2025-05_

---

## 🔒 1. General Principles

-   **Fail safely**: Never expose sensitive information (e.g., stack traces, environment variables) in responses.
-   **Log smartly**: Log meaningful technical errors server-side; avoid flooding logs with user errors.
-   **Respond consistently**: Use structured JSON for all error responses in APIs.
-   **Distinguish errors**: Separate developer errors (e.g., null pointer) from user-facing/business logic errors (e.g., invalid input).
-   **Map client-friendly messages**: Provide error codes clients can handle and show localized UI messages.

---

## 📦 2. Laravel (Backend) Guidelines

### 2.1. Use Centralized Exception Handling

-   Customize `app/Exceptions/Handler.php` to manage API and web exceptions.
-   Detect API requests using `request()->expectsJson()` and return JSON accordingly.

```php
public function render($request, Throwable $exception)
{
    if ($request->expectsJson()) {
        return $this->handleApiException($request, $exception);
    }

    return parent::render($request, $exception);
}
```

### 2.2. Recommended HTTP Status Codes

| Status | Meaning               | Use Case                      |
| ------ | --------------------- | ----------------------------- |
| 400    | Bad Request           | Malformed payload, validation |
| 401    | Unauthorized          | Missing/invalid auth token    |
| 403    | Forbidden             | Access denied                 |
| 404    | Not Found             | Resource not found            |
| 422    | Unprocessable Entity  | Validation failed             |
| 429    | Too Many Requests     | Rate limit hit                |
| 500    | Internal Server Error | Uncaught exceptions           |
| 503    | Service Unavailable   | Circuit breaker open          |

### 2.3. Custom Exception Classes

Create meaningful exception classes to indicate domain/business failures.

```php
class PaymentFailedException extends \Exception
{
    public function __construct($message = "Payment could not be processed.", $code = 422)
    {
        parent::__construct($message, $code);
    }
}
```

Throw custom exceptions inside service layers, not controllers.

### 2.4. Use Laravel Validation & FormRequest

-   Validate in `FormRequest` classes.
-   Always return 422 with `errors` structure:

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "email": ["The email field is required."]
    }
}
```

### 2.5. Logging

-   Use `Log::error()` for uncaught or critical issues.
-   Tag logs with `tenant_id`, `user_id`, or request metadata when possible.
-   Use Laravel’s log channels (`daily`, `slack`, etc.) for different log levels.

---

## ⚛️ 3. React JS (Frontend) Guidelines

### 3.1. Axios Global Error Handling

Set up a global Axios interceptor to catch and process errors:

```js
axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const { response } = error;
        if (response) {
            switch (response.status) {
                case 401:
                    // Auto logout or redirect to login
                    break;
                case 403:
                    alert("You are not allowed to perform this action.");
                    break;
                case 422:
                    return Promise.reject({
                        validationErrors: response.data.errors,
                    });
                case 500:
                    console.error("Server error", response.data);
                    break;
            }
        } else {
            console.error("Network error", error);
        }
        return Promise.reject(error);
    }
);
```

### 3.2. Component-Level Handling

-   Display human-friendly messages (no raw error dumps).
-   Use toast/snackbar systems (e.g., `react-toastify`) to notify users.
-   Handle `validationErrors` in forms by mapping them to field-level error messages.

```js
if (error.validationErrors) {
    setErrors(error.validationErrors);
}
```

---

## 🔐 4. Security Practices

-   Never include stack traces, file paths, or exception messages in API responses.
-   Mask sensitive fields (e.g., passwords) from validation and logs.
-   Use `app()->environment()` check to show detailed errors **only in local/dev**.

---

## 🧪 5. Testing & Observability

### 5.1. Backend Tests

-   Assert correct status codes and JSON structures.
-   Test exception-triggering flows using Laravel’s `expectException`.

### 5.2. Frontend Tests

-   Mock API error responses in tests (e.g., using MSW or Jest mocks).
-   Test UI for error display and behavior on form validation failures.

---

## 🧩 6. Error Response Structure (API)

Standardize this format for all API errors:

```json
{
    "message": "Error message",
    "code": "ERROR_CODE",
    "errors": {
        "field": ["Explanation of validation failure"]
    },
    "retry_after": "optional timestamp",
    "trace_id": "optional for logs/debugging"
}
```

Use `code` for consistent frontend handling (e.g., `INVALID_TOKEN`, `RATE_LIMIT_EXCEEDED`, `VALIDATION_FAILED`, `CIRCUIT_OPEN`).

---

## ✅ 7. Recommendations

-   Use **Sentry** or **Bugsnag** for monitoring errors across backend and frontend.
-   Use **Monolog processors** to enrich logs with request/tenant info.
-   Create a shared error code catalog in `/docs` and sync across teams.

---
