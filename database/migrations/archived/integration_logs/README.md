# Archived Integration Logs Migrations

These migrations have been consolidated into the main integration_logs table migration:
`2024_01_01_000001_create_integration_logs_table.php`

## Archived Files
1. 2025_04_22_033917_add_autid_to_integration_logs_table.php
   - Added audit fields (ip_address, token timestamps, latency)

2. 2025_05_17_000001_update_status_column_in_integration_logs_table.php
   - Modified status enum to include PENDING

3. 2025_05_17_000000_add_log_fields_to_integration_logs_table.php
   - Added logging-specific fields

4. 2025_04_23_120734_update_status_enum_in_integration_logs_table.php
   - Updated status enum values

5. 2025_04_18_085406_add_max_retries_to_integration_logs_table.php
   - Added max_retries field

These migrations were consolidated to:
1. Improve database versioning
2. Maintain data integrity
3. Simplify migration history
4. Document schema evolution
