# 🔐 Terminal Tokens in TSMS

## 1. Purpose & Scope

Terminal tokens in TSMS are used to securely authenticate Point-of-Sale (POS) terminals when transmitting sales transactions to the TSMS system via API. Each terminal must register and receive a **JWT (JSON Web Token)** for secured communication.

---

## 2. Where They Are Used

-   **MVP-001: POS Integration**  
    Real-time POS to TSMS integration is built on token-based authentication, where each terminal is registered and authenticated using a JWT token.  
    _References: [MVP Scope List](#), [TSMS Full Project Roadmap](#)_

-   **MVP-003: Transaction Processing**  
    All transaction submissions require valid terminal authentication via JWT tokens.

-   **MVP-007: Retry History**  
    Terminal tokens are used to authenticate retry requests when transactions need to be reprocessed.

---

## 3. Implementation Details

-   **Token Issuance**:

    -   Upon terminal registration via `/register-terminal`, the system issues a JWT token.
    -   Token is tied to the `pos_terminals` table via the `terminal_uid` field.
    -   The guard `auth:pos_api` is used to validate incoming requests.
    -   Tokens have a default expiration of 30 days.

-   **Authentication Header Format**:

    ```http
    Authorization: Bearer <JWT_TOKEN>
    ```

-   **JWT Payload Structure**:

    ```json
    {
        "iss": "https://tsms.example.com",
        "aud": "pos_terminal",
        "iat": 1683720000,
        "exp": 1686398400,
        "sub": "TERM-001",
        "jti": "random-unique-id"
    }
    ```

-   **Token Regeneration**:
    -   Administrators can regenerate tokens via the admin dashboard.
    -   Previous tokens are automatically invalidated when new ones are generated.
    -   Terminal tokens can be filtered by status (Active/Expired/Revoked).

---

## 4. Security Considerations

-   Tokens are stored using secure hashing in the database.
-   All token transmissions occur over HTTPS.
-   Tokens are subject to rate limiting to prevent abuse.
-   Failed authentication attempts are logged and monitored.
-   Integration with Circuit Breaker prevents token abuse during system outages.

---

## 5. Terminal Token Management

The TSMS Admin Dashboard provides a Terminal Tokens module with the following capabilities:

-   View all registered terminals and their token status
-   Filter terminals by ID and token status
-   Regenerate tokens for specific terminals
-   Revoke tokens for compromised terminals
-   View token expiration dates
-   Monitor token usage patterns

---

## 6. Integration Example

```php
// Example of how to use a terminal token in a PHP client
$client = new \GuzzleHttp\Client();
$response = $client->post('https://tsms.example.com/api/v1/transactions', [
    'headers' => [
        'Authorization' => 'Bearer ' . $terminal_token,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ],
    'json' => [
        'transaction_id' => 'TX-123456',
        'amount' => 1000.00,
        'terminal_uid' => 'TERM-001',
        'timestamp' => '2025-05-17T10:30:00Z',
        // Other transaction details
    ]
]);
```

---

## 7. Troubleshooting

Common token-related issues and solutions:

-   **401 Unauthorized**: Token is missing, expired, or invalid. Regenerate the token.
-   **403 Forbidden**: Terminal exists but lacks permissions for the requested operation.
-   **429 Too Many Requests**: Rate limit exceeded. Implement exponential backoff.

---

## 8. Related Documentation

-   [Retry History System](./retry_history.md)
-   [Circuit Breaker Implementation](./circuit_breaker.md)
-   [Transaction Processing Flow](./transaction_processing.md)
