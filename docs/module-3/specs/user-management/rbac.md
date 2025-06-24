# Role-Based Access Control

## Default Roles

-   SuperAdmin: Full system access
-   Admin: System configuration access
-   Manager: Report access, user management
-   User: Basic system access

## Permission Matrix

| Permission    | SuperAdmin | Admin | Manager | User |
| ------------- | ---------- | ----- | ------- | ---- |
| manage_users  | ✓          | ✓     | ✓       | ✗    |
| view_reports  | ✓          | ✓     | ✓       | ✓    |
| manage_system | ✓          | ✓     | ✗       | ✗    |
| export_data   | ✓          | ✓     | ✓       | ✗    |
