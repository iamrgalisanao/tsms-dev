# TSMS Database Structure Reference

## Entity Relationship Diagram

```mermaid
erDiagram
    TENANTS {
        bigint id PK
        string name
        string code
        string status
        timestamp created_at
        timestamp updated_at
    }

    POS_TERMINALS {
        bigint id PK
        bigint tenant_id FK
        string terminal_uid
        string status
        boolean is_sandbox
        string webhook_url
        int max_retries
        int retry_interval_sec
        boolean retry_enabled
        string jwt_token
        timestamp registered_at
        timestamp created_at
        timestamp updated_at
    }

    TRANSACTIONS {
        bigint id PK
        bigint tenant_id FK
        bigint terminal_id FK
        uuid transaction_id
        string reference_no
        decimal amount
        string status
        string request_payload
        string response_payload
        timestamp processed_at
        timestamp created_at
        timestamp updated_at
    }

    INTEGRATION_LOGS {
        bigint id PK
        bigint tenant_id FK
        bigint terminal_id FK
        string event_type
        string payload
        string status
        string notes
        timestamp created_at
        timestamp updated_at
    }

    WEBHOOK_LOGS {
        bigint id PK
        bigint terminal_id FK
        uuid transaction_id
        string event_type
        string payload
        string status
        int attempts
        string error
        timestamp last_attempt
        timestamp created_at
        timestamp updated_at
    }

    USERS {
        bigint id PK
        string name
        string email
        string password
        string role
        boolean is_active
        timestamp email_verified_at
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }

    ROLES {
        bigint id PK
        string name
        string guard_name
    }

    PERMISSIONS {
        bigint id PK
        string name
        string guard_name
    }

    MODEL_HAS_ROLES {
        bigint role_id FK
        string model_type
        bigint model_id
    }

    MODEL_HAS_PERMISSIONS {
        bigint permission_id FK
        string model_type
        bigint model_id
    }

    ROLE_HAS_PERMISSIONS {
        bigint permission_id FK
        bigint role_id FK
    }

    CIRCUIT_BREAKERS {
        bigint id PK
        bigint tenant_id FK
        string service
        string status
        int failures
        int threshold
        timestamp last_failure
        timestamp created_at
        timestamp updated_at
    }

    TENANTS ||--o{ POS_TERMINALS : has_many
    TENANTS ||--o{ TRANSACTIONS : has_many
    TENANTS ||--o{ INTEGRATION_LOGS : has_many
    TENANTS ||--o{ CIRCUIT_BREAKERS : has_many

    POS_TERMINALS ||--o{ TRANSACTIONS : processes
    POS_TERMINALS ||--o{ WEBHOOK_LOGS : has_many
    POS_TERMINALS ||--o{ INTEGRATION_LOGS : has_many

    USERS ||--o{ MODEL_HAS_ROLES : has
    USERS ||--o{ MODEL_HAS_PERMISSIONS : has
    ROLES ||--o{ ROLE_HAS_PERMISSIONS : has
    PERMISSIONS ||--o{ ROLE_HAS_PERMISSIONS : belongs_to
```


## Table Descriptions

### Core Tables
- **TENANTS**: Main table for multi-tenant architecture
- **POS_TERMINALS**: Point of Sale terminals configuration and management
- **TRANSACTIONS**: Transaction records and processing data

### Logging Tables
- **INTEGRATION_LOGS**: System integration event logging
- **WEBHOOK_LOGS**: Webhook delivery attempts and status tracking

### Authentication & Authorization
- **USERS**: User management and authentication
- **ROLES**: Role definitions for RBAC
- **PERMISSIONS**: Permission definitions
- **MODEL_HAS_ROLES**: Polymorphic role assignments
- **MODEL_HAS_PERMISSIONS**: Direct permission assignments
- **ROLE_HAS_PERMISSIONS**: Role-permission mappings

### System Tables
- **CIRCUIT_BREAKERS**: Service health monitoring and circuit breaker pattern implementation