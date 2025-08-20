# Authentication Logging Implementation

## Overview

This document outlines the comprehensive authentication logging system implemented for the TSMS application, providing dual logging capabilities for both audit trail and system monitoring purposes.

## Implementation Summary

### 1. Dual Logging Architecture

The system now logs authentication events to both:

-   **Audit Logs** (`audit_logs` table) - For compliance and security audit trails
-   **System Logs** (`system_logs` table) - For operational monitoring and alerting

### 2. Authentication Events Tracked

#### Login Events

-   **Successful Login**: Logged with user details, IP address, and timestamp
-   **Failed Login**: Logged with attempted email and IP address
-   **Logout**: Logged with user details and session information

#### Data Logged

-   User ID and email
-   IP address and User Agent
-   Timestamp
-   Action type (LOGIN, LOGOUT, LOGIN_FAILED)
-   Contextual information (session data, etc.)

### 3. Modified Files

#### Backend Changes

1. **`app/Listeners/LogAuthenticationEvent.php`**

    - Enhanced to log to both audit_logs and system_logs
    - Added detailed context information
    - Proper event type handling

2. **`app/Http/Controllers/Auth/LoginController.php`**

    - Added logout event logging
    - Enhanced error logging

3. **`app/Http/Controllers/LogController.php`**

    - Added authentication statistics
    - Enhanced filtering capabilities

4. **`app/Helpers/LogHelper.php`**
    - Added authentication event styling methods
    - Added icon mapping for auth events

#### Frontend Changes

1. **`resources/views/dashboard/logs.blade.php`**

    - Added authentication statistics cards
    - Enhanced visual representation

2. **`resources/views/logs/partials/system-table.blade.php`**
    - Added User column
    - Enhanced auth event display with icons
    - Added IP address display for auth events

## Features

### 1. Comprehensive Dashboard

-   **Total Events**: Overall audit trail count
-   **Auth Events**: Authentication-specific events
-   **Successful Logins**: Count of successful login attempts
-   **Failed Logins**: Count of failed login attempts
-   **System Logs**: Total system log entries
-   **Webhook Events**: API interaction logs

### 2. Enhanced Filtering

-   Filter by log type (including AUTH)
-   Filter by severity level
-   Date range filtering
-   User-specific filtering
-   Terminal-specific filtering (where applicable)

### 3. Visual Enhancements

-   **Icons**: Login, logout, and failed login events have distinct icons
-   **Color Coding**: Different colors for different event types
-   **IP Address Display**: Shows IP addresses for authentication events
-   **User Information**: Shows which user performed the action

### 4. Security Features

-   **Failed Login Tracking**: Monitors and alerts on failed login attempts
-   **IP Address Logging**: Tracks source IP for all authentication events
-   **User Agent Tracking**: Records browser/client information
-   **Session Tracking**: Logs session regeneration events

## Database Schema

### System Logs Table

```sql
- id (primary key)
- type (LOGIN, LOGOUT, LOGIN_FAILED)
- log_type (AUTH)
- severity (info, warning, error)
- user_id (foreign key)
- message (descriptive text)
- context (JSON - IP, user agent, etc.)
- created_at
- updated_at
```

### Audit Logs Table

```sql
- id (primary key)
- user_id (foreign key)
- action (auth.login, auth.logout, auth.failed)
- action_type (AUTH)
- ip_address
- user_agent
- message
- metadata (JSON)
- created_at
- updated_at
```

## Usage Examples

### 1. Viewing Authentication Logs

Navigate to `/dashboard/logs` and:

-   Click on "System Logs" tab to see operational auth logs
-   Click on "Audit Trail" tab to see compliance audit logs
-   Use filters to narrow down to specific events or date ranges

### 2. Monitoring Failed Logins

-   Check the "Failed Logins" statistic card
-   Filter System Logs by type "LOGIN_FAILED"
-   Review IP addresses for suspicious activity

### 3. User Activity Tracking

-   Filter by specific user ID
-   View login/logout patterns
-   Track session duration and activity

## Security Considerations

### 1. Data Protection

-   IP addresses are logged for security monitoring
-   No sensitive data (passwords) are logged
-   User agent strings help identify unusual client behavior

### 2. Compliance

-   Audit logs provide immutable trail for compliance requirements
-   Timestamps in UTC for consistent reporting
-   Retention policies should be configured as per organizational requirements

### 3. Performance

-   Indexes on frequently queried columns (user_id, created_at, log_type)
-   Pagination implemented to handle large datasets
-   Background processing for log analytics

## Recommendations

### 1. Monitoring Setup

-   Set up alerts for excessive failed login attempts
-   Monitor unusual IP address patterns
-   Track login attempts outside business hours

### 2. Maintenance

-   Regular cleanup of old logs (implement retention policy)
-   Archive logs for long-term compliance storage
-   Monitor log table sizes and performance

### 3. Enhancement Opportunities

-   Add geolocation tracking for IP addresses
-   Implement rate limiting based on failed attempts
-   Add email notifications for security events
-   Create automated reports for security team

## Testing

### 1. Authentication Events

-   Test successful login logging
-   Test failed login logging
-   Test logout logging
-   Verify IP address capture
-   Check user agent logging

### 2. Dashboard Functionality

-   Verify statistics accuracy
-   Test filtering capabilities
-   Check pagination performance
-   Validate date range filtering

### 3. Performance Testing

-   Load test with multiple concurrent logins
-   Verify log table performance with large datasets
-   Test search and filtering response times

## Conclusion

The enhanced authentication logging system provides comprehensive tracking of all authentication events while maintaining performance and security. The dual logging approach ensures both operational monitoring and compliance requirements are met, with rich visual presentation for easy analysis and monitoring.
