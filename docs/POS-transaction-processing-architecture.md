# TSMS Segment 1 – POS Transaction Processing Architecture

## 📦 Segment Overview

**Segment 1** represents an isolated network environment, likely a **tenant branch or site**, where a group of POS terminals operate and transmit data to the TSMS core system.

---

## 🖥️ Group of 20 POS Terminals

-   **Description**: This group consists of 20 POS terminals configured to push sales transactions.
-   **Function**: Each terminal:
    -   Is registered and authenticated.
    -   Sends real-time sales data via API.
    -   Operates independently but shares the same destination endpoint.

---

## 🔐 JWT Token Authentication

-   **Purpose**: Ensures that only registered POS terminals can push data.
-   **Mechanism**:
    -   Each terminal receives a **JWT token** upon registration via `/register-terminal`.
    -   Token is used in the `Authorization` header for every API call.
    -   Token is tenant- and terminal-specific, managed through `tymon/jwt-auth`.

---

## 🧠 TS1 – TSMS Middleware Server

-   **Description**: Core server that:
    -   Accepts incoming transaction payloads.
    -   Validates, transforms, and logs all incoming data.
    -   Uses Laravel Queue and Horizon for asynchronous processing.
    -   Stores data in the **TSMS database**.

---

## 🔄 Data Flow Summary

```text
POS Terminal (x20)
    ↓ (JWT Authenticated)
POST /api/v1/transaction
    ↓
TS1 Server (Middleware)
    ↓
MySQL Database (Transactions + Logs)
```
