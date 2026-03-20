# Technical Fix Record: TenantScope Infinite Recursion

> [!NOTE]
> **Fix Identifier**: FIX-20260320-001
> **Related Issue**: Staging 500 Internal Server Error (Dashboard/Admin Login)
> **Severity**: Critical

## 1. Problem Description
Admin users and dashboard API endpoints were returning 500 Internal Server Errors on the remote staging environment. The browser console showed failed requests to `api/dashboard/*`. 

## 2. Root Cause Analysis (RCA)
- **Primary Cause**: Infinite recursion in `TenantScope.php`.
- **Trigger**: `Auth::check()` or `Auth::user()` was called inside the `apply()` method of `TenantScope`. Because the `User` and `PosTerminal` models themselves use the `BelongsToTenant` trait, the call to resolve the authenticated user re-triggered the `TenantScope`, leading to an endless loop until the stack overflowed.

## 3. Technical Solution
Implemented a **Recursion Guard** using a static flag within the `TenantScope` class.
- **Guard Mechanism**: Added a `$resolvingUser` static boolean. The `apply` method checks if this flag is true; if so, it returns immediately. Otherwise, it sets the flag, executes the tenant resolution logic in a `try...finally` block, and resets the flag.
- **Impact Area**: Core scoping for `User`, `PosTerminal`, and `Transaction` models.

## 4. Regression Prevention
- **Automated Tests**: Future unit tests should simulate authentication resolution within a scoped model context.
- **Architectural Guardrails**: Updated `gemini.md` and `coding-standards.md` to mandate the use of recursion guards in global scopes that interact with the `Auth` facade.

## 5. Visual Proof
```bash
# Before Fix:
# curl -I https://staging.tsms.pitx.ph/api/dashboard/metrics
# HTTP/1.1 500 Internal Server Error

# After Fix:
# curl -I https://staging.tsms.pitx.ph/api/dashboard/metrics
# HTTP/1.1 200 OK
```
