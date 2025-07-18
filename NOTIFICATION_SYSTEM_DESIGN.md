# TSMS Notification System: Current vs. Suggested Implementation

---

## 1. Current Notification Implementation

### **Overview**

The current notification system is centralized in `NotificationService`, leveraging Laravel's notification features to alert admins about transaction failures, batch processing issues, and security events.

### **Key Features**

-   **Centralized Service:** All notification logic is in `NotificationService`.
-   **Channels:** Uses `mail` and `database` channels (configurable).
-   **Admin Recipients:** Sends to users with the `admin` role and to configured admin emails.
-   **Notification Types:**
    -   Transaction failure threshold exceeded
    -   Batch processing failure
    -   Security audit alerts
-   **Logging:** All notification actions and errors are logged.
-   **Testing Support:** In testing, sends to any user if no admin exists.
-   **Dashboard Support:** Methods to fetch recent notifications and stats, and mark as read.

### **Sample Flow**

1. Detects a threshold event (e.g., too many failed transactions).
2. Creates a notification (e.g., `TransactionFailureThresholdExceeded`).
3. Sends notification via `mail` and `database` to admins.
4. Logs the action and any errors.

---

## 2. Suggested Enhanced Implementation

### **Goals**

-   **Multi-Channel Support:** Add support for forwarding notifications to the WebApp (or other systems) via HTTP.
-   **Dynamic Channel Selection:** Use config to control which channels are used for each notification type.
-   **Extensibility:** Easily add new channels (e.g., Slack, SMS) in the future.
-   **Queueing & Retry:** Use Laravel's queue for reliable delivery, especially for HTTP/webhook notifications.

### **Key Enhancements**

#### **A. Add a Custom WebApp Notification Channel**

-   Create `WebAppNotificationChannel` to send notifications as HTTP POST to the WebApp.

#### **B. Update Notification Classes**

-   Add a `toWebApp()` method to each notification class to define the payload for the WebApp.

#### **C. Dynamic Channel Dispatch**

-   Use the `notification_channels` config to determine which channels to use per notification.
-   Example: `['mail', 'database', 'webapp']`

#### **D. Configuration**

-   Move notification config to `config/notifications.php` for clarity.
-   Add WebApp endpoint and token to `.env` and config.

#### **E. Use Queues for Delivery**

-   Dispatch notifications to the queue for async and retryable delivery.

---

### **Sample Implementation Outline**

#### 1. **Custom Channel**

```php
// app/Notifications/Channels/WebAppNotificationChannel.php
public function send($notifiable, Notification $notification)
{
    $url = config('notifications.webapp_url');
    $token = config('notifications.webapp_token');
    $payload = $notification->toWebApp($notifiable);

    Http::withToken($token)->post($url, $payload);
}
```

#### 2. **Notification Class**

```php
public function via($notifiable)
{
    return config('notifications.channels', ['mail', 'database', WebAppNotificationChannel::class]);
}

public function toWebApp($notifiable)
{
    return [
        'type' => 'transaction_failure',
        'data' => $this->data,
        'notified_at' => now()->toISOString(),
    ];
}
```

#### 3. **Config Example**

```php
// config/notifications.php
return [
    'channels' => ['mail', 'database', App\Notifications\Channels\WebAppNotificationChannel::class],
    'webapp_url' => env('WEBAPP_NOTIFICATION_URL'),
    'webapp_token' => env('WEBAPP_NOTIFICATION_TOKEN'),
    // ... other config
];
```

4.  **Queueing**

```php
Notification::send($adminUsers, (new TransactionFailureThresholdExceeded($data))->onQueue('notifications'));
```

---

## 3. Advanced/Enterprise-Ready Notification System Design (Recommended Best Practices)

### **1. Architecture & Extensibility**

-   **Event-Driven Triggers:**

    -   Raise domain events (e.g., `TransactionFailed`, `BatchProcessingErrored`) and use event listeners to handle notification dispatch.
    -   Decouples core services from notification logic and simplifies testing.

-   **Channel Interface Abstraction:**
    -   Define a `NotificationChannelInterface` (e.g., `send(Notification, Notifiable): void`).
    -   Implement drivers (Mail, Database, WebApp, Slack, SMS, etc.) and register them in the service container.
    -   Use driver names in `via()` to add new channels without code changes.

### **2. Reliability & Resilience**

-   **Retry & Back-off Policies:**

    -   Use exponential back-off for flaky endpoints by configuring per-queue retry delays or using a specialized queue driver.
    -   Prevent overwhelming downstream services.

-   **Circuit Breaker Pattern:**

    -   Wrap external HTTP channels in a circuit breaker (e.g., using a throttle or custom logic).
    -   Pause notification attempts after repeated failures, then automatically resume.

-   **Dead-Letter Queue & Monitoring:**
    -   Configure a dead-letter queue for permanently failed notification jobs.
    -   Surface failure counts in dashboards (Grafana, Sentry) for quick detection.

### **3. Configuration & Management**

-   **Per-Notification Config:**

    -   Map notification classes to channels in `config/notifications.php`:
        ```php
        'map' => [
          TransactionFailureThresholdExceeded::class => ['mail', 'database', 'webapp'],
          SecurityAuditAlert::class             => ['slack', 'sms'],
        ],
        ```
    -   Fall back to a default channel list if no mapping exists.

-   **User/Role Preferences:**

    -   Store user-specific channel preferences in the database.
    -   Merge global and user preferences in `via()` to respect opt-ins/opt-outs.

-   **Localization & Templating:**
    -   Move message bodies and templates to `resources/lang/{locale}/notifications.php` and Markdown views.
    -   Allow non-developers to edit copy without touching code.

### **4. Security & Compliance**

-   **Signed Payloads:**

    -   Sign HTTP payloads (HMAC) when pushing to external services for authenticity verification.
    -   Rotate or short-lived tokens via a secrets manager.

-   **Rate-Limiting & Throttling:**
    -   Throttle outgoing notifications per channel (e.g., max 5 mails/minute) using Laravel’s `RateLimiter`.

### **5. Observability & Testing**

-   **Structured Logging & Metrics:**

    -   Emit structured JSON logs for every send attempt, retry, and dead-letter.
    -   Push metrics (e.g., `notification_sent_total`) to monitoring systems.

-   **Comprehensive Test Coverage:**
    -   Unit tests for each channel (mock external clients).
    -   Integration tests for full cycles using Laravel’s `Notification::fake()`.

### **6. Documentation & Diagrams**

-   **Sequence Diagrams:**

    -   Visualize event firing, listener dispatch, channel invocation, and external calls.

-   **Configuration Reference:**
    -   Include a table of configuration keys and examples in `README.md` or `docs/notifications.md`.

---

## **Updated Summary**

-   **Current system** is robust for email and database notifications.
-   **Suggested system** adds HTTP/WebApp forwarding, dynamic channel config, and better extensibility.
-   **Advanced/Enterprise-Ready system** (recommended for production at scale) adds event-driven architecture, channel abstraction, reliability patterns (retry, circuit breaker, dead-letter), per-user/channel config, localization, security, observability, and comprehensive documentation/testing.
-   **Recommended:** Gradually evolve towards the advanced design for maximum flexibility, reliability, and maintainability.
