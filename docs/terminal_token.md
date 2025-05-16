# 🔐 Terminal Tokens in TSMS

## 1. Purpose & Scope

Terminal tokens in TSMS are used to securely authenticate Point-of-Sale (POS) terminals when transmitting sales transactions to the TSMS system via API. Each terminal must register and receive a **JWT (JSON Web Token)** for secured communication.

---

## 2. Where They Are Used

-   **MVP-001: POS Integration**  
    Real-time POS to TSMS integration is built on token-based authentication, where each terminal is registered and authenticated using a JWT token.  
    _References: [MVP Scope List](#), [TSMS Full Project Roadmap](#)_

---

## 3. Implementation Details

-   **Token Issuance**:

    -   Upon terminal registration via `/register-terminal`, the system issues a JWT token.
    -   Token is tied to the `pos_terminals` table.
    -   The guard `auth:pos_api` is used to validate incoming requests.

-   **Authentication Header Format**:
    ```http
    Authorization: Bearer <JWT_TOKEN>
    ```
