## Database Schema

-   users
    -   id: bigint (primary key)
    -   name: varchar(255)
    -   email: varchar(255) unique
    -   password: varchar(255)
    -   role_id: bigint (foreign key)
    -   created_at: timestamp
    -   updated_at: timestamp

## Role Structure

-   roles
    -   id: bigint (primary key)
    -   name: varchar(255)
    -   permissions: json

## Authentication Flow

1. Login Route: POST /api/v1/auth/login
2. Register Route: POST /api/v1/auth/register
3. Password Reset: POST /api/v1/auth/reset-password
